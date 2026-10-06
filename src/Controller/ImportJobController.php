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
use BackOfficeDefaultTwigBundle\Service\Admin\DataTransferJobAccess;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\LangQuery;
use Twig\Environment;

/**
 * The page of an import run as a job: how many rows it changed, which it refused.
 */
final readonly class ImportJobController
{
    public function __construct(
        private AdminAccessChecker $access,
        private DataTransferJobAccess $jobAccess,
        private DataTransferRepository $repository,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/import/job/{jobId}', name: 'import.job', methods: ['GET'], requirements: ['jobId' => '\d+'])]
    public function show(int $jobId): Response
    {
        if ($denied = $this->access->check(AdminResources::IMPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        $job = $this->repository->findImportJob($jobId);
        if (null === $job) {
            return new RedirectResponse($this->urls->generate('import.list'));
        }

        if (!$this->jobAccess->maySee($job->getAdminId())) {
            return new Response($this->translator->trans("Sorry, you're not allowed to perform this action"), Response::HTTP_FORBIDDEN);
        }

        $job->getImport()?->setLocale((string) (LangQuery::create()->findOneByByDefault(1)?->getLocale() ?? 'en_US'));

        return new Response($this->twig->render('@BackOfficeDefaultTwig/import/job.html.twig', ['job' => $job]));
    }
}
