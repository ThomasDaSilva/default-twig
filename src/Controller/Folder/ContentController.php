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

namespace BackOfficeDefaultTwigBundle\Controller\Folder;

use BackOfficeDefaultTwigBundle\Form\Content\ContentSeoType;
use BackOfficeDefaultTwigBundle\Form\Content\ContentType;
use BackOfficeDefaultTwigBundle\Repository\FolderRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\Service\Listing\FolderListQuery;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Event\Content\ContentAddFolderEvent;
use Thelia\Core\Event\Content\ContentCreateEvent;
use Thelia\Core\Event\Content\ContentDeleteEvent;
use Thelia\Core\Event\Content\ContentRemoveFolderEvent;
use Thelia\Core\Event\Content\ContentToggleVisibilityEvent;
use Thelia\Core\Event\Content\ContentUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Event\UpdateSeoEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Content;
use Thelia\Model\ContentQuery;
use Thelia\Model\LangQuery;
use Twig\Environment;

#[Route('/admin/content', name: 'admin.content.')]
final class ContentController
{
    private const RESOURCE = AdminResources::CONTENT;
    private const LIST_ROUTE = 'admin.folders.default';
    private const EDIT_ROUTE = 'admin.content.update';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/content/edit.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly EditLocaleResolver $editLocale,
        private readonly FolderRepository $folderRepository,
    ) {
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_content_creation', ContentType::class, [
            'locale' => $request->getLocale(),
            'visible' => true,
        ], []);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::CONTENT_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Content creation',
            successRoute: self::EDIT_ROUTE,
            successParametersResolver: static fn (ContentCreateEvent $event): array => ['content_id' => (int) $event->getContent()?->getId()],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
        );
    }

    #[Route('/update/{content_id}', name: 'update', methods: ['GET'], requirements: ['content_id' => '\d+'])]
    public function updateView(int $content_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $content = ContentQuery::create()->findPk($content_id);
        if ($content === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $content->setLocale($locale);

        $uiLocale = $request->getLocale();

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'content' => $content,
            'breadcrumb_path' => $this->folderRepository->buildBreadcrumbPath($this->folderRepository->find($content->getDefaultFolderId(), $uiLocale), $uiLocale),
            'preview_url' => $content->getUrl($locale),
            'form' => $this->buildUpdateForm($content, $locale)->createView(),
            'seo_form' => $this->buildSeoForm($content, $locale)->createView(),
            'folders' => $this->folderChoices($locale),
            'additional_folders' => $this->additionalFolderRows($content, $locale),
            'available_additional_folders' => $this->availableAdditionalFolders($content, $locale),
            'current_tab' => (string) $request->query->get('current_tab', 'general'),
            'edit_language_id' => (int) $editLang->getId(),
        ]));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function processUpdate(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_content_modification', ContentType::class, null, [
            'include_id' => true,
            'include_description' => true,
        ]);

        $contentId = (int) $request->request->get('content_id', 0);
        $closeAfterSave = $request->request->get('save_mode') === 'close';

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::CONTENT_UPDATE,
            eventFactory: $this->updateEvent(...),
            actionLabel: 'Content update',
            successRoute: $closeAfterSave ? self::LIST_ROUTE : self::EDIT_ROUTE,
            successParameters: $closeAfterSave ? [] : ['content_id' => $contentId],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['content_id' => $contentId])),
            successParametersResolver: $closeAfterSave ? static fn (ContentUpdateEvent $event): array => ['folder_id' => (int) $event->getDefaultFolder()] : null,
        );
    }

    #[Route('/seo/save', name: 'seo.save', methods: ['POST'])]
    public function processSeo(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_content_seo', ContentSeoType::class, null, [
        ]);

        $contentId = (int) $request->request->get('content_id', $request->request->get('id', 0));
        if ($contentId === 0 && $request->request->has('thelia_content_seo')) {
            $raw = (array) $request->request->all('thelia_content_seo');
            $contentId = (int) ($raw['id'] ?? 0);
        }

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::CONTENT_UPDATE_SEO,
            eventFactory: $this->seoEvent(...),
            actionLabel: 'Content SEO update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['content_id' => $contentId, 'current_tab' => 'seo'],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['content_id' => $contentId, 'current_tab' => 'seo'])),
        );
    }

    #[Route('/toggle-online', name: 'toggle-online', methods: ['POST'])]
    public function toggleOnline(Request $request): Response
    {
        $content = ContentQuery::create()->findPk((int) ($request->query->get('content_id') ?? $request->request->get('content_id', 0)));
        if ($content === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $folderId = (int) ($request->query->get('folder_id') ?? $content->getDefaultFolderId());

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new ContentToggleVisibilityEvent($content),
            eventName: TheliaEvents::CONTENT_TOGGLE_VISIBILITY,
            actionLabel: 'Content visibility',
            successRoute: self::LIST_ROUTE,
            successParameters: FolderListQuery::fromRequest($request)->toListParams($folderId),
        );
    }

    #[Route('/update-position', name: 'update-position', methods: ['POST'])]
    public function updatePosition(Request $request): Response
    {
        $event = new UpdatePositionEvent(
            (int) ($request->query->get('content_id') ?? $request->request->get('content_id', 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
            (int) ($request->query->get('folder_id') ?? $request->request->get('folder_id', 0)),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::CONTENT_UPDATE_POSITION,
            actionLabel: 'Content reorder',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $contentId = (int) ($request->query->get('content_id') ?? $request->request->get('content_id', 0));
        $folderId = (int) ($request->query->get('folder_id') ?? ContentQuery::create()->findPk($contentId)?->getDefaultFolderId());

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new ContentDeleteEvent($contentId),
            eventName: TheliaEvents::CONTENT_DELETE,
            actionLabel: 'Content deletion',
            successRoute: self::LIST_ROUTE,
            successParameters: FolderListQuery::fromRequest($request)->toListParams($folderId),
        );
    }

    #[Route('/additional-folder/add', name: 'additional-folder.add', methods: ['POST'])]
    public function addAdditionalFolder(Request $request): Response
    {
        $contentId = (int) $request->request->get('content_id', 0);
        $folderId = (int) $request->request->get('additional_folder_id', 0);

        $content = ContentQuery::create()->findPk($contentId);
        if ($content === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new ContentAddFolderEvent($content, $folderId),
            eventName: TheliaEvents::CONTENT_ADD_FOLDER,
            actionLabel: 'Content additional folder add',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['content_id' => $contentId],
        );
    }

    #[Route('/additional-folder/delete', name: 'additional-folder.delete', methods: ['POST'])]
    public function deleteAdditionalFolder(Request $request): Response
    {
        $contentId = (int) ($request->query->get('content_id') ?? $request->request->get('content_id', 0));
        $folderId = (int) ($request->query->get('additional_folder_id') ?? $request->request->get('additional_folder_id', 0));

        $content = ContentQuery::create()->findPk($contentId);
        if ($content === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new ContentRemoveFolderEvent($content, $folderId),
            eventName: TheliaEvents::CONTENT_REMOVE_FOLDER,
            actionLabel: 'Content additional folder remove',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['content_id' => $contentId],
        );
    }

    private function createEvent(FormInterface $validated): ContentCreateEvent
    {
        $data = $validated->getData() ?? [];
        $event = new ContentCreateEvent();
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setDefaultFolder((int) ($data['default_folder'] ?? 0))
            ->setVisible((bool) ($data['visible'] ?? false));

        return $event;
    }

    private function updateEvent(FormInterface $validated): ContentUpdateEvent
    {
        $data = $validated->getData() ?? [];
        $event = new ContentUpdateEvent((int) ($data['id'] ?? 0));
        // Non-fluent: ContentCreateEvent::setVisible() is typed ": self", which would
        // downgrade the chain type and hide ContentUpdateEvent::setChapo() et al.
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()));
        $event->setTitle((string) ($data['title'] ?? ''));
        $event->setDefaultFolder((int) ($data['default_folder'] ?? 0));
        $event->setVisible((bool) ($data['visible'] ?? false));
        $event->setChapo($this->stringOrNull($data['chapo'] ?? null));
        $event->setDescription($this->stringOrNull($data['description'] ?? null));
        $event->setPostscriptum($this->stringOrNull($data['postscriptum'] ?? null));

        return $event;
    }

    private function buildUpdateForm(Content $content, string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_content_modification', ContentType::class, [
            'id' => $content->getId(),
            'locale' => $locale,
            'title' => $content->getTitle(),
            'default_folder' => $content->getDefaultFolderId(),
            'visible' => (bool) $content->getVisible(),
            'chapo' => $content->getChapo(),
            'description' => $content->getDescription(),
            'postscriptum' => $content->getPostscriptum(),
        ], [
            'include_id' => true,
            'include_description' => true,
        ]);
    }

    private function buildSeoForm(Content $content, string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_content_seo', ContentSeoType::class, [
            'id' => $content->getId(),
            'locale' => $locale,
            'url' => $content->getRewrittenUrl($locale),
            'meta_title' => $content->getMetaTitle(),
            'meta_description' => $content->getMetaDescription(),
            'meta_keywords' => $content->getMetaKeywords(),
        ], [
        ]);
    }

    private function seoEvent(FormInterface $validated): UpdateSeoEvent
    {
        $data = $validated->getData() ?? [];

        return new UpdateSeoEvent(
            (int) ($data['id'] ?? 0),
            (string) ($data['locale'] ?? $this->defaultLocale()),
            $this->stringOrNull($data['url'] ?? null),
            $this->stringOrNull($data['meta_title'] ?? null),
            $this->stringOrNull($data['meta_description'] ?? null),
            $this->stringOrNull($data['meta_keywords'] ?? null),
        );
    }

    /** @return list<array{id: int, title: string, is_default: bool, delete_url: string}> */
    private function additionalFolderRows(Content $content, string $locale): array
    {
        $defaultFolderId = (int) $content->getDefaultFolderId();
        $folders = $content->getFolders();
        $rows = [];
        foreach ($folders as $folder) {
            $folderId = (int) $folder->getId();
            $folder->setLocale($locale);
            $rows[] = [
                'id' => $folderId,
                'title' => (string) $folder->getTitle(),
                'is_default' => $folderId === $defaultFolderId,
                'delete_url' => $this->urls->generate('admin.content.additional-folder.delete', [
                    'content_id' => (int) $content->getId(),
                    'additional_folder_id' => $folderId,
                ]),
            ];
        }

        return $rows;
    }

    /** @return list<array{id: int, title: string}> */
    private function availableAdditionalFolders(Content $content, string $locale): array
    {
        $taken = [];
        foreach ($content->getFolders() as $folder) {
            $taken[(int) $folder->getId()] = true;
        }

        $rows = [];
        foreach ($this->folderRepository->findAllOrderedByPosition($locale) as $folder) {
            $id = (int) $folder->getId();
            if (isset($taken[$id])) {
                continue;
            }
            $rows[] = ['id' => $id, 'title' => (string) $folder->getTitle()];
        }

        return $rows;
    }

    /** @return list<array{id: int, title: string}> */
    private function folderChoices(string $locale): array
    {
        $rows = [];
        foreach ($this->folderRepository->findAllOrderedByPosition($locale) as $folder) {
            $rows[] = ['id' => (int) $folder->getId(), 'title' => (string) $folder->getTitle()];
        }

        return $rows;
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!\is_scalar($value) && $value !== null) {
            return null;
        }
        $cast = (string) $value;

        return $cast === '' ? null : $cast;
    }
}
