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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\DataTransferJobAccess;
use BackOfficeDefaultTwigBundle\Service\Admin\ExportJobFile;
use BackOfficeDefaultTwigBundle\Service\Admin\JobPageRefresh;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Twig\Environment;

/**
 * The page of an export run as a job, and the file it wrote.
 */
final readonly class ExportJobController
{
    public function __construct(
        private AdminAccessChecker $access,
        private DataTransferJobAccess $jobAccess,
        private DataTransferRepository $repository,
        private ExportJobFile $exportJobFile,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private AdminFlash $flash,
    ) {
    }

    #[Route('/admin/export/job/{jobId}', name: 'export.job', methods: ['GET'], requirements: ['jobId' => '\d+'])]
    public function show(int $jobId, Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::EXPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $job = $this->repository->findExportJob($jobId);
        if (null === $job) {
            return new RedirectResponse($this->urls->generate('export.list'));
        }

        if (!$this->jobAccess->maySee($job->getAdminId())) {
            return $this->jobAccess->forbidden();
        }

        // Never null: the row goes with its export (foreign key on delete cascade).
        $job->getExport()->setLocale($this->repository->defaultLocale());

        return new Response($this->twig->render('@BackOfficeDefaultTwig/export/job.html.twig', [
            'job' => $job,
            'file_available' => $this->exportJobFile->isAvailable($job),
            ...JobPageRefresh::of($job->isFinished(), $request),
        ]));
    }

    #[Route('/admin/export/job/{jobId}/download', name: 'export.job.download', methods: ['GET'], requirements: ['jobId' => '\d+'])]
    public function download(int $jobId, Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::EXPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $job = $this->repository->findExportJob($jobId);
        if (null !== $job && !$this->jobAccess->maySee($job->getAdminId())) {
            return $this->jobAccess->forbidden();
        }

        if (null === $job || !$this->exportJobFile->isAvailable($job)) {
            $this->flash->add($request, 'error', $this->translator->trans('The file of this export is no longer available. Run the export again.'));

            return new RedirectResponse(null === $job ? $this->urls->generate('export.list') : $this->urls->generate('export.job', ['jobId' => $jobId]));
        }

        return $this->exportJobFile->response($job);
    }
}
