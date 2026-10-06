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

namespace BackOfficeDefaultTwigBundle\Controller;

use BackOfficeDefaultTwigBundle\Repository\DataTransferRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Admin\DataTransferFormOptions;
use BackOfficeDefaultTwigBundle\Service\Admin\DataTransferJobAccess;
use BackOfficeDefaultTwigBundle\Service\Admin\DataTransferLaunchGuard;
use BackOfficeDefaultTwigBundle\Service\Admin\ExportJobFile;
use BackOfficeDefaultTwigBundle\Service\Admin\ExportLaunchInput;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\DataTransfer\ExportHandler;
use Thelia\Domain\DataTransfer\Job\ExportJobLauncher;
use Thelia\Domain\DataTransfer\Job\JobStatus;
use Thelia\Messenger\JobFailureMessage;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * Tools > Exports: the list, the form of an export, and its launch as a job.
 */
final readonly class ExportController
{
    public function __construct(
        private AdminAccessChecker $access,
        private AdminFormAction $action,
        private Environment $twig,
        private ExportHandler $exportHandler,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private DataTransferRepository $repository,
        private TokenProvider $tokens,
        private DataTransferFormOptions $formOptions,
        private DataTransferLaunchGuard $launchGuard,
        private ExportJobLauncher $launcher,
        private DataTransferJobAccess $jobAccess,
        private ExportJobFile $exportJobFile,
    ) {
    }

    #[Route('/admin/export', name: 'export.list', methods: ['GET'])]
    public function index(): Response
    {
        if ($denied = $this->access->check(AdminResources::EXPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/export/list.html.twig', [
            'categories' => $this->repository->findExportCatalogue($this->repository->defaultLocale()),
            'position_url' => $this->urls->generate('export.position'),
            'position_token' => $this->tokens->assignToken(),
        ]));
    }

    #[Route('/admin/export/position', name: 'export.position', methods: ['POST'])]
    public function position(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: AdminResources::EXPORT,
            access: AccessManager::UPDATE,
            request: $request,
            event: self::positionEvent($request, 'export_id'),
            eventName: TheliaEvents::EXPORT_CHANGE_POSITION,
            actionLabel: 'Export reorder',
            successRoute: 'export.list',
        );
    }

    #[Route('/admin/export/position/category', name: 'export.category.position', methods: ['POST'])]
    public function categoryPosition(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: AdminResources::EXPORT,
            access: AccessManager::UPDATE,
            request: $request,
            event: self::positionEvent($request, 'export_category_id'),
            eventName: TheliaEvents::EXPORT_CATEGORY_CHANGE_POSITION,
            actionLabel: 'Export category reorder',
            successRoute: 'export.list',
        );
    }

    #[Route('/admin/export/{id}', name: 'export.view', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function view(int $id): Response
    {
        if ($denied = $this->access->check(AdminResources::EXPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $export = $this->exportHandler->getExport($id);
        if ($export === null) {
            return new RedirectResponse($this->urls->generate('export.list'));
        }

        $export->setLocale($this->repository->defaultLocale());

        return new Response($this->twig->render('@BackOfficeDefaultTwig/export/edit.html.twig', [
            'export' => $export,
            'export_id' => $id,
            'serializers' => $this->formOptions->serializers(),
            'archivers' => $this->formOptions->archivers(),
            'languages' => $this->formOptions->languages(),
            'handler_available' => $export->isHandlerAvailable(),
            'use_range' => $export->useRangeDate(),
            'has_images' => (bool) $export->hasImages(),
            'has_documents' => (bool) $export->hasDocuments(),
            'years' => range((int) date('Y'), (int) date('Y') - 5),
            'months' => range(1, 12),
        ]));
    }

    #[Route('/admin/export/{id}', name: 'export.process', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function launch(int $id, Request $request): Response|BinaryFileResponse
    {
        if ($denied = $this->access->check(AdminResources::EXPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $export = $this->exportHandler->getExport($id);
        if ($export === null) {
            return new RedirectResponse($this->urls->generate('export.list'));
        }

        $backToTheForm = new RedirectResponse($this->urls->generate('export.view', ['id' => $id]));

        if (!$this->launchGuard->hasValidToken($request)) {
            return $backToTheForm;
        }

        $input = $this->formOptions->exportInput($request);
        if (!$input instanceof ExportLaunchInput) {
            $this->flash($request, $input);

            return $backToTheForm;
        }

        if (!$this->launchGuard->mayLaunch($request)) {
            return $backToTheForm;
        }

        @set_time_limit(0);

        try {
            $job = $this->launcher->launch(
                $export,
                $input->serializerId,
                $input->archiverId,
                $input->language,
                $input->includeImages,
                $input->includeDocuments,
                $input->rangeDate,
                $this->jobAccess->currentAdminId(),
            );
        } catch (\Throwable $exception) {
            $this->flash($request, $this->translator->trans(JobFailureMessage::forAdministrator($exception)));

            return $backToTheForm;
        }

        // Without a queue the export ran in this request: the file is served at once,
        // as before. With one, the page tells how far the worker got.
        if (JobStatus::DONE === $job->getJobStatus()) {
            return $this->exportJobFile->response($job);
        }

        if (JobStatus::FAILED === $job->getJobStatus()) {
            $this->flash($request, $this->translator->trans((string) $job->getError()));

            return $backToTheForm;
        }

        return new RedirectResponse($this->urls->generate('export.job', ['jobId' => $job->getId()]));
    }

    private static function positionEvent(Request $request, string $idField): UpdatePositionEvent
    {
        return new UpdatePositionEvent(
            (int) ($request->query->get($idField) ?? $request->request->get($idField, 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );
    }

    private function flash(Request $request, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $message);
        }
    }
}
