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

use BackOfficeDefaultTwigBundle\Form\Content\ContentType;
use BackOfficeDefaultTwigBundle\Form\Folder\FolderSeoType;
use BackOfficeDefaultTwigBundle\Form\Folder\FolderType;
use BackOfficeDefaultTwigBundle\Repository\ContentRepository;
use BackOfficeDefaultTwigBundle\Repository\FolderRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\Service\Listing\FolderListQuery;
use BackOfficeDefaultTwigBundle\Service\Listing\ListingThumbnailPresenter;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Folder\FolderCreateEvent;
use Thelia\Core\Event\Folder\FolderDeleteEvent;
use Thelia\Core\Event\Folder\FolderToggleVisibilityEvent;
use Thelia\Core\Event\Folder\FolderUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Event\UpdateSeoEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Content;
use Thelia\Model\Folder;
use Thelia\Model\FolderQuery;
use Thelia\Model\LangQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin/folders', name: 'admin.folders.')]
final class FolderController
{
    private const RESOURCE = AdminResources::FOLDER;
    private const LIST_ROUTE = 'admin.folders.default';
    private const EDIT_ROUTE = 'admin.folders.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/folder/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/folder/edit.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly EditLocaleResolver $editLocale,
        private readonly FolderRepository $folderRepository,
        private readonly ContentRepository $contentRepository,
        private readonly ListingThumbnailPresenter $thumbnails,
    ) {
    }

    private const CONTENT_PAGE_SIZE = 20;

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $parentId = (int) $request->query->get('folder_id', 0);

        return new Response($this->twig->render(self::LIST_TEMPLATE, $this->buildListContext($parentId, $request)));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_folder_creation', FolderType::class, [
            'locale' => $request->getLocale(),
            'visible' => true,
        ], []);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::FOLDER_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Folder creation',
            successRoute: self::EDIT_ROUTE,
            successParametersResolver: static fn (FolderCreateEvent $event): array => ['folder_id' => (int) $event->getFolder()?->getId()],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
        );
    }

    #[Route('/update/{folder_id}', name: 'update', methods: ['GET'], requirements: ['folder_id' => '\d+'])]
    public function updateView(int $folder_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $folder = FolderQuery::create()->findPk($folder_id);
        if ($folder === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $folder->setLocale($locale);

        $uiLocale = $request->getLocale();

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'folder' => $folder,
            'breadcrumb_path' => $this->folderRepository->buildBreadcrumbPath($this->folderRepository->find((int) $folder->getParent(), $uiLocale), $uiLocale),
            'preview_url' => $folder->getUrl($locale),
            'form' => $this->buildUpdateForm($folder, $locale)->createView(),
            'seo_form' => $this->buildSeoForm($folder, $locale)->createView(),
            'current_tab' => (string) $request->query->get('current_tab', 'general'),
            'edit_language_id' => (int) $editLang->getId(),
        ]));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function processUpdate(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_folder_modification', FolderType::class, null, [
            'include_id' => true,
            'include_description' => true,
        ]);

        $folderId = (int) $request->request->get('folder_id', 0);
        $closeAfterSave = $request->request->get('save_mode') === 'close';

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::FOLDER_UPDATE,
            eventFactory: $this->updateEvent(...),
            actionLabel: 'Folder update',
            successRoute: $closeAfterSave ? self::LIST_ROUTE : self::EDIT_ROUTE,
            successParameters: $closeAfterSave ? [] : ['folder_id' => $folderId],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['folder_id' => $folderId])),
            successParametersResolver: $closeAfterSave ? static fn (FolderUpdateEvent $event): array => ['folder_id' => (int) $event->getParent()] : null,
        );
    }

    #[Route('/seo/save', name: 'seo.save', methods: ['POST'])]
    public function processSeo(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_folder_seo', FolderSeoType::class, null, [
        ]);

        $folderId = (int) $request->request->get('folder_id', $request->request->get('id', 0));
        if ($folderId === 0 && $request->request->has('thelia_folder_seo')) {
            $raw = (array) $request->request->all('thelia_folder_seo');
            $folderId = (int) ($raw['id'] ?? 0);
        }

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::FOLDER_UPDATE_SEO,
            eventFactory: $this->seoEvent(...),
            actionLabel: 'Folder SEO update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['folder_id' => $folderId, 'current_tab' => 'seo'],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['folder_id' => $folderId, 'current_tab' => 'seo'])),
        );
    }

    #[Route('/toggle-online', name: 'toggle-online', methods: ['POST'])]
    public function toggleOnline(Request $request): Response
    {
        $folder = FolderQuery::create()->findPk((int) ($request->query->get('folder_id') ?? $request->request->get('folder_id', 0)));
        if ($folder === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new FolderToggleVisibilityEvent($folder),
            eventName: TheliaEvents::FOLDER_TOGGLE_VISIBILITY,
            actionLabel: 'Folder visibility',
            successRoute: self::LIST_ROUTE,
            successParameters: FolderListQuery::fromRequest($request)->toListParams((int) $folder->getParent()),
        );
    }

    #[Route('/update-position', name: 'update-position', methods: ['POST'])]
    public function updatePosition(Request $request): Response
    {
        $event = new UpdatePositionEvent(
            (int) ($request->query->get('folder_id') ?? $request->request->get('folder_id', 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::FOLDER_UPDATE_POSITION,
            actionLabel: 'Folder reorder',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $folderId = (int) ($request->query->get('folder_id') ?? $request->request->get('folder_id', 0));
        $parentId = (int) FolderQuery::create()->findPk($folderId)?->getParent();

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new FolderDeleteEvent($folderId),
            eventName: TheliaEvents::FOLDER_DELETE,
            actionLabel: 'Folder deletion',
            successRoute: self::LIST_ROUTE,
            successParameters: FolderListQuery::fromRequest($request)->toListParams($parentId),
        );
    }

    private function createEvent(FormInterface $validated): FolderCreateEvent
    {
        $data = $validated->getData() ?? [];
        $event = new FolderCreateEvent();
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setParent((int) ($data['parent'] ?? 0))
            ->setVisible((bool) ($data['visible'] ?? false));

        return $event;
    }

    private function updateEvent(FormInterface $validated): FolderUpdateEvent
    {
        $data = $validated->getData() ?? [];
        $event = new FolderUpdateEvent((int) ($data['id'] ?? 0));
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setParent((int) ($data['parent'] ?? 0))
            ->setVisible((bool) ($data['visible'] ?? false))
            ->setChapo($this->stringOrNull($data['chapo'] ?? null))
            ->setDescription($this->stringOrNull($data['description'] ?? null))
            ->setPostscriptum($this->stringOrNull($data['postscriptum'] ?? null));

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildListContext(int $parentId, ?Request $request = null): array
    {
        $locale = $this->defaultLocale();
        $listQuery = $request !== null
            ? FolderListQuery::fromRequest($request)
            : new FolderListQuery(new ListSort(FolderListQuery::DEFAULT_SORT, 'asc'), 1);
        $sort = $listQuery->sort;
        $rows = [];
        foreach ($this->folderRepository->findChildrenSorted($parentId, $locale, $sort->field, $sort->direction) as $folder) {
            $rows[] = $this->folderToRow($folder, $listQuery);
        }

        $createForm = $this->formFactory->createNamed('thelia_folder_creation', FolderType::class, [
            'locale' => $locale,
            'parent' => $parentId,
        ], []);

        $contentCreateForm = $this->formFactory->createNamed('thelia_content_creation', ContentType::class, [
            'locale' => $locale,
            'default_folder' => $parentId,
            'visible' => true,
        ], []);

        return [
            'rows' => $rows,
            'parent_id' => $parentId,
            'breadcrumb_path' => $this->folderRepository->buildBreadcrumbPath($this->folderRepository->find($parentId, $locale), $locale),
            'create_form' => $createForm->createView(),
            'content_create_form' => $contentCreateForm->createView(),
            'content_update_position_url' => $this->urls->generate('admin.content.update-position', ['folder_id' => $parentId]),
            'content_update_position_token' => $this->tokens->assignToken(),
            'update_position_url' => $this->urls->generate('admin.folders.update-position'),
            'update_position_token' => $this->tokens->assignToken(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
            'list_query_params' => $listQuery->toQueryParams(),
        ] + $this->buildContentSection($parentId, $listQuery, $locale);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContentSection(int $folderId, FolderListQuery $listQuery, string $locale): array
    {
        $page = $listQuery->contentPage;
        $total = $folderId > 0 ? $this->contentRepository->countInFolder($folderId) : 0;
        if ($total === 0) {
            return ['content_rows' => [], 'content_total' => 0, 'content_pages' => 0, 'content_current_page' => 1];
        }

        $pages = max(1, (int) ceil($total / self::CONTENT_PAGE_SIZE));
        $page = max(1, min($page, $pages));

        $rows = [];
        foreach ($this->contentRepository->findInFolderPage($folderId, $locale, ($page - 1) * self::CONTENT_PAGE_SIZE, self::CONTENT_PAGE_SIZE) as $content) {
            $rows[] = $this->contentToRow($content, $folderId, $listQuery);
        }

        return ['content_rows' => $rows, 'content_total' => $total, 'content_pages' => $pages, 'content_current_page' => $page];
    }

    /**
     * @return array<string, mixed>
     */
    private function contentToRow(Content $content, int $folderId, FolderListQuery $listQuery): array
    {
        $id = (int) $content->getId();
        $title = (string) $content->getTitle();
        $editUrl = $this->urls->generate('admin.content.update', ['content_id' => $id]);

        return [
            'id' => $id,
            'thumbnail_html' => $this->thumbnails->forContent($id),
            'title' => $title,
            'title_html' => \sprintf(
                '<a href="%s" class="text-decoration-none fw-medium">%s</a>',
                htmlspecialchars($editUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            ),
            'visible' => (bool) $content->getVisible(),
            'position' => $content->hasVirtualColumn(ContentRepository::FOLDER_POSITION_COLUMN)
                ? (int) $content->getVirtualColumn(ContentRepository::FOLDER_POSITION_COLUMN)
                : (int) $content->getPosition(),
            'toggle_visible_url' => $this->urls->generate('admin.content.toggle-online', ['content_id' => $id] + $listQuery->toListParams($folderId)),
            '_actions' => [
                new RowAction(kind: 'edit', label: $this->translator->trans('Edit'), href: $editUrl, grantedAttribute: AccessManager::UPDATE, grantedSubject: AdminResources::CONTENT),
                new RowAction(kind: 'delete', label: $this->translator->trans('Delete'), modalTarget: '#content-delete-modal', grantedAttribute: AccessManager::DELETE, grantedSubject: AdminResources::CONTENT, dataAttributes: ['content-id' => $id, 'content-label' => $title]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function folderToRow(Folder $folder, FolderListQuery $listQuery): array
    {
        $id = (int) $folder->getId();
        $actions = [
            new RowAction(kind: 'edit', label: $this->translator->trans('Edit'), href: $this->urls->generate(self::EDIT_ROUTE, ['folder_id' => $id]), grantedAttribute: AccessManager::UPDATE, grantedSubject: self::RESOURCE),
            new RowAction(kind: 'delete', label: $this->translator->trans('Delete'), modalTarget: '#folder-delete-modal', grantedAttribute: AccessManager::DELETE, grantedSubject: self::RESOURCE, dataAttributes: ['folder-id' => $id, 'folder-label' => (string) $folder->getTitle()]),
        ];

        $title = (string) $folder->getTitle();
        $browseUrl = $this->urls->generate(self::LIST_ROUTE, ['folder_id' => $id]);

        return [
            'id' => $id,
            'thumbnail_html' => $this->thumbnails->forFolder($id),
            'title' => $title,
            'title_html' => \sprintf(
                '<a href="%s" class="text-decoration-none fw-medium">%s</a>',
                htmlspecialchars($browseUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            ),
            'visible' => (bool) $folder->getVisible(),
            'position' => (int) $folder->getPosition(),
            'children_url' => $browseUrl,
            'toggle_visible_url' => $this->urls->generate('admin.folders.toggle-online', ['folder_id' => $id] + $listQuery->toQueryParams()),
            '_actions' => $actions,
        ];
    }

    private function buildUpdateForm(Folder $folder, string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_folder_modification', FolderType::class, [
            'id' => $folder->getId(),
            'locale' => $locale,
            'title' => $folder->getTitle(),
            'parent' => $folder->getParent(),
            'visible' => (bool) $folder->getVisible(),
            'chapo' => $folder->getChapo(),
            'description' => $folder->getDescription(),
            'postscriptum' => $folder->getPostscriptum(),
        ], [
            'include_id' => true,
            'include_description' => true,
        ]);
    }

    private function buildSeoForm(Folder $folder, string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_folder_seo', FolderSeoType::class, [
            'id' => $folder->getId(),
            'locale' => $locale,
            'url' => $folder->getRewrittenUrl($locale),
            'meta_title' => $folder->getMetaTitle(),
            'meta_description' => $folder->getMetaDescription(),
            'meta_keywords' => $folder->getMetaKeywords(),
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
