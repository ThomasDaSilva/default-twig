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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\File\ProductVideoPresenter;
use BackOfficeDefaultTwigBundle\Service\Product\CombinationIdentifierNotices;
use BackOfficeDefaultTwigBundle\Service\Product\CombinationsTabContextBuilder;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Action\Image as ImageAction;
use Thelia\Core\Event\FeatureProduct\FeatureProductDeleteEvent;
use Thelia\Core\Event\FeatureProduct\FeatureProductUpdateEvent;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\Core\Event\Product\ProductCombinationGenerationEvent;
use Thelia\Core\Event\Product\ProductSetTemplateEvent;
use Thelia\Core\Event\ProductSaleElement\ProductSaleElementCreateEvent;
use Thelia\Core\Event\ProductSaleElement\ProductSaleElementDeleteEvent;
use Thelia\Core\Event\ProductSaleElement\ProductSaleElementToggleVisibilityEvent;
use Thelia\Core\Event\ProductSaleElement\ProductSaleElementUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Catalog\Product\Identifier\InvalidGtinException;
use Thelia\Domain\Catalog\Product\Identifier\InvalidMpnException;
use Thelia\Model\AccessoryQuery;
use Thelia\Model\Attribute;
use Thelia\Model\AttributeAvQuery;
use Thelia\Model\AttributeQuery;
use Thelia\Model\AttributeTemplateQuery;
use Thelia\Model\ContentQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Feature;
use Thelia\Model\FeatureAv;
use Thelia\Model\FeatureAvQuery;
use Thelia\Model\FeatureProductQuery;
use Thelia\Model\FeatureQuery;
use Thelia\Model\FeatureTemplateQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductAssociationTypeQuery;
use Thelia\Model\ProductDocument;
use Thelia\Model\ProductDocumentQuery;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductPrice;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsProductDocument;
use Thelia\Model\ProductSaleElementsProductDocumentQuery;
use Thelia\Model\ProductSaleElementsProductImage;
use Thelia\Model\ProductSaleElementsProductImageQuery;
use Thelia\Model\ProductSaleElementsProductVideo;
use Thelia\Model\ProductSaleElementsProductVideoQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\ProductVideoQuery;
use Thelia\Model\Template;
use Thelia\Model\TemplateQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

