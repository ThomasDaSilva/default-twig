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
use BackOfficeDefaultTwigBundle\Service\Admin\ImportTemplateBuilder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Archiver\ArchiverManager;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Serializer\SerializerManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\DataTransfer\ExportHandler;
use Thelia\Domain\DataTransfer\Job\ExportJobLauncher;
use Thelia\Domain\DataTransfer\Job\ImportJobLauncher;
use Thelia\Domain\DataTransfer\Job\JobStatus;
use Thelia\Domain\DataTransfer\ImportHandler;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\Admin;
use Thelia\Model\ExportJob;
use Thelia\Model\ExportJobQuery;
use Thelia\Model\ImportJob;
use Thelia\Model\ImportJobQuery;
use Thelia\Model\LangQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

final class ExportImportController
{
    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminFormAction $action,
        private readonly Environment $twig,
        private readonly ExportHandler $exportHandler,
        private readonly ImportHandler $importHandler,
        private readonly SerializerManager $serializerManager,
        private readonly ArchiverManager $archiverManager,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly DataTransferRepository $dataTransferRepository,
        private readonly ImportTemplateBuilder $importTemplateBuilder,
        private readonly TokenProvider $tokens,
        private readonly ExportJobLauncher $exportJobLauncher,
        private readonly ImportJobLauncher $importJobLauncher,
        private readonly SecurityContext $securityContext,
    ) {
    }

    private function checkCsrf(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $this->tokens->checkToken((string) ($request?->request->get('_token') ?? ''));
    }

    #[Route('/admin/export', name: 'export.list', methods: ['GET'])]
    public function exportIndex(): Response
    {
        if ($denied = $this->access->check(AdminResources::EXPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/export/list.html.twig', [
            'categories' => $this->dataTransferRepository->findExportCatalogue($this->defaultLocale()),
            'position_url' => $this->urls->generate('export.position'),
            'position_token' => $this->tokens->assignToken(),
        ]));
    }

    #[Route('/admin/import', name: 'import.list', methods: ['GET'])]
    public function importIndex(): Response
    {
        if ($denied = $this->access->check(AdminResources::IMPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/import/list.html.twig', [
            'categories' => $this->dataTransferRepository->findImportCatalogue($this->defaultLocale()),
            'position_url' => $this->urls->generate('import.position'),
            'position_token' => $this->tokens->assignToken(),
        ]));
    }

    #[Route('/admin/export/position', name: 'export.position', methods: ['POST'])]
    public function exportPosition(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: AdminResources::EXPORT,
            access: AccessManager::UPDATE,
            request: $request,
            event: new UpdatePositionEvent(
                (int) ($request->query->get('export_id') ?? $request->request->get('export_id', 0)),
                (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
                (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
            ),
            eventName: TheliaEvents::EXPORT_CHANGE_POSITION,
            actionLabel: 'Export reorder',
            successRoute: 'export.list',
        );
    }

    #[Route('/admin/export/position/category', name: 'export.category.position', methods: ['POST'])]
    public function exportCategoryPosition(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: AdminResources::EXPORT,
            access: AccessManager::UPDATE,
            request: $request,
            event: new UpdatePositionEvent(
                (int) ($request->query->get('export_category_id') ?? $request->request->get('export_category_id', 0)),
                (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
                (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
            ),
            eventName: TheliaEvents::EXPORT_CATEGORY_CHANGE_POSITION,
            actionLabel: 'Export category reorder',
            successRoute: 'export.list',
        );
    }

    #[Route('/admin/import/position', name: 'import.position', methods: ['POST'])]
    public function importPosition(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: AdminResources::IMPORT,
            access: AccessManager::UPDATE,
            request: $request,
            event: new UpdatePositionEvent(
                (int) ($request->query->get('import_id') ?? $request->request->get('import_id', 0)),
                (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
                (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
            ),
            eventName: TheliaEvents::IMPORT_CHANGE_POSITION,
            actionLabel: 'Import reorder',
            successRoute: 'import.list',
        );
    }

    #[Route('/admin/import/position/category', name: 'import.category.position', methods: ['POST'])]
    public function importCategoryPosition(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: AdminResources::IMPORT,
            access: AccessManager::UPDATE,
            request: $request,
            event: new UpdatePositionEvent(
                (int) ($request->query->get('import_category_id') ?? $request->request->get('import_category_id', 0)),
                (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
                (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
            ),
            eventName: TheliaEvents::IMPORT_CATEGORY_CHANGE_POSITION,
            actionLabel: 'Import category reorder',
            successRoute: 'import.list',
        );
    }

    #[Route('/admin/export/{id}', name: 'export.view', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exportView(int $id): Response
    {
        if ($denied = $this->access->check(AdminResources::EXPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $export = $this->exportHandler->getExport($id);
        if ($export === null) {
            return new RedirectResponse($this->urls->generate('export.list'));
        }

        $locale = $this->defaultLocale();
        $export->setLocale($locale);

        return new Response($this->twig->render('@BackOfficeDefaultTwig/export/edit.html.twig', [
            'export' => $export,
            'export_id' => $id,
            'serializers' => $this->serializerOptions(),
            'archivers' => $this->archiverOptions(),
            'languages' => $this->languageOptions(),
            'handler_available' => $export->isHandlerAvailable(),
            'use_range' => $export->useRangeDate(),
            'has_images' => (bool) $export->hasImages(),
            'has_documents' => (bool) $export->hasDocuments(),
            'years' => range((int) date('Y'), (int) date('Y') - 5),
            'months' => range(1, 12),
        ]));
    }

    #[Route('/admin/export/{id}', name: 'export.process', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function exportProcess(int $id, Request $request): Response|BinaryFileResponse
    {
        if ($denied = $this->access->check(AdminResources::EXPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $export = $this->exportHandler->getExport($id);
        if ($export === null) {
            return new RedirectResponse($this->urls->generate('export.list'));
        }

        $this->checkCsrf();

        @set_time_limit(0);

        $lang = LangQuery::create()->findPk((int) $request->request->get('language', 0));
        if ($lang === null) {
            $this->addFlash('error', $this->translator->trans('Invalid language selected.'));

            return new RedirectResponse($this->urls->generate('export.view', ['id' => $id]));
        }

        $serializerId = (string) $request->request->get('serializer', '');
        if (!$this->serializerManager->has($serializerId)) {
            $this->addFlash('error', $this->translator->trans('Unknown serializer.'));

            return new RedirectResponse($this->urls->generate('export.view', ['id' => $id]));
        }
        $serializer = $this->serializerManager->get($serializerId);

        $archiver = null;
        if ($request->request->getBoolean('do_compress')) {
            $archiverId = (string) $request->request->get('archiver', '');
            if ($this->archiverManager->has($archiverId)) {
                $archiver = $this->archiverManager->get($archiverId, true);
            }
        }

        $rangeDate = null;
        if ((array) $request->request->all('range_date_start') !== [] && (array) $request->request->all('range_date_end') !== []) {
            $rangeDate = [
                'start' => $request->request->all('range_date_start'),
                'end' => $request->request->all('range_date_end'),
            ];
        }

        try {
            $job = $this->exportJobLauncher->launch(
                $export,
                $serializerId,
                $archiver?->getId(),
                $lang,
                $request->request->getBoolean('images'),
                $request->request->getBoolean('documents'),
                $rangeDate,
                $this->adminId(),
            );
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());

            return new RedirectResponse($this->urls->generate('export.view', ['id' => $id]));
        }

        // Without a queue the export ran in this request: the file is served at once,
        // as before. With one, the page tells how far the worker got.
        return match ($job->getJobStatus()) {
            JobStatus::DONE => $this->exportFileResponse($job),
            JobStatus::FAILED => $this->failedExportResponse($job, $id),
            default => new RedirectResponse($this->urls->generate('export.job', ['jobId' => $job->getId()])),
        };
    }

    #[Route('/admin/export/job/{jobId}', name: 'export.job', methods: ['GET'], requirements: ['jobId' => '\d+'])]
    public function exportJob(int $jobId): Response
    {
        if ($denied = $this->access->check(AdminResources::EXPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $job = ExportJobQuery::create()->findPk($jobId);
        if ($job === null) {
            return new RedirectResponse($this->urls->generate('export.list'));
        }

        if ($denied = $this->deniedUnlessOwner($job->getAdminId())) {
            return $denied;
        }

        $job->getExport()?->setLocale($this->defaultLocale());

        return new Response($this->twig->render('@BackOfficeDefaultTwig/export/job.html.twig', [
            'job' => $job,
            'file_available' => $job->getJobStatus() === JobStatus::DONE && is_file((string) $job->getFilePath()),
        ]));
    }

    #[Route('/admin/export/job/{jobId}/download', name: 'export.job.download', methods: ['GET'], requirements: ['jobId' => '\d+'])]
    public function exportJobDownload(int $jobId): Response
    {
        if ($denied = $this->access->check(AdminResources::EXPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $job = ExportJobQuery::create()->findPk($jobId);
        if ($job !== null && ($denied = $this->deniedUnlessOwner($job->getAdminId()))) {
            return $denied;
        }

        if ($job === null || $job->getJobStatus() !== JobStatus::DONE || !is_file((string) $job->getFilePath())) {
            $this->addFlash('error', $this->translator->trans('The file of this export is no longer available. Run the export again.'));

            return new RedirectResponse($this->urls->generate($job === null ? 'export.list' : 'export.job', $job === null ? [] : ['jobId' => $jobId]));
        }

        return $this->exportFileResponse($job);
    }

    private function exportFileResponse(ExportJob $job): BinaryFileResponse
    {
        $archiverId = $job->getArchiver();
        $contentType = $archiverId !== null
            ? ($this->archiverManager->get($archiverId)?->getMimeType() ?? 'application/octet-stream')
            : $this->serializerManager->get($job->getSerializer())->getMimeType();

        return new BinaryFileResponse((string) $job->getFilePath(), Response::HTTP_OK, [
            'Content-Type' => $contentType,
            'Content-Disposition' => \sprintf('%s; filename="%s"', ResponseHeaderBag::DISPOSITION_ATTACHMENT, (string) $job->getFileName()),
        ], false);
    }

    private function failedExportResponse(ExportJob $job, int $exportId): RedirectResponse
    {
        $this->addFlash('error', (string) $job->getError());

        return new RedirectResponse($this->urls->generate('export.view', ['id' => $exportId]));
    }

    /**
     * An exported file and the refused rows of an import hold customer and order data:
     * they are the business of the administrator who asked for them, and of a
     * super-administrator, not of every colleague who may run an export.
     */
    private function deniedUnlessOwner(?int $ownerId): ?Response
    {
        $admin = $this->securityContext->getAdminUser();

        if ($admin instanceof Admin && ($admin->getPermissions() === AdminResources::SUPERADMINISTRATOR || ($ownerId !== null && $admin->getId() === $ownerId))) {
            return null;
        }

        return new Response($this->translator->trans("Sorry, you're not allowed to perform this action"), Response::HTTP_FORBIDDEN);
    }

    private function adminId(): ?int
    {
        $admin = $this->securityContext->getAdminUser();

        return $admin instanceof Admin ? $admin->getId() : null;
    }

    #[Route('/admin/import/{id}', name: 'import.view', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function importView(int $id): Response
    {
        if ($denied = $this->access->check(AdminResources::IMPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $import = $this->importHandler->getImport($id);
        if ($import === null) {
            return new RedirectResponse($this->urls->generate('import.list'));
        }

        $locale = $this->defaultLocale();
        $import->setLocale($locale);

        return new Response($this->twig->render('@BackOfficeDefaultTwig/import/edit.html.twig', [
            'import' => $import,
            'import_id' => $id,
            'handler_available' => $import->isHandlerAvailable(),
            'languages' => $this->languageOptions(),
            'allowed_extensions' => implode(', ', $this->importHandler->getAcceptedExtensions()),
            'allowed_mime_types' => implode(', ', $this->importHandler->getAcceptedMimeTypes()),
            'has_template' => $this->importTemplateBuilder->columnsFor($import) !== [],
        ]));
    }

    #[Route('/admin/import/{id}', name: 'import.process', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function importProcess(int $id, Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::IMPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $import = $this->importHandler->getImport($id);
        if ($import === null) {
            return new RedirectResponse($this->urls->generate('import.list'));
        }

        $this->checkCsrf();

        $uploaded = $request->files->get('file_upload');
        if (!$uploaded instanceof UploadedFile) {
            $this->addFlash('error', $this->translator->trans('Please select a file to import.'));

            return new RedirectResponse($this->urls->generate('import.view', ['id' => $id]));
        }

        $lang = LangQuery::create()->findPk((int) $request->request->get('language', 0));
        if ($lang === null) {
            $this->addFlash('error', $this->translator->trans('Invalid language selected.'));

            return new RedirectResponse($this->urls->generate('import.view', ['id' => $id]));
        }

        // The page advertises the formats the import handlers can read; enforce them
        // before anything is written to the import cache directory.
        try {
            $this->importHandler->validateUpload($uploaded->getClientOriginalName());
        } catch (FormValidationException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return new RedirectResponse($this->urls->generate('import.view', ['id' => $id]));
        }

        try {
            $job = $this->importJobLauncher->launch($import, $uploaded, $uploaded->getClientOriginalName(), $lang, $this->adminId());
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());

            return new RedirectResponse($this->urls->generate('import.view', ['id' => $id]));
        }

        // Without a queue the import ran in this request and is told here, as before.
        // With one, the page tells how it went once a worker ran it.
        if (!$job->isFinished()) {
            return new RedirectResponse($this->urls->generate('import.job', ['jobId' => $job->getId()]));
        }

        $this->flashImportOutcome($job);

        return new RedirectResponse($this->urls->generate('import.view', ['id' => $id]));
    }

    #[Route('/admin/import/job/{jobId}', name: 'import.job', methods: ['GET'], requirements: ['jobId' => '\\d+'])]
    public function importJob(int $jobId): Response
    {
        if ($denied = $this->access->check(AdminResources::IMPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $job = ImportJobQuery::create()->findPk($jobId);
        if ($job === null) {
            return new RedirectResponse($this->urls->generate('import.list'));
        }

        if ($denied = $this->deniedUnlessOwner($job->getAdminId())) {
            return $denied;
        }

        $job->getImport()?->setLocale($this->defaultLocale());

        return new Response($this->twig->render('@BackOfficeDefaultTwig/import/job.html.twig', ['job' => $job]));
    }

    private function flashImportOutcome(ImportJob $job): void
    {
        if ($job->getJobStatus() === JobStatus::FAILED) {
            $this->addFlash('error', (string) $job->getError());

            return;
        }

        $errors = $job->getRowErrorList();
        if (\count($errors) > 0) {
            $this->addFlash('error', $this->translator->trans(
                'Error(s) in import : %errors',
                ['%errors' => implode(' | ', $errors)],
            ));
        }
        $this->addFlash('success', $this->translator->trans(
            'Import successfully done, %count row(s) have been changed',
            ['%count' => $job->getImportedRows()],
        ));
    }

    #[Route('/admin/import/{id}/template', name: 'import.template', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function importTemplate(int $id): Response
    {
        if ($denied = $this->access->check(AdminResources::IMPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $import = $this->importHandler->getImport($id);
        if ($import === null) {
            return new RedirectResponse($this->urls->generate('import.list'));
        }

        if (!$this->importTemplateBuilder->isCsvAvailable()) {
            $this->addFlash('error', $this->translator->trans('The CSV format is not available.'));

            return new RedirectResponse($this->urls->generate('import.view', ['id' => $id]));
        }

        if ($this->importTemplateBuilder->columnsFor($import) === []) {
            $this->addFlash('error', $this->translator->trans('No column template is available for this import.'));

            return new RedirectResponse($this->urls->generate('import.view', ['id' => $id]));
        }

        return new Response(
            $this->importTemplateBuilder->build($import),
            Response::HTTP_OK,
            [
                'Content-Type' => $this->importTemplateBuilder->mimeType(),
                'Content-Disposition' => \sprintf(
                    '%s; filename="%s"',
                    ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                    $this->importTemplateBuilder->fileNameFor($import),
                ),
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /** @return list<array{id: string, name: string, extension: string}> */
    private function serializerOptions(): array
    {
        $options = [];
        foreach ($this->serializerManager->getSerializers() as $serializer) {
            $options[] = [
                'id' => (string) $serializer->getId(),
                'name' => (string) $serializer->getName(),
                'extension' => (string) $serializer->getExtension(),
            ];
        }

        return $options;
    }

    /** @return list<array{id: string, name: string, extension: string}> */
    private function archiverOptions(): array
    {
        $options = [];
        foreach ($this->archiverManager->getArchivers(true) as $archiver) {
            $options[] = [
                'id' => (string) $archiver->getId(),
                'name' => (string) $archiver->getName(),
                'extension' => (string) $archiver->getExtension(),
            ];
        }

        return $options;
    }

    /** @return list<array{id: int, title: string, is_default: bool}> */
    private function languageOptions(): array
    {
        $options = [];
        foreach (LangQuery::create()->orderByPosition()->find() as $lang) {
            $options[] = [
                'id' => (int) $lang->getId(),
                'title' => (string) $lang->getTitle(),
                'is_default' => (bool) $lang->getByDefault(),
            ];
        }

        return $options;
    }

    private function addFlash(string $type, string $message): void
    {
        $session = $this->requestStack->getSession();
        if (method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
