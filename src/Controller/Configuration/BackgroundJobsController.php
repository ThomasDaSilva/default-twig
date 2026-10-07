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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormToken;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Admin\BackgroundJobsScreen;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Messenger\JobFailureMessage;
use Thelia\Messenger\Monitoring\BackgroundJobsMonitor;
use Twig\Environment;

/**
 * The background jobs: whether the shop has a queue, how many jobs wait in it, the
 * ones that failed and why, the recurring tasks whose last run failed, and the recent
 * exports and imports.
 *
 * A failed job keeps what it was dispatched with (the recipients of a mail, the
 * reason of a failure): the screen answers to a resource of its own, not to the
 * advanced configuration.
 */
#[Route('/admin/configuration/background-jobs', name: 'admin.configuration.background-jobs')]
final readonly class BackgroundJobsController
{
    private const RESOURCE = AdminResources::BACKGROUND_JOBS;

    public function __construct(
        private AdminAccessChecker $access,
        private AdminLogger $adminLogger,
        private Environment $twig,
        private BackgroundJobsMonitor $monitor,
        private BackgroundJobsScreen $screen,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private AdminFormToken $formToken,
        private AdminFlash $flash,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/background-jobs/index.html.twig', $this->screen->view()));
    }

    #[Route('/{id}/retry', name: '.retry', methods: ['POST'])]
    public function retry(string $id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        if (!$this->formToken->isValid($request)) {
            return $this->backToTheList();
        }

        try {
            if (!$this->monitor->retry($id)) {
                $this->flash->add($request, 'warning', $this->translator->trans('This failed job no longer exists.'));

                return $this->backToTheList();
            }
        } catch (\Throwable $exception) {
            // The reason may quote a query, a host or the content of the job: the screen
            // says to read the log, and the log names the exception, not its text.
            $this->logger->error(\sprintf('The failed background job %s failed again when replayed: %s', $id, JobFailureMessage::forLog($exception)));
            $this->flash->add($request, 'danger', $this->translator->trans('The job failed again. The details are in the server log.'));

            return $this->backToTheList();
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Failed background job %s replayed', $id));
        $this->flash->add($request, 'success', $this->monitor->hasQueue()
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

        if (!$this->formToken->isValid($request)) {
            return $this->backToTheList();
        }

        if ($this->monitor->remove($id)) {
            $this->adminLogger->log(self::RESOURCE, AccessManager::DELETE, \sprintf('Failed background job %s deleted', $id));
            $this->flash->add($request, 'success', $this->translator->trans('The failed job is deleted.'));
        } else {
            $this->flash->add($request, 'warning', $this->translator->trans('This failed job no longer exists.'));
        }

        return $this->backToTheList();
    }

    private function backToTheList(): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate('admin.configuration.background-jobs'));
    }
}
