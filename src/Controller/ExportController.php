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

use BackOfficeDefaultTwigBundle\DTO\DataTransfer\ExportLaunchInput;
use BackOfficeDefaultTwigBundle\Repository\DataTransferRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\DataTransferFormOptions;
use BackOfficeDefaultTwigBundle\Service\Admin\DataTransferJobAccess;
use BackOfficeDefaultTwigBundle\Service\Admin\DataTransferLaunchGuard;
use BackOfficeDefaultTwigBundle\Service\Admin\DataTransferLaunchInputReader;
use BackOfficeDefaultTwigBundle\Service\Admin\ExportJobFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\DataTransfer\ExportHandler;
use Thelia\Domain\DataTransfer\Job\ExportJobLauncher;
use Thelia\Domain\DataTransfer\Job\JobStatus;
use Thelia\Messenger\JobFailureMessage;
use Twig\Environment;

/**
 * Tools > Exports: the form of an export, and its launch as a job.
 */
final readonly class ExportController
{
    public function __construct(
        private AdminAccessChecker $access,
        private Environment $twig,
        private ExportHandler $exportHandler,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private DataTransferRepository $repository,
        private DataTransferFormOptions $formOptions,
        private DataTransferLaunchInputReader $inputReader,
        private DataTransferLaunchGuard $launchGuard,
        private ExportJobLauncher $launcher,
        private DataTransferJobAccess $jobAccess,
        private ExportJobFile $exportJobFile,
        private AdminFlash $flash,
    ) {
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

        $input = $this->inputReader->exportInput($request);
        if (!$input instanceof ExportLaunchInput) {
            $this->flash->add($request, 'error', $input->reason);

            return $backToTheForm;
        }

        if (!$this->launchGuard->mayLaunch($request)) {
            return $backToTheForm;
        }

        @set_time_limit(0);

        try {
            $job = $this->launcher->launch($export, $input->serializerId, $input->archiverId, $input->language, $input->includeImages, $input->includeDocuments, $input->rangeDate, $this->jobAccess->currentAdminId());
        } catch (\Throwable $exception) {
            $this->flash->add($request, 'error', $this->translator->trans(JobFailureMessage::forAdministrator($exception)));

            return $backToTheForm;
        }

        // Without a queue the export ran in this request: the file is served at once,
        // as before. With one, the page tells how far the worker got.
        return match ($job->getJobStatus()) {
            JobStatus::DONE => $this->exportJobFile->response($job),
            JobStatus::FAILED => $this->failed($request, (string) $job->getError(), $backToTheForm),
            default => new RedirectResponse($this->urls->generate('export.job', ['jobId' => $job->getId()])),
        };
    }

    private function failed(Request $request, string $error, RedirectResponse $backToTheForm): RedirectResponse
    {
        $this->flash->add($request, 'error', $this->translator->trans($error));

        return $backToTheForm;
    }
}
