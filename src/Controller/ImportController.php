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
use BackOfficeDefaultTwigBundle\Service\Admin\ImportTemplateBuilder;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\DataTransfer\ImportHandler;
use Thelia\Domain\DataTransfer\Job\ImportJobLauncher;
use Thelia\Domain\DataTransfer\Job\JobStatus;
use Thelia\Messenger\JobFailureMessage;
use Thelia\Model\ImportJob;
use Thelia\Model\LangQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * Tools > Imports: the list, the form of an import, its column template, and its
 * launch as a job.
 */
final readonly class ImportController
{
    /** The refused rows a message quotes; the page of the job lists them all. */
    private const ROW_ERRORS_IN_A_MESSAGE = 10;

    public function __construct(
        private AdminAccessChecker $access,
        private AdminFormAction $action,
        private Environment $twig,
        private ImportHandler $importHandler,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private DataTransferRepository $repository,
        private ImportTemplateBuilder $importTemplateBuilder,
        private TokenProvider $tokens,
        private DataTransferFormOptions $formOptions,
        private DataTransferLaunchGuard $launchGuard,
        private ImportJobLauncher $launcher,
        private DataTransferJobAccess $jobAccess,
    ) {
    }

    #[Route('/admin/import', name: 'import.list', methods: ['GET'])]
    public function index(): Response
    {
        if ($denied = $this->access->check(AdminResources::IMPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/import/list.html.twig', [
            'categories' => $this->repository->findImportCatalogue($this->repository->defaultLocale()),
            'position_url' => $this->urls->generate('import.position'),
            'position_token' => $this->tokens->assignToken(),
        ]));
    }

    #[Route('/admin/import/position', name: 'import.position', methods: ['POST'])]
    public function position(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: AdminResources::IMPORT,
            access: AccessManager::UPDATE,
            request: $request,
            event: self::positionEvent($request, 'import_id'),
            eventName: TheliaEvents::IMPORT_CHANGE_POSITION,
            actionLabel: 'Import reorder',
            successRoute: 'import.list',
        );
    }

    #[Route('/admin/import/position/category', name: 'import.category.position', methods: ['POST'])]
    public function categoryPosition(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: AdminResources::IMPORT,
            access: AccessManager::UPDATE,
            request: $request,
            event: self::positionEvent($request, 'import_category_id'),
            eventName: TheliaEvents::IMPORT_CATEGORY_CHANGE_POSITION,
            actionLabel: 'Import category reorder',
            successRoute: 'import.list',
        );
    }

    #[Route('/admin/import/{id}', name: 'import.view', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function view(int $id): Response
    {
        if ($denied = $this->access->check(AdminResources::IMPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $import = $this->importHandler->getImport($id);
        if ($import === null) {
            return new RedirectResponse($this->urls->generate('import.list'));
        }

        $import->setLocale($this->repository->defaultLocale());

        return new Response($this->twig->render('@BackOfficeDefaultTwig/import/edit.html.twig', [
            'import' => $import,
            'import_id' => $id,
            'handler_available' => $import->isHandlerAvailable(),
            'languages' => $this->formOptions->languages(),
            'allowed_extensions' => implode(', ', $this->importHandler->getAcceptedExtensions()),
            'allowed_mime_types' => implode(', ', $this->importHandler->getAcceptedMimeTypes()),
            'has_template' => $this->importTemplateBuilder->columnsFor($import) !== [],
        ]));
    }

    #[Route('/admin/import/{id}', name: 'import.process', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function launch(int $id, Request $request): Response
    {
        // An import changes the catalog: seeing the imports is not enough to run one.
        if ($denied = $this->access->check(AdminResources::IMPORT, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $import = $this->importHandler->getImport($id);
        if ($import === null) {
            return new RedirectResponse($this->urls->generate('import.list'));
        }

        $backToTheForm = new RedirectResponse($this->urls->generate('import.view', ['id' => $id]));

        if (!$this->launchGuard->hasValidToken($request)) {
            return $backToTheForm;
        }

        $uploaded = $request->files->get('file_upload');
        if (!$uploaded instanceof UploadedFile) {
            $this->flash($request, 'error', $this->translator->trans('Please select a file to import.'));

            return $backToTheForm;
        }

        // A file the server did not take whole (too large, interrupted) has nothing to
        // read: the reason PHP gives is told rather than "not a CSV file".
        if (!$uploaded->isValid()) {
            $this->flash($request, 'error', $uploaded->getErrorMessage());

            return $backToTheForm;
        }

        $language = LangQuery::create()->findPk((int) $request->request->get('language', 0));
        if ($language === null) {
            $this->flash($request, 'error', $this->translator->trans('Invalid language selected.'));

            return $backToTheForm;
        }

        if (!$this->launchGuard->mayLaunch($request)) {
            return $backToTheForm;
        }

        // The launcher checks the name and the content of the file before anything is
        // written to the import storage.
        try {
            $job = $this->launcher->launch($import, $uploaded, $uploaded->getClientOriginalName(), $language, $this->jobAccess->currentAdminId());
        } catch (\Throwable $exception) {
            $this->flash($request, 'error', $this->translator->trans(JobFailureMessage::forAdministrator($exception)));

            return $backToTheForm;
        }

        // Without a queue the import ran in this request and is told here, as before.
        // With one, the page tells how it went once a worker ran it.
        if (!$job->isFinished()) {
            return new RedirectResponse($this->urls->generate('import.job', ['jobId' => $job->getId()]));
        }

        $this->flashOutcome($request, $job);

        // Past a few refused rows, the page of the job lists them all.
        return \count($job->getRowErrorList()) > self::ROW_ERRORS_IN_A_MESSAGE
            ? new RedirectResponse($this->urls->generate('import.job', ['jobId' => $job->getId()]))
            : $backToTheForm;
    }

    #[Route('/admin/import/{id}/template', name: 'import.template', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function template(int $id, Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::IMPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $import = $this->importHandler->getImport($id);
        if ($import === null) {
            return new RedirectResponse($this->urls->generate('import.list'));
        }

        if (!$this->importTemplateBuilder->isCsvAvailable()) {
            $this->flash($request, 'error', $this->translator->trans('The CSV format is not available.'));

            return new RedirectResponse($this->urls->generate('import.view', ['id' => $id]));
        }

        if ($this->importTemplateBuilder->columnsFor($import) === []) {
            $this->flash($request, 'error', $this->translator->trans('No column template is available for this import.'));

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

    private function flashOutcome(Request $request, ImportJob $job): void
    {
        if ($job->getJobStatus() === JobStatus::FAILED) {
            $this->flash($request, 'error', $this->translator->trans((string) $job->getError()));

            return;
        }

        // The message lives in the session: a few refused rows, and where to read the
        // others, rather than thousands of them.
        $errors = $job->getRowErrorList();
        if ($errors !== []) {
            $quoted = implode(' | ', \array_slice($errors, 0, self::ROW_ERRORS_IN_A_MESSAGE));
            $this->flash($request, 'error', \count($errors) > self::ROW_ERRORS_IN_A_MESSAGE
                ? $this->translator->trans('Error(s) in import : %errors and %count more rows refused.', ['%errors' => $quoted, '%count' => \count($errors) - self::ROW_ERRORS_IN_A_MESSAGE])
                : $this->translator->trans('Error(s) in import : %errors', ['%errors' => $quoted]));
        }

        $this->flash($request, 'success', $this->translator->trans(
            'Import successfully done, %count row(s) have been changed',
            ['%count' => $job->getImportedRows()],
        ));
    }

    private static function positionEvent(Request $request, string $idField): UpdatePositionEvent
    {
        return new UpdatePositionEvent(
            (int) ($request->query->get($idField) ?? $request->request->get($idField, 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
