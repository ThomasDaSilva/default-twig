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
use BackOfficeDefaultTwigBundle\Service\Admin\DataTransferFormOptions;
use BackOfficeDefaultTwigBundle\Service\Admin\ImportLaunchAction;
use BackOfficeDefaultTwigBundle\Service\Admin\ImportTemplateBuilder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\DataTransfer\ImportHandler;
use Twig\Environment;

/**
 * Tools > Imports: the form of an import, its column template, and its launch as a
 * job.
 */
final readonly class ImportController
{
    public function __construct(
        private AdminAccessChecker $access,
        private Environment $twig,
        private ImportHandler $importHandler,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private DataTransferRepository $repository,
        private ImportTemplateBuilder $importTemplateBuilder,
        private DataTransferFormOptions $formOptions,
        private ImportLaunchAction $launchAction,
        private AdminFlash $flash,
    ) {
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
            // Running an import needs the right to change the imports.
            'can_import' => $this->access->can(AdminResources::IMPORT, AccessManager::UPDATE),
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

        return $this->launchAction->launch($import, $request);
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
            $this->flash->add($request, 'error', $this->translator->trans('The CSV format is not available.'));

            return new RedirectResponse($this->urls->generate('import.view', ['id' => $id]));
        }

        if ($this->importTemplateBuilder->columnsFor($import) === []) {
            $this->flash->add($request, 'error', $this->translator->trans('No column template is available for this import.'));

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
}
