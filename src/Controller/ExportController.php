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
use BackOfficeDefaultTwigBundle\Service\Admin\DataTransferFormOptions;
use BackOfficeDefaultTwigBundle\Service\Admin\ExportLaunchAction;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\DataTransfer\ExportHandler;
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
        private DataTransferRepository $repository,
        private DataTransferFormOptions $formOptions,
        private ExportLaunchAction $launchAction,
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

        return $this->launchAction->launch($export, $request);
    }
}
