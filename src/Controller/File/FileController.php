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

namespace BackOfficeDefaultTwigBundle\Controller\File;

use BackOfficeDefaultTwigBundle\Form\File\DocumentMetadataType;
use BackOfficeDefaultTwigBundle\Form\File\ImageMetadataType;
use BackOfficeDefaultTwigBundle\Form\File\VideoType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFailureMessage;
use BackOfficeDefaultTwigBundle\Service\File\ProductVideoPresenter;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Action\Image as ImageAction;
use Thelia\Core\Event\Document\DocumentEvent;
use Thelia\Core\Event\File\FileCreateOrUpdateEvent;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\File\FileManager;
use Thelia\Core\File\FileModelInterface;
use Thelia\Core\File\LocalizedFileModelInterface;
use Thelia\Core\File\Service\FileDeleteService;
use Thelia\Core\File\Service\FilePositionService;
use Thelia\Core\File\Service\FileProcessorService;
use Thelia\Core\File\Service\FileVisibilityService;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Media\AltTextResolver;
use Thelia\Domain\Media\DTO\ImageUpdateDTO;
use Thelia\Domain\Media\MediaFacade;
use Thelia\Model\LangQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

final class FileController
{
    private const LIST_THUMBNAIL_SIZE = 300;

    /** The alt column of every image table. */
    public const ALT_MAX_LENGTH = 255;

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly FileManager $fileManager,
        private readonly FileProcessorService $fileProcessor,
        private readonly FileDeleteService $fileDeleter,
        private readonly FileVisibilityService $fileVisibility,
        private readonly FilePositionService $filePosition,
        private readonly EventDispatcherInterface $events,
        private readonly TranslatorInterface $translator,
        private readonly AdminResources $resources,
        private readonly FormFactoryInterface $formFactory,
        private readonly EditLocaleResolver $editLocale,
        private readonly TokenProvider $tokens,
        private readonly RequestStack $requestStack,
        private readonly MediaFacade $media,
        private readonly AltTextResolver $altText,
        private readonly LoggerInterface $logger,
        private readonly ProductVideoPresenter $videoPresenter,
    ) {
    }

    private function checkCsrf(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $token = (string) ($request?->request->get('_token') ?? '');
        $this->tokens->checkToken($token);
    }

    #[Route('/admin/image/type/{parentType}/{parentId}/list-ajax', name: 'admin.image.list-ajax', requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function imageList(string $parentType, int $parentId): Response
    {
        return $this->renderList('image', $parentType, $parentId);
    }

    #[Route('/admin/image/type/{parentType}/{parentId}/form-ajax', name: 'admin.image.form-ajax', requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function imageForm(string $parentType, int $parentId): Response
    {
        return $this->renderForm('image', $parentType, $parentId);
    }

    #[Route('/admin/image/type/{parentType}/{parentId}/save-ajax', name: 'admin.image.save-ajax', methods: ['POST'], requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function imageSave(string $parentType, int $parentId, Request $request): Response
    {
        return $this->handleSave('image', $parentType, $parentId, $request);
    }

    #[Route('/admin/image/type/{parentType}/{imageId}/delete', name: 'admin.image.delete', methods: ['POST'], requirements: ['imageId' => '\d+', 'parentType' => '.+'])]
    public function imageDelete(string $parentType, int $imageId): Response
    {
        return $this->handleDelete('image', $parentType, $imageId, TheliaEvents::IMAGE_DELETE);
    }

    #[Route('/admin/image/type/{parentType}/{imageId}/toggle', name: 'admin.image.toggle.process', methods: ['POST'], requirements: ['imageId' => '\d+', 'parentType' => '.+'])]
    public function imageToggleVisibility(string $parentType, int $imageId): Response
    {
        return $this->handleToggle('image', $parentType, $imageId, TheliaEvents::IMAGE_TOGGLE_VISIBILITY);
    }

    #[Route('/admin/image/type/{parentType}/{parentId}/update-position', name: 'admin.image.update-position', methods: ['POST'], requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function imageUpdatePosition(string $parentType, int $parentId, Request $request): Response
    {
        return $this->handlePosition('image', $parentType, $request, TheliaEvents::IMAGE_UPDATE_POSITION);
    }

    /**
     * The images tab of a product shows its images and its videos in one grid, and a
     * drop sends the whole order back: `order[]` entries of the form `image:12` or
     * `video:3`, first entry first. Every medium of the product has to be named, so
     * that a stale grid cannot half-reorder the sheet.
     */
    #[Route('/admin/product/{productId}/media/reorder', name: 'admin.product.media.reorder', methods: ['POST'], requirements: ['productId' => '\d+'])]
    public function reorderProductMedia(int $productId, Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::PRODUCT, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->checkCsrf();

        $order = [];
        foreach ($request->request->all('order') as $entry) {
            if (!\is_string($entry) || 1 !== preg_match('/^(image|video):(\d+)$/', $entry, $matches)) {
                return new JsonResponse(['error' => $this->translator->trans('Malformed media order.')], Response::HTTP_BAD_REQUEST);
            }
            $order[] = ['type' => $matches[1], 'id' => (int) $matches[2]];
        }

        if ([] === $order) {
            return new JsonResponse(['error' => $this->translator->trans('Malformed media order.')], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->media->reorderProductMedia($productId, $order);
        } catch (\InvalidArgumentException $exception) {
            // The grid the admin dragged no longer matches the product: the page
            // reloads on this answer and shows the media as they are.
            $this->logger->notice('Product media reorder refused.', ['exception' => $exception, 'product_id' => $productId]);

            return new JsonResponse(['error' => $this->translator->trans('The media of this product have changed, the page will reload.')], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    // `update-title` stays as an alias of the same method: the inline form now also
    // carries the alternative text and the decorative flag, but links and modules
    // built against the title-only endpoint keep working.
    #[Route('/admin/image/type/{parentType}/{imageId}/update-inline', name: 'admin.image.update-inline', methods: ['POST'], requirements: ['imageId' => '\d+', 'parentType' => '.+'])]
    #[Route('/admin/image/type/{parentType}/{imageId}/update-title', name: 'admin.image.update-title', methods: ['POST'], requirements: ['imageId' => '\d+', 'parentType' => '.+'])]
    public function imageUpdateInline(string $parentType, int $imageId, Request $request): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->checkCsrf();

        $model = $this->loadFileModel('image', $parentType, $imageId);
        if ($model === null) {
            return new JsonResponse(['error' => $this->translator->trans('File not found.')], Response::HTTP_NOT_FOUND);
        }

        // A decorative image disables its alt field, which then posts nothing: null
        // keeps the text the merchant had written, for the day the box is unticked
        // again. The flag itself follows the same rule, so a caller that only sends
        // a title — an old link, a module — does not silently undecorate the image.
        $alt = $request->request->has('alt') ? trim((string) $request->request->get('alt', '')) : null;
        $decorative = $request->request->has('decorative') ? $request->request->getBoolean('decorative') : null;

        if ($alt !== null && mb_strlen($alt) > self::ALT_MAX_LENGTH) {
            return new JsonResponse(
                ['error' => $this->translator->trans('The alternative text cannot be longer than %count characters.', ['%count' => self::ALT_MAX_LENGTH])],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $this->media->updateImage($model, new ImageUpdateDTO(
                locale: $this->editLocale->resolveFromRequest($request)->getLocale() ?? 'en_US',
                title: trim((string) $request->request->get('title', '')),
                alt: $alt,
                decorative: $decorative,
            ));
        } catch (\Throwable $exception) {
            // The reason belongs in the log, not on the screen: a driver or a
            // filesystem message would hand a merchant paths and queries.
            $this->logger->error('Inline image update failed.', ['exception' => $exception, 'image_id' => $imageId]);

            return new JsonResponse(['error' => $this->translator->trans('This image could not be saved.')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['status' => 'ok']);
        }

        return new RedirectResponse($request->headers->get('referer') ?? $this->urls->generate('admin.home'));
    }

    #[Route('/admin/image/type/{parentType}/{imageId}/update', name: 'admin.image.update.view', methods: ['GET'], requirements: ['imageId' => '\d+', 'parentType' => '.+'])]
    public function imageUpdateView(string $parentType, int $imageId, Request $request): Response
    {
        return $this->renderEditPage('image', $parentType, $imageId, $request);
    }

    #[Route('/admin/image/type/{parentType}/{imageId}/update', name: 'admin.image.update.process', methods: ['POST'], requirements: ['imageId' => '\d+', 'parentType' => '.+'])]
    public function imageUpdateProcess(string $parentType, int $imageId, Request $request): Response
    {
        return $this->processEditPage('image', $parentType, $imageId, $request, TheliaEvents::IMAGE_UPDATE);
    }

    #[Route('/admin/document/type/{parentType}/{parentId}/list-ajax', name: 'admin.document.list-ajax', requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function documentList(string $parentType, int $parentId): Response
    {
        return $this->renderList('document', $parentType, $parentId);
    }

    #[Route('/admin/document/type/{parentType}/{parentId}/form-ajax', name: 'admin.document.form-ajax', requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function documentForm(string $parentType, int $parentId): Response
    {
        return $this->renderForm('document', $parentType, $parentId);
    }

    #[Route('/admin/document/type/{parentType}/{parentId}/save-ajax', name: 'admin.document.save-ajax', methods: ['POST'], requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function documentSave(string $parentType, int $parentId, Request $request): Response
    {
        return $this->handleSave('document', $parentType, $parentId, $request);
    }

    #[Route('/admin/document/type/{parentType}/{documentId}/delete', name: 'admin.document.delete', methods: ['POST'], requirements: ['documentId' => '\d+', 'parentType' => '.+'])]
    public function documentDelete(string $parentType, int $documentId): Response
    {
        return $this->handleDelete('document', $parentType, $documentId, TheliaEvents::DOCUMENT_DELETE);
    }

    #[Route('/admin/document/type/{parentType}/{documentId}/toggle', name: 'admin.document.toggle.process', methods: ['POST'], requirements: ['documentId' => '\d+', 'parentType' => '.+'])]
    public function documentToggleVisibility(string $parentType, int $documentId): Response
    {
        return $this->handleToggle('document', $parentType, $documentId, TheliaEvents::DOCUMENT_TOGGLE_VISIBILITY);
    }

    #[Route('/admin/document/type/{parentType}/{parentId}/update-position', name: 'admin.document.update-position', methods: ['POST'], requirements: ['parentId' => '\d+', 'parentType' => '.+'])]
    public function documentUpdatePosition(string $parentType, int $parentId, Request $request): Response
    {
        return $this->handlePosition('document', $parentType, $request, TheliaEvents::DOCUMENT_UPDATE_POSITION);
    }

    #[Route('/admin/document/type/{parentType}/{documentId}/update-title', name: 'admin.document.update-title', methods: ['POST'], requirements: ['documentId' => '\d+', 'parentType' => '.+'])]
    public function documentUpdateTitle(string $parentType, int $documentId, Request $request): Response
    {
        return $this->handleUpdateTitle('document', $parentType, $documentId, $request, TheliaEvents::DOCUMENT_UPDATE);
    }

    #[Route('/admin/document/type/{parentType}/{documentId}/update', name: 'admin.document.update.view', methods: ['GET'], requirements: ['documentId' => '\d+', 'parentType' => '.+'])]
    public function documentUpdateView(string $parentType, int $documentId, Request $request): Response
    {
        return $this->renderEditPage('document', $parentType, $documentId, $request);
    }

    #[Route('/admin/document/type/{parentType}/{documentId}/update', name: 'admin.document.update.process', methods: ['POST'], requirements: ['documentId' => '\d+', 'parentType' => '.+'])]
    public function documentUpdateProcess(string $parentType, int $documentId, Request $request): Response
    {
        return $this->processEditPage('document', $parentType, $documentId, $request, TheliaEvents::DOCUMENT_UPDATE);
    }

    private function renderEditPage(string $kind, string $parentType, int $fileId, Request $request): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $model = $this->loadFileModel($kind, $parentType, $fileId);
        if ($model === null) {
            return new RedirectResponse($this->guessParentUrl($parentType, $kind, null));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $model->setLocale($locale);

        $parentId = (int) $model->getParentId();
        $orderedIds = $this->fetchSiblingIds($kind, $parentType, $parentId);
        [$previousId, $nextId] = $this->neighbourIds($orderedIds, $fileId);

        $editUrl = $this->urls->generate($this->editRouteName($kind), [
            'parentType' => $parentType,
            $kind === 'image' ? 'imageId' : 'documentId' => $fileId,
        ]);

        $form = $this->buildMetadataForm($kind, [
            'id' => $fileId,
            'locale' => $locale,
            'success_url' => $editUrl,
            'title' => (string) $model->getTitle(),
            'chapo' => method_exists($model, 'getChapo') ? (string) $model->getChapo() : '',
            'postscriptum' => method_exists($model, 'getPostscriptum') ? (string) $model->getPostscriptum() : '',
            'description' => method_exists($model, 'getDescription') ? (string) $model->getDescription() : '',
            'visible' => method_exists($model, 'getVisible') ? (bool) $model->getVisible() : true,
        ] + ($kind === 'image' ? [
            'alt' => method_exists($model, 'getAlt') ? (string) $model->getAlt() : '',
            'decorative' => method_exists($model, 'getDecorative') && (bool) $model->getDecorative(),
        ] : []));

        $template = $kind === 'image'
            ? '@BackOfficeDefaultTwig/file/image-edit.html.twig'
            : '@BackOfficeDefaultTwig/file/document-edit.html.twig';

        return new Response($this->twig->render($template, $this->buildEditContext($kind, $parentType, $parentId, $fileId, $model, $form->createView(), $editLang->getId(), $previousId, $nextId)));
    }

    private function processEditPage(string $kind, string $parentType, int $fileId, Request $request, string $eventName): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $model = $this->loadFileModel($kind, $parentType, $fileId);
        if ($model === null) {
            return new RedirectResponse($this->guessParentUrl($parentType, $kind, null));
        }

        $form = $this->buildMetadataForm($kind);
        $form->handleRequest($request);

        // Replacing the file goes straight to the update event, so it has to be checked
        // against the shop upload policy here, like a first upload would be.
        $replacement = $form->has('file') ? $form->get('file')->getData() : null;
        if ($replacement instanceof UploadedFile) {
            try {
                $this->fileProcessor->validateUpload($replacement, $kind);
                $this->fileProcessor->sanitizeUpload($replacement);
            } catch (\Throwable $exception) {
                $form->get('file')->addError(new FormError(AdminFailureMessage::of($exception, $this->translator)));
            }
        }

        $editUrl = $this->urls->generate($this->editRouteName($kind), [
            'parentType' => $parentType,
            $kind === 'image' ? 'imageId' : 'documentId' => $fileId,
        ]);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $editLang = $this->editLocale->resolveFromRequest($request);
            $parentId = (int) $model->getParentId();
            $orderedIds = $this->fetchSiblingIds($kind, $parentType, (int) $parentId);
            [$previousId, $nextId] = $this->neighbourIds($orderedIds, $fileId);

            $model->setLocale($editLang->getLocale() ?? 'en_US');

            $template = $kind === 'image'
                ? '@BackOfficeDefaultTwig/file/image-edit.html.twig'
                : '@BackOfficeDefaultTwig/file/document-edit.html.twig';

            return new Response($this->twig->render($template, $this->buildEditContext($kind, $parentType, $parentId, $fileId, $model, $form->createView(), $editLang->getId(), $previousId, $nextId)), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $form->getData();
        $locale = (string) ($data['locale'] ?? '');
        if ($locale === '') {
            $locale = $this->defaultLocale();
        }

        $oldModel = clone $model;
        $uploaded = $data['file'] ?? null;

        if ($kind === 'image') {
            // Wording, visibility, alternative text and decorative flag all travel
            // through the media facade, so this screen writes them exactly the way
            // the API and the inline grid do.
            $this->media->updateImage($model, new ImageUpdateDTO(
                locale: $locale,
                title: (string) ($data['title'] ?? ''),
                chapo: (string) ($data['chapo'] ?? ''),
                description: (string) ($data['description'] ?? ''),
                postscriptum: (string) ($data['postscriptum'] ?? ''),
                visible: !empty($data['visible']),
                alt: (string) ($data['alt'] ?? ''),
                decorative: !empty($data['decorative']),
            ));
        } else {
            $model->setLocale($locale);
            $model->setTitle((string) ($data['title'] ?? ''));
            $model->setChapo((string) ($data['chapo'] ?? ''));
            $model->setDescription((string) ($data['description'] ?? ''));
            $model->setPostscriptum((string) ($data['postscriptum'] ?? ''));
            $model->setVisible(!empty($data['visible']) ? 1 : 0);
        }

        // Replacing the stored file is the one thing the facade does not carry: it
        // stays on the file event, which is what moves the upload into the library.
        if ($kind !== 'image' || $uploaded instanceof UploadedFile) {
            $event = new FileCreateOrUpdateEvent((int) $model->getParentId());
            $event->setModel($model);
            $event->setOldModel($oldModel);

            if ($uploaded instanceof UploadedFile) {
                $event->setUploadedFile($uploaded);
            }

            $this->events->dispatch($event, $eventName);
        }

        $saveMode = (string) $request->request->get('save_mode', 'stay');
        if ($saveMode === 'close') {
            return new RedirectResponse($this->guessParentUrl($parentType, $kind, (int) $model->getParentId()));
        }

        return new RedirectResponse($editUrl);
    }

    private function loadFileModel(string $kind, string $parentType, int $fileId): ?FileModelInterface
    {
        $modelInstance = $this->fileManager->getModelInstance($kind, $parentType);
        $model = $modelInstance->getQueryInstance()->findPk($fileId);

        return $model instanceof FileModelInterface ? $model : null;
    }

    private function buildMetadataForm(string $kind, ?array $data = null)
    {
        $type = $kind === 'image' ? ImageMetadataType::class : DocumentMetadataType::class;
        $name = $kind === 'image' ? 'thelia_image_modification' : 'thelia_document_modification';

        return $this->formFactory->createNamed($name, $type, $data);
    }

    /**
     * @param list<int> $orderedIds
     *
     * @return array{0: int, 1: int} [previousId, nextId] - 0 means none
     */
    private function neighbourIds(array $orderedIds, int $currentId): array
    {
        $index = array_search($currentId, $orderedIds, true);
        if ($index === false) {
            return [0, 0];
        }
        $previous = $index > 0 ? $orderedIds[$index - 1] : 0;
        $next = $index < (\count($orderedIds) - 1) ? $orderedIds[$index + 1] : 0;

        return [$previous, $next];
    }

    /**
     * @return list<int>
     */
    private function fetchSiblingIds(string $kind, string $parentType, int $parentId): array
    {
        $modelInstance = $this->fileManager->getModelInstance($kind, $parentType);
        $query = $modelInstance->getQueryInstance();
        $filterMethod = 'filterBy'.ucfirst($parentType).'Id';
        if (method_exists($query, $filterMethod)) {
            $query->{$filterMethod}($parentId);
        }

        $ids = [];
        if (method_exists($query, 'orderByPosition')) {
            $query->orderByPosition();
        }
        foreach ($query->find() as $record) {
            $ids[] = (int) $record->getId();
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEditContext(string $kind, string $parentType, int $parentId, int $fileId, FileModelInterface $model, $formView, int $editLanguageId, int $previousId, int $nextId): array
    {
        $editRoute = $this->editRouteName($kind);
        $previousUrl = $previousId > 0 ? $this->urls->generate($editRoute, ['parentType' => $parentType, $kind === 'image' ? 'imageId' : 'documentId' => $previousId]) : '';
        $nextUrl = $nextId > 0 ? $this->urls->generate($editRoute, ['parentType' => $parentType, $kind === 'image' ? 'imageId' : 'documentId' => $nextId]) : '';

        $closeUrl = $this->guessParentUrl($parentType, $kind, $parentId);
        $fileName = (string) $model->getFile();

        return [
            'kind' => $kind,
            // A translated image file: the language being edited shows the file of the
            // default language until one is uploaded for it.
            'file_inherited' => $model instanceof LocalizedFileModelInterface && null === $model->getOwnFile() && $fileName !== '',
            'parent_type' => $parentType,
            'parent_id' => $parentId,
            'file_id' => $fileId,
            'file_name' => $fileName,
            'file_title' => (string) $model->getTitle(),
            'file_alt' => $this->altText->resolve(
                method_exists($model, 'getAlt') ? (string) $model->getAlt() : null,
                method_exists($model, 'getDecorative') && (bool) $model->getDecorative(),
                (string) $model->getTitle(),
            ),
            'file_url' => $this->fileUrl($kind, $parentType, $model),
            'form' => $formView,
            'edit_language_id' => $editLanguageId,
            'previous_url' => $previousUrl,
            'next_url' => $nextUrl,
            'close_url' => $closeUrl,
            'edit_url' => $this->urls->generate($editRoute, ['parentType' => $parentType, $kind === 'image' ? 'imageId' : 'documentId' => $fileId]),
        ];
    }

    private function editRouteName(string $kind): string
    {
        return $kind === 'image' ? 'admin.image.update.view' : 'admin.document.update.view';
    }

    private function guessParentUrl(string $parentType, string $kind, ?int $parentId): string
    {
        $parentEditRoute = $this->guessParentEditRoute($parentType);
        if ($parentEditRoute === null) {
            return $this->urls->generate('admin.home');
        }

        $params = ['current_tab' => $kind === 'image' ? 'images' : 'documents'];
        if ($parentId !== null && $parentId > 0) {
            $params[$parentType.'_id'] = $parentId;
        }

        return $this->urls->generate($parentEditRoute, $params);
    }

    private function renderList(string $kind, string $parentType, int $parentId): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::VIEW)) {
            return $denied;
        }

        $items = $this->fetchItems($kind, $parentType, $parentId);
        $canUpdate = $this->access->check($resource, [], AccessManager::UPDATE) === null;
        $canDelete = $this->access->check($resource, [], AccessManager::DELETE) === null;

        return new Response($this->twig->render('@BackOfficeDefaultTwig/file/_list.html.twig', [
            'kind' => $kind,
            'parent_type' => $parentType,
            'parent_id' => $parentId,
            'items' => $items,
            'can_update' => $canUpdate,
            'can_delete' => $canDelete,
            'urls' => $this->urlsFor($kind, $parentType),
            'edit_locale' => $this->currentEditLocale(),
            'with_videos' => $this->showsVideos($kind, $parentType),
        ]));
    }

    private function renderForm(string $kind, string $parentType, int $parentId): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::UPDATE)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/file/_form.html.twig', [
            'kind' => $kind,
            'parent_type' => $parentType,
            'parent_id' => $parentId,
            'urls' => $this->urlsFor($kind, $parentType),
            'video_form' => $this->showsVideos($kind, $parentType)
                ? $this->formFactory->createNamed(VideoType::NAME, VideoType::class, ['visible' => true])->createView()
                : null,
        ]));
    }

    /**
     * Only a product has videos, and they sit with its images: one grid, one order.
     */
    private function showsVideos(string $kind, string $parentType): bool
    {
        return 'image' === $kind && 'product' === $parentType;
    }

    private function handleSave(string $kind, string $parentType, int $parentId, Request $request): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->checkCsrf();

        $uploadedFile = $request->files->get('file');
        if ($uploadedFile === null) {
            return new JsonResponse(['error' => $this->translator->trans('No file uploaded.')], Response::HTTP_BAD_REQUEST);
        }

        try {
            // No constraint argument: the shop upload policy for this object type applies.
            $this->fileProcessor->processFile($this->events, $uploadedFile, $parentId, $parentType, $kind);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => AdminFailureMessage::of($exception, $this->translator)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleDelete(string $kind, string $parentType, int $fileId, string $eventName): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::DELETE)) {
            return $denied;
        }

        $this->checkCsrf();

        try {
            $this->fileDeleter->deleteFile($this->events, $fileId, $parentType, $kind, $eventName);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => AdminFailureMessage::of($exception, $this->translator)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleToggle(string $kind, string $parentType, int $fileId, string $eventName): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->checkCsrf();

        try {
            $this->fileVisibility->toggleFileVisibility($this->events, $fileId, $parentType, $kind, $eventName);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => AdminFailureMessage::of($exception, $this->translator)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function handlePosition(string $kind, string $parentType, Request $request, string $eventName): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->checkCsrf();

        $fileId = (int) $request->request->get('file_id', 0);
        $position = (int) $request->request->get('position', 0);
        if ($fileId === 0 || $position === 0) {
            return new JsonResponse(['error' => $this->translator->trans('Missing file_id or position.')], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->filePosition->updateFilePosition($this->events, $parentType, $fileId, $kind, $eventName, $position);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => AdminFailureMessage::of($exception, $this->translator)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleUpdateTitle(string $kind, string $parentType, int $fileId, Request $request, string $eventName): Response
    {
        $resource = $this->resources->getResource($parentType);
        if ($denied = $this->access->check($resource, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->checkCsrf();

        $title = trim((string) $request->request->get('title', ''));
        $modelInstance = $this->fileManager->getModelInstance($kind, $parentType);
        $model = $modelInstance->getQueryInstance()->findPk($fileId);
        if ($model === null) {
            return new JsonResponse(['error' => $this->translator->trans('File not found.')], Response::HTTP_NOT_FOUND);
        }

        try {
            $model->setLocale($this->defaultLocale());
            $model->setTitle($title);
            $model->save();
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => AdminFailureMessage::of($exception, $this->translator)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new RedirectResponse($request->headers->get('referer') ?? $this->urls->generate('admin.home'));
    }

    private function guessParentEditRoute(string $parentType): ?string
    {
        return match ($parentType) {
            'product' => 'admin.products.update',
            'category' => 'admin.categories.update',
            'folder' => 'admin.folders.update',
            'content' => 'admin.content.update',
            'brand' => 'admin.brand.update',
            default => null,
        };
    }

    /**
     * The entries of the grid, by position. On the images tab of a product the videos
     * are in the list too, told apart by `type`; two media on the same position keep
     * the image first, the way the front office shows them.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchItems(string $kind, string $parentType, int $parentId): array
    {
        $model = $this->fileManager->getModelInstance($kind, $parentType);
        // The grid is edited in the language the admin is translating in, so what it
        // shows and what the inline form writes are always the same wording.
        $locale = $this->currentEditLocale();

        $query = $model->getQueryInstance();
        $filterMethod = 'filterBy'.ucfirst($parentType).'Id';
        if (method_exists($query, $filterMethod)) {
            $query->{$filterMethod}($parentId);
        }
        if (method_exists($query, 'orderByPosition')) {
            $query->orderByPosition();
        }
        $records = $query->find();

        $items = [];
        foreach ($records as $record) {
            if (!method_exists($record, 'setLocale')) {
                continue;
            }
            $record->setLocale($locale);
            $file = (string) $record->getFile();
            $title = (string) $record->getTitle();
            $alt = method_exists($record, 'getAlt') ? (string) $record->getAlt() : '';
            $decorative = method_exists($record, 'getDecorative') && (bool) $record->getDecorative();
            $items[] = [
                'type' => $kind,
                'id' => (int) $record->getId(),
                'title' => $title,
                'alt' => $alt,
                'decorative' => $decorative,
                'resolved_alt' => $this->altText->resolve($alt, $decorative, $title),
                'file' => $file,
                'visible' => (bool) (method_exists($record, 'getVisible') ? $record->getVisible() : true),
                'position' => (int) (method_exists($record, 'getPosition') ? $record->getPosition() : 0),
                'url' => $this->fileUrl($kind, $parentType, $record),
            ];
        }

        if (!$this->showsVideos($kind, $parentType)) {
            return $items;
        }

        foreach ($this->videoPresenter->items($parentId, $locale) as $video) {
            $items[] = $video + ['type' => 'video', 'decorative' => false, 'file' => '', 'url' => $video['thumbnail_url']];
        }

        usort($items, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return $items;
    }

    /**
     * @return array<string, string>
     */
    private function urlsFor(string $kind, string $parentType): array
    {
        return $kind === 'image'
            ? [
                'save' => 'admin.image.save-ajax',
                'position' => 'admin.image.update-position',
                'toggle' => 'admin.image.toggle.process',
                'delete' => 'admin.image.delete',
                'update' => 'admin.image.update.view',
                'update_title' => 'admin.image.update-inline',
                'list' => 'admin.image.list-ajax',
            ]
            : [
                'save' => 'admin.document.save-ajax',
                'position' => 'admin.document.update-position',
                'toggle' => 'admin.document.toggle.process',
                'delete' => 'admin.document.delete',
                'update' => 'admin.document.update.view',
                'update_title' => 'admin.document.update-title',
                'list' => 'admin.document.list-ajax',
            ];
    }

    private function fileUrl(string $kind, string $parentType, object $record): string
    {
        if (!method_exists($record, 'getUploadDir')) {
            return '';
        }

        $file = method_exists($record, 'getFile') ? (string) $record->getFile() : '';
        if ($file === '') {
            return '';
        }

        $sourcePath = $record->getUploadDir().\DIRECTORY_SEPARATOR.$file;

        try {
            if ($kind === 'image') {
                $event = new ImageEvent();
                $event->setSourceFilepath($sourcePath);
                $event->setCacheSubdirectory($parentType);
                $event->setWidth(self::LIST_THUMBNAIL_SIZE);
                $event->setHeight(self::LIST_THUMBNAIL_SIZE);
                $event->setResizeMode((string) ImageAction::EXACT_RATIO_WITH_BORDERS);
                $this->events->dispatch($event, TheliaEvents::IMAGE_PROCESS);

                return (string) $event->getFileUrl();
            }

            $event = new DocumentEvent();
            $event->setSourceFilepath($sourcePath);
            $event->setCacheSubdirectory($parentType);
            $this->events->dispatch($event, TheliaEvents::DOCUMENT_PROCESS);

            return $event->getDocumentUrl();
        } catch (\Throwable) {
            return '';
        }
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }

    /**
     * The edition language of the admin, for fragments rendered without a request
     * of their own (the grid is loaded by XHR, the switcher lives on the host page).
     */
    private function currentEditLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return $this->defaultLocale();
        }

        return $this->editLocale->resolveFromRequest($request)->getLocale() ?? $this->defaultLocale();
    }
}
