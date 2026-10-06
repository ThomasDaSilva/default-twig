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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Messenger\Monitoring\BackgroundJobsMonitor;
use Thelia\Model\Admin;
use Thelia\Model\ExportJobQuery;
use Thelia\Model\ImportJobQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The background jobs: whether the shop has a queue, how many jobs wait in it, the
 * ones that failed and why, and the recent exports and imports.
 *
 * A failed job keeps what it was dispatched with (the recipients of a mail, the
 * reason of a failure): the screen answers to a resource of its own, not to the
 * advanced configuration.
 */
#[Route('/admin/configuration/background-jobs', name: 'admin.configuration.background-jobs')]
final class BackgroundJobsController
{
    private const RESOURCE = AdminResources::BACKGROUND_JOBS;
    private const RECENT_JOBS = 20;

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly Environment $twig,
        private readonly BackgroundJobsMonitor $monitor,
        private readonly TokenProvider $tokens,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly SecurityContext $securityContext,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $admin = $this->securityContext->getAdminUser();

        return new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/background-jobs/index.html.twig', [
            'has_queue' => $this->monitor->hasQueue(),
            'pending_count' => $this->monitor->pendingCount(),
            'failed_count' => $this->monitor->failedCount(),
            'failed_jobs' => $this->monitor->failedJobs(),
            'recent_exports' => ExportJobQuery::create()->orderByCreatedAt('desc')->orderById('desc')->limit(self::RECENT_JOBS)->find(),
            'recent_imports' => ImportJobQuery::create()->orderByCreatedAt('desc')->orderById('desc')->limit(self::RECENT_JOBS)->find(),
            'can_retry' => null === $this->access->check(self::RESOURCE, [], AccessManager::UPDATE),
            'can_delete' => null === $this->access->check(self::RESOURCE, [], AccessManager::DELETE),
            'token' => $this->tokens->assignToken(),
            // The page of an export or an import belongs to whoever asked for it.
            'current_admin_id' => $admin instanceof Admin ? $admin->getId() : null,
            'is_super_admin' => $admin instanceof Admin && $admin->getPermissions() === AdminResources::SUPERADMINISTRATOR,
        ]));
    }

    #[Route('/{id}/retry', name: '.retry', methods: ['POST'])]
    public function retry(string $id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        if (!$this->hasValidToken($request)) {
            return $this->backToTheList();
        }

        try {
            if (!$this->monitor->retry($id)) {
                $this->flash($request, 'warning', $this->translator->trans('This failed job no longer exists.'));

                return $this->backToTheList();
            }
        } catch (\Throwable $exception) {
            $this->flash($request, 'danger', $this->translator->trans('The job failed again: %reason%', ['%reason%' => $exception->getMessage()]));

            return $this->backToTheList();
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Failed background job %s replayed', $id));
        $this->flash($request, 'success', $this->monitor->hasQueue()
            ? $this->translator->trans('The job is back in the queue.')
            : $this->translator->trans('The job ran again.'));

        return $this->backToTheList();
    }

    #[Route('/{id}/delete', name: '.delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::DELETE)) {
            return $denied;
        }

        if (!$this->hasValidToken($request)) {
            return $this->backToTheList();
        }

        if ($this->monitor->remove($id)) {
            $this->adminLogger->log(self::RESOURCE, AccessManager::DELETE, \sprintf('Failed background job %s deleted', $id));
            $this->flash($request, 'success', $this->translator->trans('The failed job is deleted.'));
        } else {
            $this->flash($request, 'warning', $this->translator->trans('This failed job no longer exists.'));
        }

        return $this->backToTheList();
    }

    private function hasValidToken(Request $request): bool
    {
        try {
            $this->tokens->checkToken((string) $request->request->get('_token'));

            return true;
        } catch (TokenAuthenticationException) {
            $this->flash($request, 'danger', $this->translator->trans('The form has expired, please try again.'));

            return false;
        }
    }

    private function backToTheList(): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate('admin.configuration.background-jobs'));
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
