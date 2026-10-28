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

use BackOfficeDefaultTwigBundle\Form\Configuration\Ai\AiConnectionType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiConnectionAdmin;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiLabels;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Security\User\UserInterface;
use Thelia\Domain\Ai\AiJurisdiction;
use Thelia\Model\AiConnection;
use Thelia\Model\AiConnectionQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * Configuration > AI > Connections: how the shop reaches an AI provider. The rules (code, address, jurisdiction,
 * key sealed in the vault) are the ones of the admin API: the screen calls the same services.
 */
#[Route('/admin/configuration/ai', name: 'admin.configuration.ai.')]
final class AiConnectionController
{
    private const RESOURCE = AdminResources::AI;
    private const LIST_ROUTE = 'admin.configuration.ai.default';
    private const EDIT_ROUTE = 'admin.configuration.ai.connections.edit';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/ai/connection/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/ai/connection/edit.html.twig';
    private const FORM_NAME = 'thelia_ai_connection';

    public function __construct(
        private readonly AiConnectionAdmin $connections,
        private readonly AiLabels $labels,
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly AdminFlash $flash,
        private readonly AdminFormErrorRenderer $errors,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly TokenProvider $tokens,
        private readonly SecurityContext $securityContext,
        #[Target('ai_connection_test')]
        private readonly RateLimiterFactoryInterface $testLimiter,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $rows = [];
        foreach ($this->connections->all() as $connection) {
            $rows[] = $this->toRow($connection);
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'european_union_only' => $this->connections->isEuropeanUnionOnly(),
            'has_non_european_connection' => null !== AiConnectionQuery::create()->filterByJurisdiction(AiJurisdiction::OutsideEuropeanUnion->value)->filterByActive(true)->findOne(),
        ]));
    }

    #[Route('/connections/new', name: 'connections.new', methods: ['GET'])]
    public function new(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        return $this->render(null, $this->form(null, ['active' => true]));
    }

    #[Route('/connections', name: 'connections.create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        return $this->submit($request, null);
    }

    #[Route('/connections/{id}', name: 'connections.edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $connection = AiConnectionQuery::create()->findPk($id);

        if (null === $connection) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->render($connection, $this->form($connection, $this->dataOf($connection)));
    }

    #[Route('/connections/{id}', name: 'connections.save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function save(Request $request, int $id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $connection = AiConnectionQuery::create()->findPk($id);

        if (null === $connection) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->submit($request, $connection);
    }

    #[Route('/connections/delete', name: 'connections.delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $id = (int) $request->request->get('connection_id', 0);

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::DELETE)) {
            return $denied;
        }

        $connection = AiConnectionQuery::create()->findPk($id);
        $back = new RedirectResponse($this->urls->generate(self::LIST_ROUTE));

        if (null === $connection) {
            return $back;
        }

        if (!$this->tokenIsValid($request)) {
            $this->flash->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $back;
        }

        $title = (string) $connection->getTitle();

        try {
            $this->connections->delete($id);
        } catch (HttpExceptionInterface $refusal) {
            $this->flash->add($request, 'danger', Response::HTTP_CONFLICT === $refusal->getStatusCode()
                ? $this->translator->trans('This connection is still used by a range. Give that range another model first (Configuration > AI > Ranges), then delete the connection.')
                : $refusal->getMessage());

            return $back;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::DELETE, \sprintf('AI connection %s deleted', $title), $id);
        $this->flash->add($request, 'success', $this->translator->trans('The connection was deleted.'));

        return $back;
    }

    #[Route('/connections/{id}/test', name: 'connections.test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function test(Request $request, int $id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $connection = AiConnectionQuery::create()->findPk($id);

        if (null === $connection) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $back = new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['id' => $id]));

        if (!$this->tokenIsValid($request)) {
            $this->flash->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $back;
        }

        $adminUser = $this->securityContext->getAdminUser();

        if (!$this->testLimiter->create($adminUser instanceof UserInterface ? $adminUser->getUsername() : null)->consume()->isAccepted()) {
            $this->flash->add($request, 'warning', $this->translator->trans('Too many tests in a row. Try again in a few minutes.'));

            return $back;
        }

        try {
            $error = $this->connections->test($connection);
        } catch (\Throwable $failure) {
            $this->errors->fail($this->translator->trans('AI connection test'), $failure);

            return $back;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('AI connection %s tested', (string) $connection->getTitle()), $id);

        // The message is plain text of the provider: the flash is escaped by the layout.
        $this->flash->add($request, null === $error ? 'success' : 'danger', null === $error
            ? $this->translator->trans('The provider answered: the connection works.')
            : $this->translator->trans('The test failed: %error%', ['%error%' => $error]));

        return $back;
    }

    #[Route('/settings', name: 'settings', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $back = new RedirectResponse($this->urls->generate(self::LIST_ROUTE));

        if (!$this->tokenIsValid($request)) {
            $this->flash->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $back;
        }

        $europeanUnionOnly = $request->request->getBoolean('european_union_only');
        $this->connections->setEuropeanUnionOnly($europeanUnionOnly);
        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, $europeanUnionOnly ? 'AI: European Union only turned on' : 'AI: European Union only turned off');
        $this->flash->add($request, 'success', $this->translator->trans('The setting was saved.'));

        return $back;
    }

    private function submit(Request $request, ?AiConnection $connection): Response
    {
        $form = $this->form($connection, []);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $values */
            $values = $form->getData();

            try {
                $this->connections->save($connection?->getId(), $values);
            } catch (HttpExceptionInterface $refusal) {
                $form->addError(new \Symfony\Component\Form\FormError($refusal->getMessage()));

                return $this->render($connection, $form);
            }

            $saved = $connection ?? AiConnectionQuery::create()->findOneByCode((string) $values['code']);
            $this->adminLogger->log(self::RESOURCE, null === $connection ? AccessManager::CREATE : AccessManager::UPDATE, \sprintf('AI connection %s saved', (string) $values['title']), $saved?->getId());
            $this->flash->add($request, 'success', $this->translator->trans('The connection was saved.'));

            return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['id' => (int) $saved?->getId()]));
        }

        return $this->render($connection, $form);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function form(?AiConnection $connection, array $data): FormInterface
    {
        return $this->formFactory->createNamed(self::FORM_NAME, AiConnectionType::class, $data, ['has_key' => $connection?->getHasKey() ?? false]);
    }

    /**
     * @return array<string, mixed> never the key: the field stays empty
     */
    private function dataOf(AiConnection $connection): array
    {
        return [
            'title' => $connection->getTitle(),
            'code' => $connection->getCode(),
            'provider' => $connection->getProvider(),
            'base_url' => $connection->getBaseUrl(),
            'jurisdiction' => $connection->getJurisdiction(),
            'api_key_env' => $connection->getApiKeyEnv(),
            'allow_private_network' => (bool) $connection->getAllowPrivateNetwork(),
            'active' => (bool) $connection->getActive(),
        ];
    }

    private function render(?AiConnection $connection, FormInterface $form): Response
    {
        $response = new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'connection' => $connection,
            'form' => $form->createView(),
            'has_key' => $connection?->getHasKey() ?? false,
            'jurisdiction_label' => null === $connection ? '' : $this->labels->jurisdiction($connection->getJurisdiction()),
            'outside_european_union' => AiJurisdiction::OutsideEuropeanUnion->value === $connection?->getJurisdiction(),
        ]));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function tokenIsValid(Request $request): bool
    {
        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(AiConnection $connection): array
    {
        $id = (int) $connection->getId();
        $checkError = (string) $connection->getCheckError();
        $checked = $connection->getCheckedAt();

        return [
            'id' => $id,
            'title' => (string) $connection->getTitle(),
            'code' => (string) $connection->getCode(),
            'provider' => $this->labels->provider($connection->getProvider()),
            'jurisdiction' => AiJurisdiction::EuropeanUnion->value === $connection->getJurisdiction() ? 'eu' : 'non_eu',
            'jurisdiction_label' => $this->labels->jurisdiction($connection->getJurisdiction()),
            'has_key' => $connection->getHasKey() ? 'yes' : 'no',
            'has_key_label' => $this->translator->trans($connection->getHasKey() ? 'Key saved: yes' : 'Key saved: no'),
            'status' => $connection->getActive() ? 'active' : 'inactive',
            'status_label' => $this->translator->trans($connection->getActive() ? 'In use' : 'Not in use'),
            'last_test' => null === $checked ? $this->translator->trans('Never tested') : ('' === $checkError ? $this->translator->trans('Worked on %date%', ['%date%' => $checked->format('Y-m-d H:i')]) : $this->translator->trans('Failed on %date%', ['%date%' => $checked->format('Y-m-d H:i')])),
            '_actions' => [
                new RowAction(kind: 'edit', label: $this->translator->trans('Edit'), href: $this->urls->generate(self::EDIT_ROUTE, ['id' => $id]), grantedAttribute: AccessManager::VIEW, grantedSubject: self::RESOURCE),
                new RowAction(kind: 'delete', label: $this->translator->trans('Delete'), modalTarget: '#ai-connection-delete-modal', grantedAttribute: AccessManager::DELETE, grantedSubject: self::RESOURCE, dataAttributes: ['connection-id' => $id, 'connection-label' => (string) $connection->getTitle()]),
            ],
        ];
    }
}
