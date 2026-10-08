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

use BackOfficeDefaultTwigBundle\DTO\DataTransfer\ImportLaunchInput;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\DataTransfer\Job\ImportJobLauncher;
use Thelia\Domain\DataTransfer\Job\JobStatus;
use Thelia\Messenger\JobFailureMessage;
use Thelia\Model\Import;
use Thelia\Model\ImportJob;

/**
 * Launches an import posted from its form, once the administrator is allowed to: reads
 * the form, holds the administrator to the launch limit, and tells how it went.
 */
final readonly class ImportLaunchAction
{
    /** The refused rows a message quotes; the page of the job lists them all. */
    private const ROW_ERRORS_IN_A_MESSAGE = 10;

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private DataTransferLaunchInputReader $inputReader,
        private DataTransferLaunchGuard $launchGuard,
        private ImportJobLauncher $launcher,
        private DataTransferJobAccess $jobAccess,
        private AdminFlash $flash,
    ) {
    }

    public function launch(Import $import, Request $request): RedirectResponse
    {
        $backToTheForm = new RedirectResponse($this->urls->generate('import.view', ['id' => $import->getId()]));

        // Over post_max_size, PHP drops the whole body, the token with it: the file is
        // too large, the form has not expired.
        if (0 === $request->request->count() && 0 === $request->files->count() && (int) $request->server->get('CONTENT_LENGTH', 0) > 0) {
            $this->flash->add($request, 'error', $this->translator->trans('The file is larger than the server accepts: post_max_size is %size%.', ['%size%' => (string) \ini_get('post_max_size')]));

            return $backToTheForm;
        }

        if (!$this->launchGuard->hasValidToken($request)) {
            return $backToTheForm;
        }

        $input = $this->inputReader->importInput($request);
        if (!$input instanceof ImportLaunchInput) {
            $this->flash->add($request, 'error', $input->reason);

            return $backToTheForm;
        }

        if (!$this->launchGuard->mayLaunch($request)) {
            return $backToTheForm;
        }

        try {
            $job = $this->launcher->launch($import, $input->file, $input->file->getClientOriginalName(), $input->language, $this->jobAccess->currentAdminId());
        } catch (\Throwable $exception) {
            $this->flash->add($request, 'error', $this->translator->trans(JobFailureMessage::forAdministrator($exception)));

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

    private function flashOutcome(Request $request, ImportJob $job): void
    {
        if ($job->getJobStatus() === JobStatus::FAILED) {
            $this->flash->add($request, 'error', $this->translator->trans((string) $job->getError()));

            return;
        }

        // The message lives in the session: a few refused rows, and where to read the
        // others, rather than thousands of them.
        $errors = $job->getRowErrorList();
        if ($errors !== []) {
            $quoted = implode(' | ', \array_slice($errors, 0, self::ROW_ERRORS_IN_A_MESSAGE));
            $this->flash->add($request, 'error', \count($errors) > self::ROW_ERRORS_IN_A_MESSAGE
                ? $this->translator->trans('Error(s) in import : %errors and %count more rows refused.', ['%errors' => $quoted, '%count' => \count($errors) - self::ROW_ERRORS_IN_A_MESSAGE])
                : $this->translator->trans('Error(s) in import : %errors', ['%errors' => $quoted]));
        }

        $this->flash->add($request, 'success', $this->translator->trans(
            'Import successfully done, %count row(s) have been changed',
            ['%count' => $job->getImportedRows()],
        ));
    }
}
