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

use BackOfficeDefaultTwigBundle\Form\Product\ProductCloneType;
use BackOfficeDefaultTwigBundle\Form\Product\ProductSeoType;
use BackOfficeDefaultTwigBundle\Form\Product\ProductType;
use BackOfficeDefaultTwigBundle\Repository\CategoryRepository;
use BackOfficeDefaultTwigBundle\Repository\ProductRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Catalog\CategoryListPresenter;
use BackOfficeDefaultTwigBundle\Service\Catalog\ProductFilterPresenter;
use BackOfficeDefaultTwigBundle\Service\Catalog\ProductFilters;
use BackOfficeDefaultTwigBundle\Service\Catalog\ProductPricingPresenter;
use BackOfficeDefaultTwigBundle\Service\Catalog\ProductPricingProvider;
use BackOfficeDefaultTwigBundle\Service\Catalog\ProductPricingSnapshot;
use BackOfficeDefaultTwigBundle\Service\Catalog\ProductRelationsContext;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\Service\Listing\ListingThumbnailPresenter;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Product\ProductCloneEvent;
use Thelia\Core\Event\Product\ProductCreateEvent;
use Thelia\Core\Event\Product\ProductDeleteEvent;
use Thelia\Core\Event\Product\ProductToggleVisibilityEvent;
use Thelia\Core\Event\Product\ProductUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Event\UpdateSeoEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\BrandQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\MetaData;
use Thelia\Model\MetaDataQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductAssociationTypeQuery;
use Thelia\Model\ProductDocumentQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\TaxRuleQuery;
use Thelia\Model\TemplateQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin/products', name: 'admin.products.')]
final class ProductController
{
    private const RESOURCE = AdminResources::PRODUCT;
    private const LIST_ROUTE = 'admin.products.default';
    private const CATEGORY_LIST_ROUTE = 'admin.categories.default';
    private const EDIT_ROUTE = 'admin.products.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/catalog/product/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/catalog/product/edit.html.twig';
    private const PAGE_SIZE = 25;
    private const BULK_CHOICES_LIMIT = 500;

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly ProductRelationsContext $relations,
        private readonly CategoryRepository $categories,
        private readonly ProductRepository $productRepository,
        private readonly EditLocaleResolver $editLocale,
        private readonly ListingThumbnailPresenter $thumbnails,
        private readonly ProductFilterPresenter $filterPresenter,
        private readonly ProductPricingProvider $pricing,
        private readonly ProductPricingPresenter $pricingPresenter,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $filters = ProductFilters::fromRequest($request);
        $locale = $this->defaultLocale();
        $categories = $this->categoryChoices();

