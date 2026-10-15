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

namespace BackOfficeDefaultTwigBundle\Controller\Catalog;

use BackOfficeDefaultTwigBundle\Form\Catalog\CategorySeoType;
use BackOfficeDefaultTwigBundle\Form\Catalog\CategoryType;
use BackOfficeDefaultTwigBundle\Repository\CategoryRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Catalog\CategoryListPresenter;
use BackOfficeDefaultTwigBundle\Service\Catalog\ChoiceFilterPresenter;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Category\CategoryCreateEvent;
use Thelia\Core\Event\Category\CategoryDeleteEvent;
use Thelia\Core\Event\Category\CategoryToggleVisibilityEvent;
use Thelia\Core\Event\Category\CategoryUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Event\UpdateSeoEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Category;
use Thelia\Model\CategoryAssociatedContentQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ContentQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\TemplateQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin/categories', name: 'admin.categories.')]
final class CategoryController
{
    private const RESOURCE = AdminResources::CATEGORY;
    private const LIST_ROUTE = 'admin.categories.default';
    private const EDIT_ROUTE = 'admin.categories.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/catalog/category/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/catalog/category/edit.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly CategoryListPresenter $listPresenter,
        private readonly CategoryRepository $categories,
        private readonly ChoiceFilterPresenter $choiceFilterPresenter,
        private readonly EditLocaleResolver $editLocale,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $parentId = (int) $request->query->get('category_id', 0);
        $productPage = max(1, (int) $request->query->get('page', 1));
        $locale = $request->getLocale();

        $context = $this->listPresenter->buildListContext($parentId, $productPage, $locale);
        $context['create_form'] = $this->buildCreateForm($locale, $parentId)->createView();

