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
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiApprovalAdmin;
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
use Thelia\Domain\Ai\Tool\AiToolApprovalServiceInterface;
use Thelia\Domain\Ai\Tool\AiToolApprovalStatus;
use Thelia\Domain\Ai\Tool\Exception\AiToolApprovalException;
use Thelia\Domain\Ai\Tool\Exception\AiToolApprovalFailure;
use Thelia\Model\Admin;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * Configuration > AI > Approvals: the changes the AI proposed, waiting for an administrator. The approver is the
 * administrator of the session, never a field of the form; every decision is a POST with its token. The right the
 * change asks for is checked by the service of the core, today, not by this screen.
 */
#[Route('/admin/configuration/ai/approvals', name: 'admin.configuration.ai.approvals.')]
final class AiApprovalController
{
    private const RESOURCE = AdminResources::AI;
    private const LIST_ROUTE = 'admin.configuration.ai.approvals.default';
    private const SHOW_ROUTE = 'admin.configuration.ai.approvals.show';

    public function __construct(
        private readonly AiApprovalAdmin $approvals,
        private readonly AiToolApprovalServiceInterface $decisions,
        private readonly SecurityContext $securityContext,
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly AdminFlash $flashes,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly TokenProvider $tokens,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function index(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $adminId = $this->adminId();

        return new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/ai/approval/list.html.twig', [
            'pending' => $this->approvals->pending($adminId),
            'applying' => $this->approvals->applying($adminId),
        ]));
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $approval = $this->approvals->detail($this->adminId(), $id);

        if (null === $approval) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $response = new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/ai/approval/show.html.twig', ['approval' => $approval]));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(Request $request, int $id): Response
    {
        return $this->decide($request, $id, function () use ($request, $id): void {
            $view = $this->decisions->approve($id);
            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('AI change %d decided: %s', $id, $view->status->value), $id);
            $this->flash($request, AiToolApprovalStatus::Applied === $view->status ? 'success' : 'danger', AiToolApprovalStatus::Applied === $view->status ? $this->translator->trans('The change was applied.') : $this->translator->trans('The change could not be applied. Check the reason below.'));
        });
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(Request $request, int $id): Response
    {
        return $this->decide($request, $id, function () use ($request, $id): void {
            $note = trim((string) $request->request->get('note', ''));
            $this->decisions->reject($id, '' === $note ? null : mb_substr($note, 0, 255));
            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('AI change %d rejected', $id), $id);
            $this->flash($request, 'success', $this->translator->trans('The change was rejected. Nothing was applied.'));
        });
    }

    #[Route('/{id}/close', name: 'close', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function close(Request $request, int $id): Response
    {
        return $this->decide($request, $id, function () use ($request, $id): void {
            $note = trim((string) $request->request->get('note', ''));

            if ('' === $note) {
                $this->flash($request, 'danger', $this->translator->trans('Say what you checked in the shop before closing this change.'));

                return;
            }

            $this->decisions->markFailed($id, mb_substr($note, 0, 255));
            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('AI change %d closed as failed by hand', $id), $id);
            $this->flash($request, 'success', $this->translator->trans('The change was closed as failed. It is never replayed.'));
        });
    }

    /**
     * @param \Closure(): void $decision
     */
    private function decide(Request $request, int $id, \Closure $decision): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $back = new RedirectResponse($this->urls->generate(self::SHOW_ROUTE, ['id' => $id]));

        if (!$this->tokenIsValid($request)) {
            $this->flash($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $back;
        }

        try {
            $decision();
        } catch (AiToolApprovalException $refusal) {
            $this->flash($request, 'danger', $this->refusal($refusal->reason));
        } catch (AiExceptionInterface $refusal) {
            $this->flash($request, 'danger', $refusal->getMessage());
        }

        return $back;
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $this->flashes->add($request, $type, $message);
    }

    private function refusal(AiToolApprovalFailure $reason): string
    {
        return match ($reason) {
            AiToolApprovalFailure::NotFound => $this->translator->trans('This proposal does not exist.'),
            AiToolApprovalFailure::NotPending => $this->translator->trans('This proposal is no longer waiting: another decision was taken first. Nothing was applied twice.'),
            AiToolApprovalFailure::Expired => $this->translator->trans('This proposal expired. Ask for it again.'),
            AiToolApprovalFailure::Forbidden => $this->translator->trans('You do not hold the right this change needs.'),
            AiToolApprovalFailure::ToolGone => $this->translator->trans('The tool that proposed this change no longer exists or asks for another right. Reject the proposal.'),
            default => $this->translator->trans('This change cannot be decided from here.'),
        };
    }

    private function adminId(): int
    {
        $admin = $this->securityContext->getAdminUser();

        return $admin instanceof Admin ? (int) $admin->getId() : 0;
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
}