final class ProductAdvancedController
{
    private const RESOURCE = AdminResources::PRODUCT;
    private const EDIT_ROUTE = 'admin.products.update';
    private const PSE_THUMBNAIL_WIDTH = 200;
    private const PSE_THUMBNAIL_HEIGHT = 200;

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly CombinationsTabContextBuilder $combinationsTabContextBuilder,
        private readonly EventDispatcherInterface $imageEvents,
        private readonly TokenProvider $tokens,
        private readonly ProductVideoPresenter $videoPresenter,
        private readonly CombinationIdentifierNotices $identifierNotices,
    ) {
    }

    #[Route('/admin/product/{productId}/available-content/{folderId}.{_format}', name: 'admin.product.available-related-content', methods: ['GET'], requirements: ['productId' => '\d+', 'folderId' => '\d+'])]
    public function availableRelatedContent(int $productId, int $folderId, string $_format): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $locale = $this->defaultLocale();
        $alreadyAssigned = \Thelia\Model\ProductAssociatedContentQuery::create()
            ->select('content_id')
            ->findByProductId($productId)
            ->toArray();

        $query = ContentQuery::create()
            ->useContentFolderQuery()
            ->filterByFolderId($folderId)
            ->endUse()
            ->filterById($alreadyAssigned, Criteria::NOT_IN)
            ->orderByPosition();

        $items = [];
        foreach ($query->find() as $content) {
            $content->setLocale($locale);
            $items[] = ['id' => (int) $content->getId(), 'title' => (string) $content->getTitle()];
        }

        return $_format === 'json' ? new JsonResponse($items) : new Response('');
    }

    #[Route('/admin/product/{productId}/available-accessories/{categoryId}.{_format}', name: 'admin.product.accessories-content', methods: ['GET'], requirements: ['productId' => '\d+', 'categoryId' => '\d+'])]
    public function availableAccessories(int $productId, int $categoryId, string $_format): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $locale = $this->defaultLocale();
        $alreadyAssigned = AccessoryQuery::create()
            ->select('accessory')
            ->findByProductId($productId)
            ->toArray();
        $excluded = array_merge([$productId], array_map('intval', $alreadyAssigned));

        $query = ProductQuery::create()
            ->useProductCategoryQuery()
            ->filterByCategoryId($categoryId)
            ->endUse()
            ->filterById($excluded, Criteria::NOT_IN)
            ->orderByPosition();

        $items = [];
        foreach ($query->find() as $product) {
            $product->setLocale($locale);
            $items[] = ['id' => (int) $product->getId(), 'title' => (string) $product->getTitle(), 'ref' => (string) $product->getRef()];
        }

        return $_format === 'json' ? new JsonResponse($items) : new Response('');
    }

    /**
     * The products a category offers for a relation of this type.
     *
     * Products already related under this very type are out, and so is the product
     * itself. Products related under another type stay in: a case may be both an
     * accessory of the phone and a cross-sell of the charger, and excluding every
     * relation whatever its type would hide them from the other blocks.
     */
    #[Route('/admin/product/{productId}/available-associations/{typeCode}/{categoryId}.{_format}', name: 'admin.product.associations-content', methods: ['GET'], requirements: ['productId' => '\d+', 'typeCode' => '[a-z0-9_]+', 'categoryId' => '\d+'])]
    public function availableAssociations(int $productId, string $typeCode, int $categoryId, string $_format): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $type = ProductAssociationTypeQuery::create()->filterByCode($typeCode)->findOne();

        if (null === $type) {
            return $_format === 'json' ? new JsonResponse([]) : new Response('');
        }

        $locale = $this->defaultLocale();
        $alreadyRelated = AccessoryQuery::create()
            ->filterByProductId($productId)
            ->filterByTypeId($type->getId())
            ->select('accessory')
            ->find()
            ->toArray();
        $excluded = array_merge([$productId], array_map('intval', $alreadyRelated));

        $query = ProductQuery::create()
            ->useProductCategoryQuery()
                ->filterByCategoryId($categoryId)
            ->endUse()
            ->filterById($excluded, Criteria::NOT_IN)
            ->orderByPosition();

        $items = [];
        foreach ($query->find() as $product) {
            $product->setLocale($locale);
            $items[] = ['id' => (int) $product->getId(), 'title' => (string) $product->getTitle(), 'ref' => (string) $product->getRef()];
        }

        return $_format === 'json' ? new JsonResponse($items) : new Response('');
    }

    #[Route('/admin/product/update-association-position', name: 'admin.product.update-association-position', methods: ['POST'])]
    public function updateAssociationPosition(Request $request): Response
    {
        $event = new UpdatePositionEvent(
            (int) ($request->query->get('association_id') ?? $request->request->get('association_id', 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::PRODUCT_UPDATE_ASSOCIATION_POSITION,
            actionLabel: 'Product relation reorder',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['product_id' => (int) ($request->query->get('product_id') ?? $request->request->get('product_id', 0)), 'current_tab' => 'related'],
        );
    }

    #[Route('/admin/product/update-content-position', name: 'admin.product.update-content-position', methods: ['POST'])]
    public function updateContentPosition(Request $request): Response
    {
        $event = new UpdatePositionEvent(
            (int) ($request->query->get('content_id') ?? $request->request->get('content_id', 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::PRODUCT_UPDATE_CONTENT_POSITION,
            actionLabel: 'Product content reorder',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['product_id' => (int) ($request->query->get('product_id') ?? $request->request->get('product_id', 0)), 'current_tab' => 'related'],
        );
    }

    #[Route('/admin/product/update-accessory-position', name: 'admin.product.update-accessory-position', methods: ['POST'])]
    public function updateAccessoryPosition(Request $request): Response
    {
        $event = new UpdatePositionEvent(
            (int) ($request->query->get('accessory_id') ?? $request->request->get('accessory_id', 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::PRODUCT_UPDATE_ACCESSORY_POSITION,
            actionLabel: 'Product accessory reorder',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['product_id' => (int) ($request->query->get('product_id') ?? $request->request->get('product_id', 0)), 'current_tab' => 'related'],
        );
    }

    #[Route('/admin/product/calculate-price', name: 'admin.product.calculate-price', methods: ['GET'])]
    public function calculatePrice(Request $request): JsonResponse
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return new JsonResponse([], Response::HTTP_FORBIDDEN);
        }

        $price = (float) $request->query->get('price', 0);
        $taxRuleId = (int) $request->query->get('tax_rule_id', 0);
        $action = (string) $request->query->get('action', 'untaxed_to_taxed');

        $result = $price;
        $taxRule = \Thelia\Model\TaxRuleQuery::create()->findPk($taxRuleId);
        $country = \Thelia\Model\CountryQuery::create()->findOneByByDefault(1);

        if ($taxRule !== null && $country !== null) {
            $calculator = new \Thelia\Domain\Taxation\TaxEngine\Calculator();
            try {
                $calculator->loadTaxRuleWithoutProduct($taxRule, $country);
                $result = match ($action) {
                    'taxed_to_untaxed' => $calculator->getUntaxedPrice($price),
                    default => $calculator->getTaxedPrice($price),
                };
            } catch (\Throwable) {
                $result = $price;
            }
        }

        return new JsonResponse([
            'price' => $price,
            'tax_rule_id' => $taxRuleId,
            'action' => $action,
            'result' => round((float) $result, 6),
        ]);
    }

    #[Route('/admin/product/calculate-raw-price', name: 'admin.product.calculate-raw-price', methods: ['GET'])]
    public function calculateRawPrice(Request $request): JsonResponse
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return new JsonResponse([], Response::HTTP_FORBIDDEN);
        }

        $price = (float) $request->query->get('price', 0);
        $taxRuleId = (int) $request->query->get('tax_rule_id', 0);

        $taxRule = \Thelia\Model\TaxRuleQuery::create()->findPk($taxRuleId);
        $country = \Thelia\Model\CountryQuery::create()->findOneByByDefault(1);

        $untaxed = $price;
        if ($taxRule !== null && $country !== null) {
            $calculator = new \Thelia\Domain\Taxation\TaxEngine\Calculator();
            try {
                $calculator->loadTaxRuleWithoutProduct($taxRule, $country);
                $untaxed = $calculator->getUntaxedPrice($price);
            } catch (\Throwable) {
                $untaxed = $price;
            }
        }

        return new JsonResponse([
            'price' => $price,
            'tax_rule_id' => $taxRuleId,
            'result' => round((float) $untaxed, 6),
        ]);
    }

    #[Route('/admin/product/load-converted-prices', name: 'admin.product.load-converted-prices', methods: ['GET'])]
    public function loadConvertedPrices(Request $request): JsonResponse
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return new JsonResponse([], Response::HTTP_FORBIDDEN);
        }

        $price = (float) $request->query->get('price', 0);
        $defaultCurrencyId = (int) $request->query->get('default_currency_id', 0);

        $base = CurrencyQuery::create()->findPk($defaultCurrencyId)
            ?? CurrencyQuery::create()->findOneByByDefault(1);

        $prices = [];
        foreach (CurrencyQuery::create()->find() as $currency) {
            $rate = (float) $currency->getRate();
            $baseRate = $base ? (float) $base->getRate() : 1.0;
            $converted = $baseRate > 0 ? ($price / $baseRate) * $rate : $price;
            $prices[(int) $currency->getId()] = round($converted, 6);
        }

        return new JsonResponse(['prices' => $prices]);
    }

    #[Route('/admin/product/product-sale-element-visibility', name: 'admin.product.product-sale-element-visibility', methods: ['POST'])]
    public function pseToggleVisibility(Request $request): Response
    {
        $pseId = (int) ($request->query->get('product_sale_element_id') ?? $request->request->get('product_sale_element_id', 0));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new ProductSaleElementToggleVisibilityEvent($pseId),
            eventName: TheliaEvents::PRODUCT_PRODUCT_SALE_ELEMENT_TOGGLE_VISIBILITY,
            actionLabel: 'PSE visibility',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['product_id' => (int) ($request->query->get('product_id') ?? $request->request->get('product_id', 0)), 'current_tab' => 'pse'],
        );
    }

    #[Route('/admin/product/product-sale-element-position', name: 'admin.product.product-sale-element-position', methods: ['POST'])]
    public function psePosition(Request $request): Response
    {
        $event = new UpdatePositionEvent(
            (int) ($request->query->get('product_sale_element_id') ?? $request->request->get('product_sale_element_id', 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::PRODUCT_PRODUCT_SALE_ELEMENT_UPDATE_POSITION,
            actionLabel: 'PSE reorder',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['product_id' => (int) ($request->query->get('product_id') ?? $request->request->get('product_id', 0)), 'current_tab' => 'pse'],
        );
    }

    #[Route('/admin/products/attributes/tab', name: 'admin.products.attributes.tab', methods: ['GET'])]
    public function attributesTab(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $productId = (int) ($request->attributes->get('product_id') ?? $request->query->get('product_id', 0));
        $product = ProductQuery::create()->findPk($productId);
        if ($product === null) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return new Response($this->twig->render(
            '@BackOfficeDefaultTwig/catalog/product/_attributes_tab.html.twig',
            $this->attributesTabContext($product),
        ));
    }

    #[Route('/admin/product/{productId}/set-product-template', name: 'admin.products.set-product-template', methods: ['POST'], requirements: ['productId' => '\d+'])]
    public function setProductTemplate(int $productId, Request $request): Response
    {
        $product = ProductQuery::create()->findPk($productId);
        if ($product === null) {
            return new RedirectResponse($this->urls->generate('admin.products.default'));
        }

        $event = new ProductSetTemplateEvent($product, (int) ($request->query->get('template_id') ?? $request->request->get('template_id', 0)), $this->defaultCurrencyId());

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::PRODUCT_SET_TEMPLATE,
            actionLabel: 'Product template set',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['product_id' => $productId],
        );
    }

    #[Route('/admin/product/{productId}/update-attributes-and-features', name: 'admin.products.update-attributes-and-features', methods: ['POST'], requirements: ['productId' => '\d+'])]
    public function updateAttributesAndFeatures(int $productId, Request $request, EventDispatcherInterface $events): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        $product = ProductQuery::create()->findPk($productId);
        if ($product !== null) {
            $this->dispatchFeatureUpdates($product, $request, $events);
        }

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, [
            'product_id' => $productId,
            'current_tab' => 'attributes',
        ]));
    }

    #[Route('/admin/product/{productId}/attribute-values/{attributeId}.{_format}', name: 'admin.product.attribute-values', methods: ['GET'], requirements: ['productId' => '\d+', 'attributeId' => '\d+'])]
    public function attributeValues(int $productId, int $attributeId, string $_format): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $locale = $this->defaultLocale();
        $items = [];
        foreach (AttributeAvQuery::create()->filterByAttributeId($attributeId)->orderByPosition()->find() as $av) {
            $av->setLocale($locale);
            $items[] = ['id' => (int) $av->getId(), 'title' => (string) $av->getTitle()];
        }

        return $_format === 'json' ? new JsonResponse($items) : new Response('');
    }

    #[Route('/admin/product/{productId}/add-attribute-value-to-combination/{attributeAvId}/{combination}.{_format}', name: 'admin.product.add-attribute-value-to-combination', methods: ['GET'], requirements: ['productId' => '\d+', 'attributeAvId' => '\d+'])]
    public function addAttributeValueToCombination(int $productId, int $attributeAvId, string $combination, string $_format): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $combinationIds = $combination !== '' ? array_filter(array_map('intval', explode(',', $combination))) : [];
        $combinationIds[] = $attributeAvId;
        $combinationIds = array_unique($combinationIds);

        $items = [];
        foreach (AttributeAvQuery::create()->filterById($combinationIds, Criteria::IN)->find() as $av) {
            $av->setLocale($this->defaultLocale());
            $items[] = ['id' => (int) $av->getId(), 'title' => (string) $av->getTitle()];
        }

        return $_format === 'json' ? new JsonResponse(['combination' => implode(',', $combinationIds), 'attributes' => $items]) : new Response('');
    }

    #[Route('/admin/product/combination/add', name: 'admin.product.combination.add', methods: ['POST'])]
    public function combinationAdd(Request $request): Response
    {
        $product = ProductQuery::create()->findPk((int) ($request->query->get('product_id') ?? $request->request->get('product_id', 0)));
        if ($product === null) {
            return new RedirectResponse($this->urls->generate('admin.products.default'));
        }

        $combination = (string) ($request->query->get('combination_attributes') ?? $request->request->get('combination_attributes', ''));
        $attributeAvList = $combination !== '' ? array_filter(array_map('intval', explode(',', $combination))) : [];
        $event = new ProductSaleElementCreateEvent($product, $attributeAvList, $this->defaultCurrencyId());

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::PRODUCT_ADD_PRODUCT_SALE_ELEMENT,
            actionLabel: 'PSE created',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['product_id' => (int) $product->getId(), 'current_tab' => 'pse'],
        );
    }

    #[Route('/admin/product/combination/toggle-visibility/{pse_id}', name: 'admin.product.combination.toggle-visibility', methods: ['POST'], requirements: ['pse_id' => '\d+'])]
    public function combinationToggleVisibility(int $pse_id, Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new ProductSaleElementToggleVisibilityEvent($pse_id),
            eventName: TheliaEvents::PRODUCT_PRODUCT_SALE_ELEMENT_TOGGLE_VISIBILITY,
            actionLabel: 'PSE visibility toggled',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['product_id' => (int) ($request->query->get('product_id') ?? $request->request->get('product_id', 0)), 'current_tab' => 'pse'],
        );
    }

    #[Route('/admin/product/combination/delete', name: 'admin.product.combination.delete', methods: ['POST'])]
    public function combinationDelete(Request $request): Response
    {
        $event = new ProductSaleElementDeleteEvent(
            (int) ($request->query->get('product_sale_element_id') ?? $request->request->get('product_sale_element_id', 0)),
            $this->defaultCurrencyId(),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::PRODUCT_DELETE_PRODUCT_SALE_ELEMENT,
            actionLabel: 'PSE deleted',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['product_id' => (int) ($request->query->get('product_id') ?? $request->request->get('product_id', 0)), 'current_tab' => 'pse'],
        );
    }

    #[Route('/admin/product/combinations/update', name: 'admin.product.combination.update', methods: ['POST'])]
    public function combinationUpdate(Request $request, EventDispatcherInterface $events): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $form = $request->request;
        $this->tokens->checkToken((string) $form->get('_token', ''));

        $productId = (int) $form->get('product_id', 0);
        $product = ProductQuery::create()->findPk($productId);
        if ($product === null) {
            return new RedirectResponse($this->urls->generate('admin.products.default'));
        }

        $currency = $this->defaultCurrencyId();
        $taxRule = (int) $form->get('tax_rule', (int) $product->getTaxRuleId());
        $useExchangeRate = (int) $form->get('use_exchange_rate', 0);
        $defaultPse = (int) $form->get('default_pse', 0);

        $ids = $form->all('product_sale_element_id');
        $refs = $form->all('reference');
        $prices = $form->all('price');
        $weights = $form->all('weight');
        $quantities = $form->all('quantity');
        $salePrices = $form->all('sale_price');
        $eans = $form->all('ean_code');
        $mpns = $form->all('mpn');
        $manufacturerBrands = $form->all('manufacturer_brand_id');
        $onsale = $form->all('onsale');
        $isnew = $form->all('isnew');
        $saved = [];

        foreach ($ids as $index => $rawId) {
            $pseId = (int) $rawId;
            if ($pseId <= 0) {
                continue;
            }

            $storedPrice = $this->storedPrice($pseId, $currency);
            $event = new ProductSaleElementUpdateEvent($product, $pseId);
            $event
                ->setReference((string) ($refs[$index] ?? ''))
                ->setPrice((float) ($prices[$index] ?? $storedPrice?->getPrice() ?? 0))
                ->setCurrencyId($currency)
                ->setWeight((float) ($weights[$index] ?? 0))
                ->setQuantity((float) ($quantities[$index] ?? 0))
                ->setSalePrice((float) ($salePrices[$index] ?? $storedPrice?->getPromoPrice() ?? 0))
                ->setOnsale(isset($onsale[$index]) ? 1 : 0)
                ->setIsnew(isset($isnew[$index]) ? 1 : 0)
                ->setIsdefault($defaultPse === $pseId)
                ->setEanCode((string) ($eans[$index] ?? ''))
                ->setMpn(isset($mpns[$index]) ? (string) $mpns[$index] : null)
                ->setManufacturerBrandId(isset($manufacturerBrands[$index]) ? (int) $manufacturerBrands[$index] : null)
                ->setTaxRuleId($taxRule)
                ->setFromDefaultCurrency($useExchangeRate);

            // A refused code costs its own row only: the other combinations of the grid
            // are saved, and the refusal says which one and why.
            try {
                $events->dispatch($event, TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT);
                $saved[] = $pseId;
            } catch (InvalidGtinException|InvalidMpnException $refusal) {
                $this->identifierNotices->refused($refusal);
            }
        }

        $this->identifierNotices->reportSharedCodes($saved);

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['product_id' => $productId, 'current_tab' => 'pse']));
    }

    #[Route('/admin/products/combinations/tab', name: 'admin.product.combinations.tab', methods: ['GET'])]
    public function combinationsTab(Request $request): Response
    {
        $productId = (int) ($request->attributes->get('product_id') ?? $request->query->get('product_id', 0));
        $product = ProductQuery::create()->findPk($productId);
        if ($product === null) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $currencyId = (int) ($request->attributes->get('currency_id') ?? $request->query->get('currency_id', 0));

        return new Response($this->twig->render(
            '@BackOfficeDefaultTwig/catalog/product/_combinations_tab.html.twig',
            $this->combinationsTabContextBuilder->build($product, $currencyId > 0 ? $currencyId : null),
        ));
    }

    #[Route('/admin/product/combination/build', name: 'admin.product.combination.build', methods: ['POST'])]
    public function combinationBuild(Request $request): Response
    {
        $product = ProductQuery::create()->findPk((int) ($request->query->get('product_id') ?? $request->request->get('product_id', 0)));
        if ($product === null) {
            return new RedirectResponse($this->urls->generate('admin.products.default'));
        }

        $combinations = $this->expandCombinations((array) $request->request->all('attribute_av'));
        $reference = trim((string) $request->request->get('reference', ''));
        $event = new ProductCombinationGenerationEvent($product, $this->defaultCurrencyId(), $combinations);
        $event
            ->setReference($reference !== '' ? $reference : (string) $product->getRef())
            ->setPrice((float) $request->request->get('price', 0))
            ->setWeight((float) $request->request->get('weight', 0))
            ->setQuantity((float) $request->request->get('quantity', 0))
            ->setSalePrice((float) $request->request->get('sale_price', 0))
            ->setOnsale((bool) $request->request->get('onsale', false))
            ->setIsnew((bool) $request->request->get('isnew', false))
            ->setEanCode((string) $request->request->get('ean_code', ''));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::PRODUCT_COMBINATION_GENERATION,
            actionLabel: 'PSE combinations built',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['product_id' => (int) $product->getId(), 'current_tab' => 'pse'],
        );
    }

    /**
     * Group `attribute_av` tokens (`attrId:avId`) by attribute, then build the cartesian
     * product so each output entry lists one AttributeAv id per chosen attribute.
     *
     * @param list<string> $tokens
     *
     * @return list<list<int>>
     */
    private function expandCombinations(array $tokens): array
    {
        $grouped = [];
        foreach ($tokens as $token) {
            if (!str_contains($token, ':')) {
                continue;
            }
            [$attributeId, $avId] = explode(':', $token, 2);
            $attributeId = (int) $attributeId;
            $avId = (int) $avId;
            if ($attributeId <= 0 || $avId <= 0) {
                continue;
            }
            $grouped[$attributeId][] = $avId;
        }

        if ($grouped === []) {
            return [];
        }

        $combinations = [[]];
        foreach ($grouped as $avIds) {
            $next = [];
            foreach ($combinations as $partial) {
                foreach ($avIds as $avId) {
                    $next[] = [...$partial, $avId];
                }
            }
            $combinations = $next;
        }

        return $combinations;
    }

    #[Route('/admin/product/default-price/update', name: 'admin.product.combination.defaut-price.update', methods: ['POST'])]
    public function combinationDefaultPriceUpdate(Request $request, EventDispatcherInterface $events): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $form = $request->request;
        $this->tokens->checkToken((string) $form->get('_token', ''));

        $productId = (int) $form->get('product_id', 0);
        $product = ProductQuery::create()->findPk($productId);
        if ($product === null) {
            return new RedirectResponse($this->urls->generate('admin.products.default'));
        }

        $pseId = (int) $form->get('product_sale_element_id', 0);
        if ($pseId > 0) {
            $currency = (int) $form->get('currency', $this->defaultCurrencyId());
            $storedPrice = $this->storedPrice($pseId, $currency);
            $event = new ProductSaleElementUpdateEvent($product, $pseId);
            $event
                ->setReference((string) $form->get('reference', ''))
                ->setPrice((float) ($form->get('price') ?? $storedPrice?->getPrice() ?? 0))
                ->setCurrencyId($currency)
                ->setWeight((float) $form->get('weight', 0))
                ->setQuantity((float) $form->get('quantity', 0))
                ->setSalePrice((float) ($form->get('sale_price') ?? $storedPrice?->getPromoPrice() ?? 0))
                ->setOnsale($form->get('onsale') !== null ? 1 : 0)
                ->setIsnew($form->get('isnew') !== null ? 1 : 0)
                ->setIsdefault(true)
                ->setEanCode((string) $form->get('ean_code', ''))
                ->setMpn($form->has('mpn') ? (string) $form->get('mpn') : null)
                ->setManufacturerBrandId($form->has('manufacturer_brand_id') ? (int) $form->get('manufacturer_brand_id') : null)
                ->setTaxRuleId((int) $form->get('tax_rule', (int) $product->getTaxRuleId()))
                ->setFromDefaultCurrency((int) $form->get('use_exchange_rate', 0));

            try {
                $events->dispatch($event, TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT);
                $this->identifierNotices->reportSharedCodes([$pseId]);
            } catch (InvalidGtinException|InvalidMpnException $refusal) {
                $this->identifierNotices->refused($refusal);
            }
        }

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['product_id' => $productId, 'current_tab' => 'pse']));
    }

    /**
     * Attaches a medium of the product to one of its combinations, or detaches it.
     *
     * Every medium is looked up inside the product of the combination: an id that
     * belongs to another product is not found here, so a crafted call cannot hang
     * the images, documents or videos of one product onto the combination of
     * another.
     */
    #[Route('/admin/product_sale_elements/{pseId}/{type}/{typeId}', name: 'admin.product_sale_elements.document_image_assoc', methods: ['POST'], requirements: ['pseId' => '\d+', 'typeId' => '\d+', 'type' => 'image|document|virtual|video'])]
    public function pseDocumentImageAssoc(int $pseId, string $type, int $typeId, Request $request): JsonResponse
    {
        if ($this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        $pse = ProductSaleElementsQuery::create()->findPk($pseId);
        if ($pse === null) {
            return new JsonResponse(['error' => 'pse not found'], Response::HTTP_NOT_FOUND);
        }

        $productId = (int) $pse->getProductId();

        $response = [
            'product_sale_elements_id' => $pseId,
            'type' => $type,
            'type_id' => $typeId,
        ];

        if ($type === 'image') {
            if (ProductImageQuery::create()->filterByProductId($productId)->filterById($typeId)->findOne() === null) {
                return new JsonResponse(['error' => 'image not found'], Response::HTTP_NOT_FOUND);
            }

            $assoc = ProductSaleElementsProductImageQuery::create()
                ->filterByProductSaleElementsId($pseId)
                ->findOneByProductImageId($typeId);

            if ($assoc === null) {
                $assoc = new ProductSaleElementsProductImage();
                $assoc->setProductSaleElementsId($pseId)->setProductImageId($typeId)->save();
                $response['is_associated'] = 1;
            } else {
                $assoc->delete();
                $response['is_associated'] = 0;
            }
            $response['product_image_id'] = $typeId;
        } elseif ($type === 'document') {
            if (ProductDocumentQuery::create()->filterByProductId($productId)->filterById($typeId)->findOne() === null) {
                return new JsonResponse(['error' => 'document not found'], Response::HTTP_NOT_FOUND);
            }

            $assoc = ProductSaleElementsProductDocumentQuery::create()
                ->filterByProductSaleElementsId($pseId)
                ->findOneByProductDocumentId($typeId);

            if ($assoc === null) {
                $assoc = new ProductSaleElementsProductDocument();
                $assoc->setProductSaleElementsId($pseId)->setProductDocumentId($typeId)->save();
                $response['is_associated'] = 1;
            } else {
                $assoc->delete();
                $response['is_associated'] = 0;
            }
            $response['product_document_id'] = $typeId;
        } elseif ($type === 'video') {
            if (ProductVideoQuery::create()->filterByProductId($productId)->filterById($typeId)->findOne() === null) {
                return new JsonResponse(['error' => 'video not found'], Response::HTTP_NOT_FOUND);
            }

            $assoc = ProductSaleElementsProductVideoQuery::create()
                ->filterByProductSaleElementsId($pseId)
                ->findOneByProductVideoId($typeId);

            if ($assoc === null) {
                $assoc = new ProductSaleElementsProductVideo();
                $assoc->setProductSaleElementsId($pseId)->setProductVideoId($typeId)->save();
                $response['is_associated'] = 1;
            } else {
                $assoc->delete();
                $response['is_associated'] = 0;
            }
            $response['product_video_id'] = $typeId;
        } elseif ($type === 'virtual') {
            if (ProductDocumentQuery::create()->findPk($typeId) === null) {
                return new JsonResponse(['error' => 'document not found'], Response::HTTP_NOT_FOUND);
            }

            if ($pse->getVirtualDocument()?->getId() === $typeId) {
                $pse->setVirtualDocument(null);
                $response['is_associated'] = 0;
            } else {
                $pse->setVirtualDocument($typeId);
                $response['is_associated'] = 1;
            }
            $response['product_document_id'] = $typeId;
        } else {
            return new JsonResponse(['error' => 'unsupported type for this endpoint'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($response);
    }

    #[Route('/admin/product_sale_elements/ajax/{type}/{id}', name: 'admin.product_sale_elements.document_image_assoc.get_assoc', methods: ['GET'], requirements: ['id' => '\d+', 'type' => 'image|document|virtual|video'])]
    public function pseDocumentImageAssocGet(string $type, int $id): JsonResponse
    {
        if ($this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $pse = ProductSaleElementsQuery::create()->findPk($id);
        if ($pse === null) {
            return new JsonResponse(['error' => 'pse not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'type' => $type,
            'pse_id' => $id,
            'items' => match ($type) {
                'image' => $this->pseImageItems($pse),
                'document' => $this->pseDocumentItems($pse),
                'virtual' => $this->pseVirtualDocumentItems($pse),
                'video' => $this->pseVideoItems($pse),
                default => [],
            },
        ]);
    }

    /**
     * @return list<array{id: int, title: string, url: string, filename: string, is_associated: bool}>
     */
    private function pseImageItems(ProductSaleElements $pse): array
    {
        $locale = $this->defaultLocale();
        $images = ProductImageQuery::create()
            ->filterByProductId((int) $pse->getProductId())
            ->orderByPosition()
            ->find();

        $assoc = ProductSaleElementsProductImageQuery::create()
            ->filterByProductSaleElementsId((int) $pse->getId())
            ->find();
        $assocIds = [];
        foreach ($assoc as $a) {
            $assocIds[(int) $a->getProductImageId()] = true;
        }

        $items = [];
        foreach ($images as $image) {
            $image->setLocale($locale);
            $items[] = [
                'id' => (int) $image->getId(),
                'title' => (string) $image->getTitle(),
                'url' => $this->processedImageUrl($image),
                'filename' => (string) $image->getFile(),
                'is_associated' => isset($assocIds[(int) $image->getId()]),
            ];
        }

        return $items;
    }

    /**
     * @return list<array{id: int, title: string, url: string, filename: string, is_associated: bool}>
     */
    private function pseVideoItems(ProductSaleElements $pse): array
    {
        $videos = ProductVideoQuery::create()
            ->filterByProductId((int) $pse->getProductId())
            ->orderByPosition()
            ->find();

        $assocIds = [];
        foreach (ProductSaleElementsProductVideoQuery::create()->filterByProductSaleElementsId((int) $pse->getId())->find() as $assoc) {
            $assocIds[(int) $assoc->getProductVideoId()] = true;
        }

        $locale = $this->defaultLocale();

        $items = [];
        foreach ($videos as $video) {
            $video->setLocale($locale);
            $items[] = [
                'id' => (int) $video->getId(),
                'title' => (string) $video->getTitle(),
                // The thumbnail is what a video shows: the address it plays from never
                // reaches this payload, and never reaches the picker.
                'url' => $this->videoPresenter->thumbnailUrl($video),
                'filename' => (string) $video->getProvider(),
                'is_associated' => isset($assocIds[(int) $video->getId()]),
            ];
        }

        return $items;
    }

    private function processedImageUrl(ProductImage $image): string
    {
        $sourcePath = $image->getUploadDir().\DIRECTORY_SEPARATOR.$image->getFile();
        $event = new ImageEvent();
        $event->setSourceFilepath($sourcePath);
        $event->setCacheSubdirectory('product');
        $event->setWidth(self::PSE_THUMBNAIL_WIDTH);
        $event->setHeight(self::PSE_THUMBNAIL_HEIGHT);
        $event->setResizeMode((string) ImageAction::EXACT_RATIO_WITH_BORDERS);

        try {
            $this->imageEvents->dispatch($event, TheliaEvents::IMAGE_PROCESS);
        } catch (\Throwable) {
            return '';
        }

        return (string) $event->getFileUrl();
    }

    /**
     * @return list<array{id: int, title: string, url: string, filename: string, is_associated: bool}>
     */
    private function pseDocumentItems(ProductSaleElements $pse): array
    {
        $documents = ProductDocumentQuery::create()
            ->filterByProductId((int) $pse->getProductId())
            ->orderByPosition()
            ->find();

        $assoc = ProductSaleElementsProductDocumentQuery::create()
            ->filterByProductSaleElementsId((int) $pse->getId())
            ->find();
        $assocIds = [];
        foreach ($assoc as $a) {
            $assocIds[(int) $a->getProductDocumentId()] = true;
        }

        $locale = $this->defaultLocale();

        $items = [];
        foreach ($documents as $document) {
            $document->setLocale($locale);
            $items[] = $this->documentItem($document, isset($assocIds[(int) $document->getId()]));
        }

        return $items;
    }

    /**
     * Downloadable file of a sale element. The association lives in
     * product_sale_elements_virtual_document, not in the product_sale_elements_product_document
     * join table the documents picker uses, and it holds a single document: the toggle endpoint
     * writes exactly that storage.
     *
     * @return list<array{id: int, title: string, url: string, filename: string, is_associated: bool}>
     */
    private function pseVirtualDocumentItems(ProductSaleElements $pse): array
    {
        // Only documents hidden from the front office can be sold: a visible one is freely downloadable.
        $documents = ProductDocumentQuery::create()
            ->filterByProductId((int) $pse->getProductId())
            ->filterByVisible(0)
            ->orderByPosition()
            ->find();

        $virtualDocumentId = $pse->getVirtualDocument()?->getId();

        $locale = $this->defaultLocale();

        $items = [];
        foreach ($documents as $document) {
            $document->setLocale($locale);
            $items[] = $this->documentItem($document, (int) $document->getId() === $virtualDocumentId);
        }

        return $items;
    }

    /**
     * @return array{id: int, title: string, url: string, filename: string, is_associated: bool}
     */
    private function documentItem(ProductDocument $document, bool $isAssociated): array
    {
        return [
            'id' => (int) $document->getId(),
            'title' => (string) $document->getTitle(),
            'url' => (string) $document->getFile(),
            'filename' => (string) $document->getFile(),
            'is_associated' => $isAssociated,
        ];
    }

    #[Route('/admin/product/virtual-documents/{productId}/{pseId}', name: 'admin.product.virtual_documents', methods: ['GET'], requirements: ['productId' => '\d+', 'pseId' => '\d+'])]
    public function virtualDocuments(int $productId, int $pseId): JsonResponse
    {
        if ($this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $selectedId = ProductSaleElementsQuery::create()->findPk($pseId)?->getVirtualDocument()?->getId();

        $documents = ProductDocumentQuery::create()
            ->filterByProductId($productId)
            ->filterByVisible(0)
            ->orderByPosition()
            ->find();

        $results = [];
        foreach ($documents as $document) {
            $results[] = [
                'id' => (int) $document->getId(),
                'title' => (string) $document->getTitle(),
                'file' => (string) $document->getFile(),
                'selected' => ((int) $document->getId() === $selectedId),
            ];
        }

        return new JsonResponse($results);
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }

    private function defaultCurrencyId(): int
    {
        $defaultCurrency = CurrencyQuery::create()->findOneByByDefault(1);

        return (int) ($defaultCurrency?->getId() ?? 1);
    }

    private function storedPrice(int $pseId, int $currencyId): ?ProductPrice
    {
        return ProductPriceQuery::create()
            ->filterByProductSaleElementsId($pseId)
            ->filterByCurrencyId($currencyId)
            ->findOne();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesTabContext(Product $product): array
    {
        $locale = $this->defaultLocale();
        $templates = [];
        foreach (TemplateQuery::create()->orderById()->find() as $template) {
            \assert($template instanceof Template);
            $template->setLocale($locale);
            $templates[] = [
                'id' => (int) $template->getId(),
                'name' => $template->getName() ?? \sprintf('#%d', (int) $template->getId()),
            ];
        }

        $templateId = (int) ($product->getTemplateId() ?? 0);

        return [
            'product' => $product,
            'product_template_id' => $templateId,
            'product_templates' => $templates,
            'product_attribute_rows' => $this->attributeRowsForTemplate($templateId, $locale),
            'product_feature_rows' => $this->featureRowsForProduct($product, $templateId, $locale),
            'attributes_form_action' => $this->urls->generate('admin.products.update-attributes-and-features', ['productId' => (int) $product->getId()]),
            'set_template_action' => $this->urls->generate('admin.products.set-product-template', ['productId' => (int) $product->getId()]),
        ];
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    private function attributeRowsForTemplate(int $templateId, string $locale): array
    {
        if ($templateId <= 0) {
            return [];
        }

        $rows = [];
        $attributeTemplates = AttributeTemplateQuery::create()
            ->filterByTemplateId($templateId)
            ->orderByPosition()
            ->find();
        $attributeIds = [];
        foreach ($attributeTemplates as $entry) {
            $attributeIds[] = (int) $entry->getAttributeId();
        }
        if ($attributeIds === []) {
            return [];
        }

        $attributes = AttributeQuery::create()
            ->filterById($attributeIds, Criteria::IN)
            ->find();
        $byId = [];
        foreach ($attributes as $attribute) {
            \assert($attribute instanceof Attribute);
            $attribute->setLocale($locale);
            $byId[(int) $attribute->getId()] = $attribute;
        }

        foreach ($attributeIds as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $attribute = $byId[$id];
            $rows[] = [
                'id' => $id,
                'title' => $attribute->getTitle() ?? \sprintf('#%d', $id),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{
     *     id: int,
     *     title: string,
     *     options: list<array{id: int, title: string, selected: bool}>,
     *     free_text: bool,
     *     free_text_value: string,
     * }>
     */
    private function featureRowsForProduct(Product $product, int $templateId, string $locale): array
    {
        if ($templateId <= 0) {
            return [];
        }

        $featureTemplates = FeatureTemplateQuery::create()
            ->filterByTemplateId($templateId)
            ->orderByPosition()
            ->find();
        $featureIds = [];
        foreach ($featureTemplates as $entry) {
            $featureIds[] = (int) $entry->getFeatureId();
        }
        if ($featureIds === []) {
            return [];
        }

        $features = FeatureQuery::create()
            ->filterById($featureIds, Criteria::IN)
            ->joinWithI18n($locale)
            ->find();
        $byId = [];
        foreach ($features as $feature) {
            \assert($feature instanceof Feature);
            $feature->setLocale($locale);
            $byId[(int) $feature->getId()] = $feature;
        }

        $rows = [];
        $productId = (int) $product->getId();

        foreach ($featureIds as $featureId) {
            if (!isset($byId[$featureId])) {
                continue;
            }
            $feature = $byId[$featureId];

            // Free-text values are stored by the core as per-product FeatureAvs
            // (feature_product.is_free_text = 1): they are not selectable choices and
            // must not turn a free-text feature into a giant select.
            $featureAvs = FeatureAvQuery::create()
                ->filterByFeatureId($featureId)
                ->joinWithI18n($locale)
                ->where('feature_av.ID NOT IN (SELECT feature_av_id FROM feature_product WHERE is_free_text = 1 AND feature_av_id IS NOT NULL)')
                ->orderByPosition()
                ->find();
            $options = [];
            foreach ($featureAvs as $av) {
                \assert($av instanceof FeatureAv);
                $av->setLocale($locale);
                $options[] = [
                    'id' => (int) $av->getId(),
                    'title' => $av->getTitle() ?? \sprintf('#%d', (int) $av->getId()),
                ];
            }

            $selectedAvIds = [];
            $freeTextValue = '';
            $freeTextOnly = $options === [];

            $featureProducts = FeatureProductQuery::create()
                ->filterByProductId($productId)
                ->filterByFeatureId($featureId)
                ->find();
            foreach ($featureProducts as $fp) {
                if ($fp->getIsFreeText()) {
                    $av = $fp->getFeatureAv();
                    $av?->setLocale($locale);
                    $freeTextValue = $av?->getTitle() ?? '';
                    continue;
                }
                $selectedAvIds[] = (int) $fp->getFeatureAvId();
            }

            foreach ($options as &$option) {
                $option['selected'] = \in_array($option['id'], $selectedAvIds, true);
            }
            unset($option);

            $rows[] = [
                'id' => $featureId,
                'title' => $feature->getTitle() ?? \sprintf('#%d', $featureId),
                'options' => $options,
                'free_text' => $freeTextOnly,
                'free_text_value' => $freeTextValue,
            ];
        }

        return $rows;
    }

    private function dispatchFeatureUpdates(Product $product, Request $request, EventDispatcherInterface $events): void
    {
        $productId = (int) $product->getId();
        $templateId = (int) ($product->getTemplateId() ?? 0);
        if ($templateId <= 0) {
            return;
        }

        $featureTemplates = FeatureTemplateQuery::create()
            ->filterByTemplateId($templateId)
            ->find();
        $allFeatureIds = [];
        foreach ($featureTemplates as $entry) {
            $allFeatureIds[] = (int) $entry->getFeatureId();
        }

        $updated = [];
        $featureValues = $request->request->all('feature_value');
        foreach ($featureValues as $featureId => $values) {
            $featureId = (int) $featureId;
            $events->dispatch(new FeatureProductDeleteEvent($productId, $featureId), TheliaEvents::PRODUCT_FEATURE_DELETE_VALUE);

            foreach ((array) $values as $value) {
                $events->dispatch(
                    new FeatureProductUpdateEvent($productId, $featureId, $value),
                    TheliaEvents::PRODUCT_FEATURE_UPDATE_VALUE,
                );
            }
            $updated[] = $featureId;
        }

        $featureTextValues = $request->request->all('feature_text_value');
        foreach ($featureTextValues as $featureId => $value) {
            $featureId = (int) $featureId;
            $value = (string) $value;
            $existing = FeatureProductQuery::create()
                ->filterByProductId($productId)
                ->filterByIsFreeText(true)
                ->findOneByFeatureId($featureId);
            if ($existing === null && $value === '') {
                continue;
            }

            $event = new FeatureProductUpdateEvent($productId, $featureId, $value, true);
            $event->setLocale($this->defaultLocale());
            $events->dispatch($event, TheliaEvents::PRODUCT_FEATURE_UPDATE_VALUE);
            $updated[] = $featureId;
        }

        foreach ($allFeatureIds as $featureId) {
            if (\in_array($featureId, $updated, true)) {
                continue;
            }
            $events->dispatch(new FeatureProductDeleteEvent($productId, $featureId), TheliaEvents::PRODUCT_FEATURE_DELETE_VALUE);
        }
    }
}
