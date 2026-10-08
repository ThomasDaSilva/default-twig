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
use BackOfficeDefaultTwigBundle\Service\Admin\UpdatePositionEventFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Twig\Environment;

/**
 * Tools > Exports and Tools > Imports: the lists, and the order they are shown in.
 */
final readonly class DataTransferListController
{
    public function __construct(
        private AdminAccessChecker $access,
        private AdminFormAction $action,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private DataTransferRepository $repository,
        private UpdatePositionEventFactory $positions,
    ) {
    }

    #[Route('/admin/export', name: 'export.list', methods: ['GET'])]
    public function exports(): Response
    {
        if ($denied = $this->access->check(AdminResources::EXPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/export/list.html.twig', [
            'categories' => $this->repository->findExportCatalogue($this->repository->defaultLocale()),
            'position_url' => $this->urls->generate('export.position'),
        ]));
    }

    #[Route('/admin/import', name: 'import.list', methods: ['GET'])]
    public function imports(): Response
    {
        if ($denied = $this->access->check(AdminResources::IMPORT, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/import/list.html.twig', [
            'categories' => $this->repository->findImportCatalogue($this->repository->defaultLocale()),
            'position_url' => $this->urls->generate('import.position'),
        ]));
    }

    #[Route('/admin/export/position', name: 'export.position', methods: ['POST'])]
    public function exportPosition(Request $request): Response
    {
        return $this->reorder($request, AdminResources::EXPORT, 'export_id', TheliaEvents::EXPORT_CHANGE_POSITION, 'Export reorder', 'export.list');
    }

    #[Route('/admin/export/position/category', name: 'export.category.position', methods: ['POST'])]
    public function exportCategoryPosition(Request $request): Response
    {
        return $this->reorder($request, AdminResources::EXPORT, 'export_category_id', TheliaEvents::EXPORT_CATEGORY_CHANGE_POSITION, 'Export category reorder', 'export.list');
    }

    #[Route('/admin/import/position', name: 'import.position', methods: ['POST'])]
    public function importPosition(Request $request): Response
    {
        return $this->reorder($request, AdminResources::IMPORT, 'import_id', TheliaEvents::IMPORT_CHANGE_POSITION, 'Import reorder', 'import.list');
    }

    #[Route('/admin/import/position/category', name: 'import.category.position', methods: ['POST'])]
    public function importCategoryPosition(Request $request): Response
    {
        return $this->reorder($request, AdminResources::IMPORT, 'import_category_id', TheliaEvents::IMPORT_CATEGORY_CHANGE_POSITION, 'Import category reorder', 'import.list');
    }

    private function reorder(Request $request, string $resource, string $idField, string $eventName, string $actionLabel, string $successRoute): Response
    {
        return $this->action->tokenAction(
            resource: $resource,
            access: AccessManager::UPDATE,
            request: $request,
            event: $this->positions->fromRequest($request, $idField),
            eventName: $eventName,
            actionLabel: $actionLabel,
            successRoute: $successRoute,
        );
    }
}