        return new Response($this->twig->render(self::LIST_TEMPLATE, $context));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_category_creation', CategoryType::class, [
            'locale' => $request->getLocale(),
            'visible' => true,
        ], [
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::CATEGORY_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Category creation',
            successRoute: self::EDIT_ROUTE,
            successParametersResolver: static fn (CategoryCreateEvent $event): array => ['category_id' => (int) $event->getCategory()?->getId()],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
            describeForLog: $this->describeCreated(...),
        );
    }

    #[Route('/update', name: 'update', methods: ['GET'])]
    public function updateView(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $categoryId = (int) $request->query->get('category_id', 0);
        $category = CategoryQuery::create()->findPk($categoryId);
        if ($category === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $category->setLocale($locale);

        // UI locale labels the choice lists + breadcrumb; edit locale drives edited fields + preview.
        $uiLocale = $request->getLocale();

        $parentId = (int) $category->getParent();
        $parentCategory = $parentId > 0 ? CategoryQuery::create()->findPk($parentId) : null;
        $navigation = $this->categories->findPreviousNext($category);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'category' => $category,
            'preview_url' => $category->getUrl($locale),
            'form' => $this->buildUpdateForm($category, $locale)->createView(),
            'seo_form' => $this->buildSeoForm($category, $locale)->createView(),
            'children' => $this->childRows($category, $locale),
            'available_categories' => $this->categories->flatTree($uiLocale),
            'parent_excluded_ids' => $this->categories->subtreeIds((int) $category->getId()),
            'available_templates' => $this->availableTemplates($uiLocale),
            'current_tab' => (string) $request->query->get('current_tab', 'general'),
            'edit_language_id' => (int) $editLang->getId(),
            'assigned_contents' => $this->assignedContents($category, $locale),
            'available_related_content_url' => $this->urls->generate('admin.category.available-related-content', ['categoryId' => (int) $category->getId(), 'locale' => $locale]),
            'delete_related_content_token' => $this->tokens->assignToken(),
            'choice_filter' => $this->choiceFilterPresenter->forCategory($categoryId, $locale),
            'breadcrumb_path' => $this->categories->buildBreadcrumbPath($parentCategory, $uiLocale),
            'prev_url' => $navigation['previous'] !== null ? $this->urls->generate(self::EDIT_ROUTE, ['category_id' => $navigation['previous']]) : null,
            'next_url' => $navigation['next'] !== null ? $this->urls->generate(self::EDIT_ROUTE, ['category_id' => $navigation['next']]) : null,
        ]));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function processUpdate(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_category_modification', CategoryType::class, null, [
            'include_id' => true,
            'include_description' => true,
        ]);

        $categoryId = (int) $request->request->get('category_id', 0);
        $closeAfterSave = $request->request->get('save_mode') === 'close';

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::CATEGORY_UPDATE,
            eventFactory: $this->updateEvent(...),
            actionLabel: 'Category update',
            successRoute: $closeAfterSave ? self::LIST_ROUTE : self::EDIT_ROUTE,
            successParameters: $closeAfterSave ? [] : ['category_id' => $categoryId],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['category_id' => $categoryId])),
            successParametersResolver: $closeAfterSave ? static fn (CategoryUpdateEvent $event): array => ['category_id' => (int) $event->getParent()] : null,
            describeForLog: $this->describeUpdated(...),
        );
    }

    #[Route('/seo/save', name: 'seo.save', methods: ['POST'])]
    public function processSeo(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_category_seo', CategorySeoType::class, null, [
        ]);

        $categoryId = (int) $request->request->get('category_id', $request->request->get('id', 0));
        if ($categoryId === 0 && $request->request->has('thelia_category_seo')) {
            $raw = (array) $request->request->all('thelia_category_seo');
            $categoryId = (int) ($raw['id'] ?? 0);
        }

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::CATEGORY_UPDATE_SEO,
            eventFactory: $this->seoEvent(...),
            actionLabel: 'Category SEO update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['category_id' => $categoryId, 'current_tab' => 'seo'],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['category_id' => $categoryId, 'current_tab' => 'seo'])),
            describeForLog: $this->describeSeoUpdated(...),
        );
    }

    #[Route('/toggle-online', name: 'set-default', methods: ['POST'])]
    public function toggleOnline(Request $request): Response
    {
        $categoryId = (int) ($request->query->get('category_id') ?? $request->request->get('category_id', 0));
        $category = CategoryQuery::create()->findPk($categoryId);
        if ($category === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new CategoryToggleVisibilityEvent($category),
            eventName: TheliaEvents::CATEGORY_TOGGLE_VISIBILITY,
            actionLabel: 'Category visibility',
            successRoute: self::LIST_ROUTE,
            successParameters: ['category_id' => (int) $category->getParent(), 'page' => $this->listPage($request)],
        );
    }

    #[Route('/update-position', name: 'update-position', methods: ['POST'])]
    public function updatePosition(Request $request): Response
    {
        $event = new UpdatePositionEvent(
            (int) ($request->query->get('category_id') ?? $request->request->get('category_id', 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::CATEGORY_UPDATE_POSITION,
            actionLabel: 'Category reorder',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/tree', name: 'tree', methods: ['GET'])]
    public function tree(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $locale = $this->defaultLocale();

        return new Response($this->twig->render('@BackOfficeDefaultTwig/catalog/category/tree.html.twig', [
            'tree' => $this->buildTree(0, $locale),
            'token' => $this->tokens->assignToken(),
        ]));
    }

    #[Route('/tree-data', name: 'tree-data', methods: ['GET'])]
    public function treeData(): JsonResponse
    {
        if ($this->access->check(self::RESOURCE, [], AccessManager::VIEW) !== null) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse($this->buildTree(0, $this->defaultLocale()));
    }

    #[Route('/move', name: 'move', methods: ['POST'])]
    public function move(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'invalid token'], Response::HTTP_BAD_REQUEST);
        }

        $categoryId = (int) $request->request->get('category_id', 0);
        $newParent = (int) $request->request->get('new_parent_id', 0);
        $position = (int) $request->request->get('position', 0);

        $category = CategoryQuery::create()->findPk($categoryId);
        if ($category === null) {
            return new JsonResponse(['error' => 'category not found'], Response::HTTP_NOT_FOUND);
        }

        if ($newParent === $categoryId) {
            return new JsonResponse(['error' => 'cannot make a category its own parent'], Response::HTTP_BAD_REQUEST);
        }

        $ancestorId = $newParent;
        $depth = 0;
        while ($ancestorId > 0 && $depth < 1000) {
            $ancestor = CategoryQuery::create()->findPk($ancestorId);
            if ($ancestor === null) {
                break;
            }
            if ((int) $ancestor->getId() === $categoryId) {
                return new JsonResponse(['error' => 'cannot move a category into one of its descendants'], Response::HTTP_BAD_REQUEST);
            }
            $ancestorId = (int) $ancestor->getParent();
            ++$depth;
        }

        $category->setParent($newParent)->save();

        if ($position > 0) {
            $category->changeAbsolutePosition($position);
        }

        return new JsonResponse(['ok' => true, 'category_id' => $categoryId, 'parent_id' => $newParent, 'position' => $position]);
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $categoryId = (int) ($request->query->get('category_id') ?? $request->request->get('category_id', 0));
        $category = CategoryQuery::create()->findPk($categoryId);
        $parentId = $category?->getParent() ?? 0;

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new CategoryDeleteEvent($categoryId),
            eventName: TheliaEvents::CATEGORY_DELETE,
            actionLabel: 'Category deletion',
            successRoute: self::LIST_ROUTE,
            successParameters: ['category_id' => $parentId, 'page' => $this->listPage($request)],
        );
    }

    /**
     * The page of products of the category list the action was sent from.
     */
    private function listPage(Request $request): int
    {
        return max(1, (int) $request->query->get('page', 1));
    }

    private function createEvent(FormInterface $validated): CategoryCreateEvent
    {
        $data = $validated->getData() ?? [];

        $event = new CategoryCreateEvent();
        $event
            ->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setParent((int) ($data['parent'] ?? 0))
            ->setVisible((bool) ($data['visible'] ?? false));

        return $event;
    }

    private function updateEvent(FormInterface $validated): CategoryUpdateEvent
    {
        $data = $validated->getData() ?? [];

        $event = new CategoryUpdateEvent((int) ($data['id'] ?? 0));
        $event
            ->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setParent((int) ($data['parent'] ?? 0))
            ->setVisible((bool) ($data['visible'] ?? false))
            ->setChapo($this->stringOrNull($data['chapo'] ?? null))
            ->setDescription($this->stringOrNull($data['description'] ?? null))
            ->setPostscriptum($this->stringOrNull($data['postscriptum'] ?? null))
            ->setDefaultTemplateId((int) ($data['default_template_id'] ?? 0));

        return $event;
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

    /**
     * @return array{0: string, 1: int|null}
     */
    private function describeCreated(CategoryCreateEvent $event): array
    {
        $category = $event->getCategory();
        if ($category === null) {
            throw new \LogicException($this->translator->trans('No category was created.'));
        }

        return [\sprintf('Category %s (ID %d) created', (string) $category->getTitle(), (int) $category->getId()), (int) $category->getId()];
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function describeUpdated(CategoryUpdateEvent $event): array
    {
        $category = $event->getCategory();
        if ($category === null) {
            return ['Category modified', null];
        }

        return [\sprintf('Category %s (ID %d) modified', (string) $category->getTitle(), (int) $category->getId()), (int) $category->getId()];
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function describeSeoUpdated(UpdateSeoEvent $event): array
    {
        return [\sprintf('Category SEO (ID %d) modified', (int) $event->getObjectId()), (int) $event->getObjectId()];
    }

    private function buildCreateForm(string $locale, int $parentId): FormInterface
    {
        return $this->formFactory->createNamed('thelia_category_creation', CategoryType::class, [
            'locale' => $locale,
            'parent' => $parentId,
        ], []);
    }

    private function buildUpdateForm(Category $category, string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_category_modification', CategoryType::class, [
            'id' => $category->getId(),
            'locale' => $locale,
            'title' => $category->getTitle(),
            'parent' => $category->getParent(),
            'visible' => (bool) $category->getVisible(),
            'chapo' => $category->getChapo(),
            'description' => $category->getDescription(),
            'postscriptum' => $category->getPostscriptum(),
            'default_template_id' => $category->getDefaultTemplateId(),
        ], [
            'include_id' => true,
            'include_description' => true,
        ]);
    }

    private function buildSeoForm(Category $category, string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_category_seo', CategorySeoType::class, [
            'id' => $category->getId(),
            'locale' => $locale,
            'url' => $category->getRewrittenUrl($locale),
            'meta_title' => $category->getMetaTitle(),
            'meta_description' => $category->getMetaDescription(),
            'meta_keywords' => $category->getMetaKeywords(),
        ], [
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function childRows(Category $category, string $locale): array
    {
        $rows = [];
        $children = CategoryQuery::create()->filterByParent($category->getId())->orderByPosition()->find();
        foreach ($children as $child) {
            $child->setLocale($locale);
            $rows[] = [
                'id' => (int) $child->getId(),
                'title' => (string) $child->getTitle(),
                'visible' => (bool) $child->getVisible(),
                'position' => (int) $child->getPosition(),
                'children_url' => $this->urls->generate(self::LIST_ROUTE, ['category_id' => (int) $child->getId()]),
                'edit_url' => $this->urls->generate(self::EDIT_ROUTE, ['category_id' => (int) $child->getId()]),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, title: string, url: string}>
     */
    private function assignedContents(Category $category, string $locale): array
    {
        $assignments = CategoryAssociatedContentQuery::create()
            ->filterByCategoryId((int) $category->getId())
            ->find();

        $items = [];
        foreach ($assignments as $assignment) {
            $content = ContentQuery::create()->findPk((int) $assignment->getContentId());
            if ($content === null) {
                continue;
            }
            $content->setLocale($locale);
            $items[] = [
                'id' => (int) $content->getId(),
                'title' => (string) $content->getTitle(),
                'url' => $this->urls->generate('admin.content.update', ['content_id' => (int) $content->getId()]),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function availableTemplates(string $locale): array
    {
        $items = [['id' => null, 'name' => $this->translator->trans('- Inherit from parent -')]];
        foreach (TemplateQuery::create()->orderById()->find() as $template) {
            $template->setLocale($locale);
            $items[] = ['id' => (int) $template->getId(), 'name' => (string) $template->getName()];
        }

        return $items;
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }

    /**
     * @return list<array{id: int, title: string, visible: bool, position: int, children: list<array>}>
     */
    private function buildTree(int $parentId, string $locale): array
    {
        $items = CategoryQuery::create()
            ->filterByParent($parentId)
            ->orderByPosition()
            ->find();

        $tree = [];
        foreach ($items as $category) {
            $category->setLocale($locale);
            $tree[] = [
                'id' => (int) $category->getId(),
                'title' => (string) $category->getTitle(),
                'visible' => (bool) $category->getVisible(),
                'position' => (int) $category->getPosition(),
                'children' => $this->buildTree((int) $category->getId(), $locale),
            ];
        }

        return $tree;
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
