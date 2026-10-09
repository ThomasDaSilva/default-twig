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

namespace BackOfficeDefaultTwigBundle\Service\Admin;

use BackOfficeDefaultTwigBundle\DTO\DataTransfer\ExportLaunchInput;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\DataTransfer\Job\ExportJobLauncher;
use Thelia\Domain\DataTransfer\Job\JobStatus;
use Thelia\Messenger\JobFailureMessage;
use Thelia\Model\Export;
use Thelia\Model\ExportJob;

/**
 * Launches an export posted from its form, once the administrator is allowed to: reads
 * the form, holds the administrator to the launch limit, and serves the file when the
 * export ran in the request.
 */
final readonly class ExportLaunchAction
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private DataTransferLaunchInputReader $inputReader,
        private DataTransferLaunchGuard $launchGuard,
        private ExportJobLauncher $launcher,
        private DataTransferJobAccess $jobAccess,
        private ExportJobFile $exportJobFile,
        private AdminFlash $flash,
    ) {
    }

    public function launch(Export $export, Request $request): Response
    {
        $backToTheForm = new RedirectResponse($this->urls->generate('export.view', ['id' => $export->getId()]));

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
            JobStatus::DONE => $this->exportJobFile->isAvailable($job) ? $this->exportJobFile->response($job) : $this->fileGone($request, $job),
            JobStatus::FAILED => $this->failed($request, (string) $job->getError(), $backToTheForm),
            default => new RedirectResponse($this->urls->generate('export.job', ['jobId' => $job->getId()])),
        };
    }

    /**
     * A listener of the export moved its file out of the export folder: nothing is served.
     */
    private function fileGone(Request $request, ExportJob $job): RedirectResponse
    {
        $this->flash->add($request, 'error', $this->translator->trans('The file of this export is no longer available. Run the export again.'));

        return new RedirectResponse($this->urls->generate('export.job', ['jobId' => $job->getId()]));
    }

    private function failed(Request $request, string $error, RedirectResponse $backToTheForm): RedirectResponse
    {
        $this->flash->add($request, 'error', $this->translator->trans($error));

        return $backToTheForm;
    }
}
