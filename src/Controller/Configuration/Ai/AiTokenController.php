<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Controller\Configuration\Ai;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiTokenAdmin;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiTokenCreationNonce;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;
use Thelia\Domain\Ai\Tool\AiCreatedToolToken;
use Thelia\Domain\Ai\Tool\Exception\AiToolTokenException;
use Thelia\Model\Admin;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * Configuration > AI > MCP tokens: the keys an AI client uses to reach the tools of the shop, in the name of the
 * administrator who creates them. The token is shown once, in the answer to the creation, and never again: only its
 * fingerprint is stored. A token has scopes unless it is explicitly given every right of its administrator.
 */
#[Route('/admin/configuration/ai/tokens', name: 'admin.configuration.ai.tokens.')]
final class AiTokenController
{
    private const RESOURCE = AdminResources::AI_MCP;
    private const LIST_ROUTE = 'admin.configuration.ai.tokens.default';

    public function __construct(
        private readonly AiTokenAdmin $tokens,
        private readonly SecurityContext $securityContext,
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly AdminFlash $flash,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly TokenProvider $tokenProvider,
        private readonly AiTokenCreationNonce $creationNonce,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return $this->render($request, null, false);
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        $back = new RedirectResponse($this->urls->generate(self::LIST_ROUTE));

        if (!$this->tokenIsValid($request)) {
            $this->flash->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $back;
        }

        if (!$this->creationNonce->consume($request, (string) $request->request->get('creation_nonce', ''))) {
            $this->flash->add($request, 'danger', $this->translator->trans('This form was already sent, or it has expired. Check the list below, then open the form again to create another token.'));

            return $back;
        }

        $allRights = $request->request->getBoolean('all_rights');
        $resources = $this->strings($request, 'resources');
        $accesses = $this->strings($request, 'accesses');
        $modules = array_values(array_filter(array_map('trim', explode(',', (string) $request->request->get('modules', '')))));

        try {
            $created = $this->tokens->create($this->adminId(), (string) $request->request->get('label', ''), $allRights, $resources, $accesses, $modules);
        } catch (AiExceptionInterface $refusal) {
            $this->flash->add($request, 'danger', $this->creationRefusal($refusal));

            return $back;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::CREATE, \sprintf('MCP token %s created', $created->prefix), $created->id);

        // Not a redirect: the token lives in this answer only, so a reload cannot show it again.
        return $this->render($request, $created, $this->tokens->createsTrifecta($allRights, $resources, $accesses, $modules), $allRights);
    }

    #[Route('/{id}/revoke', name: 'revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(Request $request, int $id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $back = new RedirectResponse($this->urls->generate(self::LIST_ROUTE));

        if (!$this->tokenIsValid($request)) {
            $this->flash->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $back;
        }

        try {
            $this->tokens->revoke($id, $this->adminId());
        } catch (AiExceptionInterface) {
            // One answer for a token that does not exist, one that belongs to another administrator and one that
            // cannot be revoked: an id forged in the form learns nothing.
            $this->flash->add($request, 'danger', $this->translator->trans('This token does not exist or cannot be revoked.'));

            return $back;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, 'MCP token revoked', $id);
        $this->flash->add($request, 'success', $this->translator->trans('The token was revoked. It stops working at the next request.'));

        return $back;
    }

    private function render(Request $request, ?AiCreatedToolToken $created, bool $trifecta, bool $createdWithAllRights = false): Response
    {
        $superAdministrator = $this->tokens->isSuperAdministrator($this->adminId());
        $response = new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/ai/token/index.html.twig', [
            'rows' => $this->tokens->rows($this->adminId()),
            'created' => $created,
            'created_trifecta' => $trifecta,
            'super_administrator' => $superAdministrator,
            'created_super_administrator_all_rights' => $createdWithAllRights && $superAdministrator,
            'creation_nonce' => $this->creationNonce->issue($request),
            'mcp_enabled' => $this->tokens->mcpEnabled(),
            'host' => $request->getHost(),
            'host_allowed' => $this->tokens->hostIsAllowed($request->getHost()),
            'resource_choices' => AiTokenAdmin::resourceChoices(),
            'access_choices' => AiTokenAdmin::accessChoices(),
        ]));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function creationRefusal(AiExceptionInterface $refusal): string
    {
        if ($refusal instanceof AiToolTokenException && AiToolTokenException::ALL_RIGHTS_WITH_SCOPES === $refusal->getCode()) {
            return $this->translator->trans('Choose either all your rights or some scopes, not both. No token was created.');
        }

        return $refusal->getMessage();
    }

    /**
     * @return list<string>
     */
    private function strings(Request $request, string $key): array
    {
        $values = $request->request->all()[$key] ?? [];

        return \is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    private function adminId(): int
    {
        $admin = $this->securityContext->getAdminUser();

        return $admin instanceof Admin ? (int) $admin->getId() : 0;
    }

    private function tokenIsValid(Request $request): bool
    {
        try {
            $this->tokenProvider->checkToken((string) $request->request->get('_token', ''));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