        return new Response($this->twig->render(self::LIST_TEMPLATE, array_merge(
            $this->paginatedRows($filters, $page),
            [
                'current_page' => $page,
                'current_category' => $filters->categoryId ?? 0,
                'current_search' => $filters->search,
                'current_order' => $filters->sort,
                'filters' => $this->filterPresenter->present($filters, $locale, $categories),
                'query_params' => $filters->toQueryParams(),
                'allow_manual_ordering' => $filters->allowsManualOrdering(),
                'create_form' => $this->buildCreateForm($request)->createView(),
                'clone_form' => $this->buildCloneForm()->createView(),
                'available_categories' => $categories,
                'available_currencies' => $this->currencyChoices(),
                'available_tax_rules' => $this->taxRuleChoices(),
                'bulk_contents' => $this->contentChoices($locale),
                'bulk_products' => $this->productChoices($locale),
                'bulk_association_types' => $this->associationTypeChoices($request->getLocale()),
            ],
        )));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $this->buildCreateForm($request);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::PRODUCT_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Product creation',
            successRoute: self::EDIT_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
            describeForLog: $this->describeCreated(...),
            successParametersResolver: static fn (ProductCreateEvent $event): array => ['product_id' => (int) $event->getProduct()->getId()],
        );
    }

    #[Route('/create-modal', name: 'create-modal', methods: ['GET'])]
    public function createModalFragment(Request $request, int $default_category = 0): Response
    {
        if ($this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return new Response('');
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/catalog/product/_create_modal.html.twig', [
            'create_form' => $this->buildCreateForm($request)->createView(),
            'available_categories' => $this->categoryChoices(),
            'available_currencies' => $this->currencyChoices(),
            'available_tax_rules' => $this->taxRuleChoices(),
            'default_category_id' => $default_category,
        ]));
    }

    #[Route('/clone', name: 'clone', methods: ['POST'])]
    public function clone(Request $request): Response
    {
        $form = $this->buildCloneForm();

        $cloneData = $request->request->all('thelia_product_clone');
        $productId = (int) ($cloneData['productId'] ?? 0);
        if ($productId === 0) {
            $productId = (int) $request->request->get('productId', 0);
        }

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::PRODUCT_CLONE,
            eventFactory: function (FormInterface $validated) use ($productId): ProductCloneEvent {
                $data = $validated->getData() ?? [];
                $resolvedId = (int) ($data['productId'] ?? $productId);
                $original = ProductQuery::create()->findPk($resolvedId);
                if ($original === null) {
                    throw new \LogicException($this->translator->trans('Original product not found.'));
                }

                return new ProductCloneEvent(
                    (string) ($data['newRef'] ?? ''),
                    $this->defaultLocale(),
                    $original,
                );
            },
            actionLabel: 'Product clone',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
        );
    }

    #[Route('/update', name: 'update', methods: ['GET'])]
    public function updateView(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $productId = (int) $request->query->get('product_id', 0);
        $product = ProductQuery::create()->findPk($productId);
        if ($product === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $product->setLocale($locale);

        // UI locale (admin interface language) labels the choice lists and breadcrumb;
        // the edit locale only drives the edited product fields and the front preview URL.
        $uiLocale = $request->getLocale();

        $form = $this->buildUpdateForm($product, $locale);
        $seoForm = $this->buildSeoForm($product, $locale);

        $defaultCategoryId = (int) $product->getDefaultCategoryId();
        $defaultCategory = $defaultCategoryId > 0 ? CategoryQuery::create()->findPk($defaultCategoryId) : null;
        $navigation = $this->productRepository->findPreviousNext($product);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, array_merge(
            [
                'product' => $product,
                'form' => $form->createView(),
                'seo_form' => $seoForm->createView(),
                'clone_form' => $this->buildCloneForm()->createView(),
                'current_tab' => (string) $request->query->get('current_tab', 'general'),
                'edit_language_id' => (int) $editLang->getId(),
                'available_templates' => $this->templateChoices(),
                'available_categories' => $this->categories->flatTree($uiLocale),
                'available_brands' => $this->brandChoices($uiLocale),
                'available_documents' => $this->documentChoices($product, $uiLocale),
                'virtual_document_per_combination' => $this->hasSeveralSaleElements($product),
                'preview_url' => $product->getUrl($locale),
                'breadcrumb_path' => $this->categories->buildBreadcrumbPath($defaultCategory, $uiLocale),
                'prev_url' => $navigation['previous'] !== null ? $this->urls->generate(self::EDIT_ROUTE, ['product_id' => $navigation['previous']]) : null,
                'next_url' => $navigation['next'] !== null ? $this->urls->generate(self::EDIT_ROUTE, ['product_id' => $navigation['next']]) : null,
            ],
            $this->relations->build($product, $locale, $uiLocale),
        )));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function processUpdate(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_product_modification', ProductType::class, null, [
            'include_id' => true,
        ]);

        $productId = (int) $request->request->get('product_id', 0);
        $closeAfterSave = $request->request->get('save_mode') === 'close';

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::PRODUCT_UPDATE,
            eventFactory: $this->updateEvent(...),
            actionLabel: 'Product update',
            successRoute: $closeAfterSave ? self::LIST_ROUTE : self::EDIT_ROUTE,
            successParameters: $closeAfterSave ? [] : ['product_id' => $productId],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['product_id' => $productId])),
            describeForLog: $this->describeUpdated(...),
        );
    }

    #[Route('/seo/save', name: 'seo.save', methods: ['POST'])]
    public function processSeo(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_product_seo', ProductSeoType::class, null, [
        ]);

        $productId = (int) $request->request->get('product_id', 0);
        if ($productId === 0 && $request->request->has('thelia_product_seo')) {
            $raw = (array) $request->request->all('thelia_product_seo');
            $productId = (int) ($raw['id'] ?? 0);
        }

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::PRODUCT_UPDATE_SEO,
            eventFactory: $this->seoEvent(...),
            actionLabel: 'Product SEO update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['product_id' => $productId, 'current_tab' => 'seo'],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['product_id' => $productId, 'current_tab' => 'seo'])),
            describeForLog: $this->describeSeoUpdated(...),
        );
    }

    #[Route('/toggle-online', name: 'set-default', methods: ['POST'])]
    public function toggleOnline(Request $request): Response
    {
        $productId = (int) ($request->query->get('product_id') ?? $request->request->get('product_id', 0));
        $product = ProductQuery::create()->findPk($productId);
        if ($product === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new ProductToggleVisibilityEvent($product),
            eventName: TheliaEvents::PRODUCT_TOGGLE_VISIBILITY,
            actionLabel: 'Product visibility',
            successRoute: $this->listRoute($request),
            successParameters: $this->listParameters($request),
        );
    }

    #[Route('/update-position', name: 'update-position', methods: ['POST'])]
    public function updatePosition(Request $request): Response
    {
        $event = new UpdatePositionEvent(
            (int) ($request->query->get('product_id') ?? $request->request->get('product_id', 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
            // A product's position lives in each of its categories: without the category,
            // the core finds no row to move.
            (int) ($request->query->get('category_id') ?? $request->request->get('category_id', 0)),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::PRODUCT_UPDATE_POSITION,
            actionLabel: 'Product reorder',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new ProductDeleteEvent((int) ($request->query->get('product_id') ?? $request->request->get('product_id', 0))),
            eventName: TheliaEvents::PRODUCT_DELETE,
            actionLabel: 'Product deletion',
            successRoute: $this->listRoute($request),
            successParameters: $this->listParameters($request),
        );
    }

    /**
     * The list an action of a product row was sent from: the category list
     * when it says so, the product list otherwise.
     */
    private function listRoute(Request $request): string
    {
        return $this->isFromCategoryList($request) ? self::CATEGORY_LIST_ROUTE : self::LIST_ROUTE;
    }

    /**
     * The page, filters and sort of that list, as its URL carried them.
     *
     * @return array<string, mixed>
     */
    private function listParameters(Request $request): array
    {
        $page = max(1, (int) $request->query->get('page', 1));

        if ($this->isFromCategoryList($request)) {
            return ['category_id' => (int) $request->query->get('category_id', 0), 'page' => $page];
        }

        return ['page' => $page] + ProductFilters::fromRequest($request)->toQueryParams();
    }

    private function isFromCategoryList(Request $request): bool
    {
        return $request->query->get(CategoryListPresenter::ORIGIN_PARAMETER) === CategoryListPresenter::ORIGIN;
    }

    private function buildCreateForm(Request $request): FormInterface
    {
        return $this->formFactory->createNamed('thelia_product_creation', ProductType::class, [
            'locale' => $request->getLocale(),
            'visible' => true,
        ], [
        ]);
    }

    private function buildCloneForm(): FormInterface
    {
        return $this->formFactory->createNamed('thelia_product_clone', ProductCloneType::class, null, [
        ]);
    }

    private function buildUpdateForm(Product $product, string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_product_modification', ProductType::class, [
            'id' => $product->getId(),
            'locale' => $locale,
            'ref' => $product->getRef(),
            'title' => $product->getTitle(),
            'default_category' => $product->getDefaultCategoryId(),
            'visible' => (bool) $product->getVisible(),
            'virtual' => (bool) $product->getVirtual(),
            'chapo' => $product->getChapo(),
            'description' => $product->getDescription(),
            'postscriptum' => $product->getPostscriptum(),
            'template_id' => $product->getTemplateId(),
            'brand_id' => $product->getBrandId(),
            'virtual_document_id' => $this->currentVirtualDocumentId($product),
            'guest_checkout_forbidden' => (bool) $product->getGuestCheckoutForbidden(),
        ], [
            'include_id' => true,
        ]);
    }

    private function buildSeoForm(Product $product, string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_product_seo', ProductSeoType::class, [
            'id' => $product->getId(),
            'locale' => $locale,
            'url' => $product->getRewrittenUrl($locale),
            'meta_title' => $product->getMetaTitle(),
            'meta_description' => $product->getMetaDescription(),
            'meta_keywords' => $product->getMetaKeywords(),
        ], [
        ]);
    }

    private function createEvent(FormInterface $validated): ProductCreateEvent
    {
        $data = $validated->getData() ?? [];

        $event = new ProductCreateEvent();
        $event
            ->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setRef((string) ($data['ref'] ?? ''))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setDefaultCategory((int) ($data['default_category'] ?? 0))
            ->setVisible((bool) ($data['visible'] ?? false))
            ->setVirtual((bool) ($data['virtual'] ?? false))
            ->setBasePrice((float) ($data['price'] ?? 0))
            ->setCurrencyId((int) ($data['currency'] ?? 0))
            ->setTaxRuleId((int) ($data['tax_rule'] ?? 0))
            ->setBaseWeight((float) ($data['weight'] ?? 0))
            ->setBaseQuantity((int) ($data['quantity'] ?? 0));

        return $event;
    }

    private function updateEvent(FormInterface $validated): ProductUpdateEvent
    {
        $data = $validated->getData() ?? [];
        $productId = (int) ($data['id'] ?? 0);

        $event = new ProductUpdateEvent($productId);
        $event
            ->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setRef((string) ($data['ref'] ?? ''))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setDefaultCategory((int) ($data['default_category'] ?? 0))
            ->setVisible((bool) ($data['visible'] ?? false))
            ->setVirtual((bool) ($data['virtual'] ?? false))
            ->setChapo($this->stringOrNull($data['chapo'] ?? null))
            ->setDescription($this->stringOrNull($data['description'] ?? null))
            ->setPostscriptum($this->stringOrNull($data['postscriptum'] ?? null))
            ->setTemplateId($this->intOrNull($data['template_id'] ?? null))
            ->setBrandId($this->intOrNull($data['brand_id'] ?? null))
            // 0 clears the association, -1 leaves it untouched (product with several combinations).
            ->setVirtualDocumentId(isset($data['virtual_document_id']) ? (int) $data['virtual_document_id'] : -1)
            ->setGuestCheckoutForbidden((bool) ($data['guest_checkout_forbidden'] ?? false));

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
    private function describeCreated(ProductCreateEvent $event): array
    {
        if (!$event->hasProduct()) {
            throw new \LogicException($this->translator->trans('No product was created.'));
        }
        $product = $event->getProduct();

        return [\sprintf('Product %s (ID %d) created', (string) $product->getRef(), (int) $product->getId()), (int) $product->getId()];
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function describeUpdated(ProductUpdateEvent $event): array
    {
        if (!$event->hasProduct()) {
            return ['Product modified', null];
        }
        $product = $event->getProduct();

        return [\sprintf('Product %s (ID %d) modified', (string) $product->getRef(), (int) $product->getId()), (int) $product->getId()];
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function describeSeoUpdated(UpdateSeoEvent $event): array
    {
        return [\sprintf('Product SEO (ID %d) modified', (int) $event->getObjectId()), (int) $event->getObjectId()];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function paginatedRows(ProductFilters $filters, int $page): array
    {
        $locale = $this->defaultLocale();
        $query = $filters->applyTo(ProductQuery::create(), $locale);

        $this->applyOrder($query, $filters);

        $total = (int) $query->count();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);

        $products = $query
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE)
            ->find();

        $productIds = [];
        foreach ($products as $product) {
            $productIds[] = (int) $product->getId();
        }
        $pricing = $this->pricing->forProducts($productIds);

        $rows = [];
        foreach ($products as $product) {
            \assert($product instanceof Product);
            $product->setLocale($locale);
            $rows[] = $this->productToRow($product, $pricing[(int) $product->getId()] ?? null, $filters, $page);
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'pages' => $pages,
            'update_position_url' => $this->urls->generate('admin.products.update-position'),
            'update_position_token' => $this->tokens->assignToken(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productToRow(Product $product, ?ProductPricingSnapshot $pricing, ProductFilters $filters, int $page): array
    {
        $id = (int) $product->getId();
        $pricing ??= new ProductPricingSnapshot();

        $actions = [
            new RowAction(
                kind: 'edit',
                label: $this->translator->trans('Edit this product'),
                href: $this->urls->generate(self::EDIT_ROUTE, ['product_id' => $id]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: self::RESOURCE,
            ),
            new RowAction(
                kind: 'delete',
                label: $this->translator->trans('Delete this product'),
                modalTarget: '#product-delete-modal',
                grantedAttribute: AccessManager::DELETE,
                grantedSubject: self::RESOURCE,
                dataAttributes: [
                    'product-id' => $id,
                    'product-label' => (string) $product->getTitle(),
                ],
            ),
        ];

        $editUrl = $this->urls->generate(self::EDIT_ROUTE, ['product_id' => $id]);

        return [
            'id' => $id,
            'thumbnail_html' => $this->thumbnails->forProduct($id),
            'ref' => (string) $product->getRef(),
            'ref_html' => \sprintf(
                '<a href="%s" class="text-decoration-none" data-testid="bo-product-ref-link">%s</a>',
                htmlspecialchars($editUrl),
                htmlspecialchars((string) $product->getRef()),
            ),
            'title' => (string) $product->getTitle(),
            'title_html' => \sprintf(
                '<a href="%s" class="text-decoration-none fw-semibold" data-testid="bo-product-title-link">%s</a>',
                htmlspecialchars($editUrl),
                htmlspecialchars((string) $product->getTitle()),
            ),
            'price_html' => $this->pricingPresenter->price($pricing),
            'promo_price_html' => $this->pricingPresenter->promoPrice($pricing),
            'stock_html' => $this->pricingPresenter->stock($pricing),
            'visible' => (bool) $product->getVisible(),
            'position' => (int) $product->getPosition(),
            'toggle_visible_url' => $this->urls->generate(
                'admin.products.set-default',
                ['product_id' => $id, 'page' => $page] + $filters->toQueryParams(),
            ),
            '_actions' => $actions,
        ];
    }

    private function applyOrder(ProductQuery $query, ProductFilters $filters): void
    {
        $direction = $filters->direction === 'desc' ? Criteria::DESC : Criteria::ASC;

        match ($filters->sort) {
            'id' => $query->orderById($direction),
            'ref' => $query->orderByRef($direction),
            'title' => $query->useProductI18nQuery('order_i18n')
                ->filterByLocale($this->defaultLocale())
                ->orderByTitle($direction)
                ->endUse(),
            'price' => $query->addAsColumn(
                'sort_price',
                '(SELECT pp.price FROM product_price pp
                    INNER JOIN product_sale_elements pse_sort ON pse_sort.id = pp.product_sale_elements_id
                    WHERE pse_sort.product_id = '.ProductTableMap::COL_ID.' AND pse_sort.is_default = 1 LIMIT 1)'
            )->orderBy('sort_price', $direction),
            'quantity' => $query->addAsColumn(
                'sort_quantity',
                '(SELECT COALESCE(SUM(pse_sort_qty.quantity), 0) FROM product_sale_elements pse_sort_qty
                    WHERE pse_sort_qty.product_id = '.ProductTableMap::COL_ID.')'
            )->orderBy('sort_quantity', $direction),
            default => $query->orderByPosition(Criteria::ASC),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categoryChoices(): array
    {
        $locale = $this->defaultLocale();
        $items = [['id' => 0, 'title' => $this->translator->trans('- All categories -')]];
        foreach (CategoryQuery::create()->joinWithI18n($locale)->orderById()->find() as $category) {
            $category->setLocale($locale);
            $items[] = ['id' => (int) $category->getId(), 'title' => (string) $category->getTitle()];
        }

        return $items;
    }

    /**
     * Bounded option lists for the bulk edit modal: enough to cover a typical
     * catalog without turning the select into a full search engine.
     *
     * @return list<array{id: int, title: string}>
     */
    private function contentChoices(string $locale): array
    {
        $items = [];
        foreach (\Thelia\Model\ContentQuery::create()->joinWithI18n($locale)->orderById()->limit(self::BULK_CHOICES_LIMIT)->find() as $content) {
            $content->setLocale($locale);
            $items[] = ['id' => (int) $content->getId(), 'title' => (string) $content->getTitle()];
        }

        usort($items, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return $items;
    }

    /**
     * @return list<array{code: string, title: string}>
     */
    private function associationTypeChoices(string $locale): array
    {
        $items = [];
        $types = ProductAssociationTypeQuery::create()
            ->joinWithI18n($locale)
            ->filterByVisible(1)
            ->orderByPosition()
            ->find();

        foreach ($types as $type) {
            $type->setLocale($locale);
            $items[] = ['code' => (string) $type->getCode(), 'title' => (string) $type->getTitle()];
        }

        return $items;
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function productChoices(string $locale): array
    {
        $items = [];
        foreach (ProductQuery::create()->joinWithI18n($locale)->orderByRef()->limit(self::BULK_CHOICES_LIMIT)->find() as $product) {
            $product->setLocale($locale);
            $items[] = [
                'id' => (int) $product->getId(),
                'title' => trim((string) $product->getRef().' - '.$product->getTitle()),
            ];
        }

        return $items;
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function brandChoices(string $locale): array
    {
        $items = [];
        foreach (BrandQuery::create()->joinWithI18n($locale)->orderByPosition()->find() as $brand) {
            $brand->setLocale($locale);
            $items[] = ['id' => (int) $brand->getId(), 'title' => (string) $brand->getTitle()];
        }

        return $items;
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function documentChoices(Product $product, string $locale): array
    {
        $items = [];

        // Only documents hidden from the front office can be sold: a visible one is freely downloadable.
        $documents = ProductDocumentQuery::create()
            ->filterByProductId($product->getId())
            ->filterByVisible(0)
            ->orderByPosition()
            ->find();

        foreach ($documents as $document) {
            $document->setLocale($locale);
            $label = (string) $document->getTitle();
            $items[] = ['id' => (int) $document->getId(), 'title' => $label !== '' ? $label : (string) $document->getFile()];
        }

        return $items;
    }

    /**
     * Current virtual document of the product's default sale element, if any.
     * Thelia 3.0.0-beta3 moved the association to its own table; older cores keep it
     * in a meta_data row, and reading it there keeps the screen usable on those.
     */
    private function currentVirtualDocumentId(Product $product): ?int
    {
        $defaultSaleElement = $this->defaultSaleElement($product);
        if ($defaultSaleElement === null) {
            return null;
        }

        // The guard is what keeps an older core working; PHPStan analyses this bundle
        // against the current core, where the method is always there.
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists($defaultSaleElement, 'getVirtualDocument')) {
            return $defaultSaleElement->getVirtualDocument()?->getId();
        }

        $documentId = (int) MetaDataQuery::getVal('virtual', MetaData::PSE_KEY, $defaultSaleElement->getId());

        return $documentId > 0 ? $documentId : null;
    }

    private function defaultSaleElement(Product $product): ?ProductSaleElements
    {
        return ProductSaleElementsQuery::create()
            ->filterByProductId($product->getId())
            ->filterByIsDefault(true)
            ->findOne();
    }

    /**
     * A product with several combinations gets one document per combination, set in the
     * combinations tab: the general tab then leaves every association untouched.
     */
    private function hasSeveralSaleElements(Product $product): bool
    {
        return ProductSaleElementsQuery::create()->filterByProductId($product->getId())->count() > 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function currencyChoices(): array
    {
        $items = [];
        foreach (CurrencyQuery::create()->orderByPosition()->find() as $currency) {
            $items[] = ['id' => (int) $currency->getId(), 'name' => (string) $currency->getName(), 'default' => (bool) $currency->getByDefault()];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taxRuleChoices(): array
    {
        $locale = $this->defaultLocale();
        $items = [];
        foreach (TaxRuleQuery::create()->joinWithI18n($locale)->orderById()->find() as $taxRule) {
            $taxRule->setLocale($locale);
            $items[] = ['id' => (int) $taxRule->getId(), 'title' => (string) $taxRule->getTitle()];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function templateChoices(): array
    {
        $locale = $this->defaultLocale();
        $items = [['id' => null, 'name' => $this->translator->trans('- No template -')]];
        foreach (TemplateQuery::create()->joinWithI18n($locale)->orderById()->find() as $template) {
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

    private function stringOrNull(mixed $value): ?string
    {
        if (!\is_scalar($value) && $value !== null) {
            return null;
        }
        $cast = (string) $value;

        return $cast === '' ? null : $cast;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
