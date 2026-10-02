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

namespace BackOfficeDefaultTwigBundle\Service\Catalog;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Domain\Catalog\Product\Identifier\Gtin;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\ProductI18nQuery;
use Thelia\Model\ProductQuery;

/**
 * Single source of truth for the catalog list filters: parsing, URL
 * serialisation and SQL application live here so the controller stays thin.
 *
 * Stock and price predicates target the sale elements, since that is where
 * Thelia keeps quantities and amounts.
 */
final readonly class ProductFilters
{
    public const DEFAULT_SORT = 'manual';
    public const DEFAULT_DIRECTION = 'asc';
    public const ALLOWED_DIRECTIONS = ['asc', 'desc'];
    public const ALLOWED_SORTS = ['manual', 'id', 'ref', 'title', 'price', 'quantity'];

    public const TRISTATE_WITH = 'with';
    public const TRISTATE_WITHOUT = 'without';

    public const KEY_SEARCH = 'search';
    public const KEY_CATEGORY = 'category_id';
    public const KEY_BRAND = 'brand_id';
    public const KEY_TEMPLATE = 'template_id';
    public const KEY_VISIBLE = 'visible';
    public const KEY_PROMO = 'promo';
    public const KEY_NEW = 'new';
    public const KEY_QUANTITY = 'quantity';
    public const KEY_PRICE = 'price';
    public const KEY_FEATURES = 'feature_ids';
    public const KEY_ATTRIBUTES = 'attribute_ids';

    /**
     * @param list<int> $featureIds
     * @param list<int> $attributeIds
     */
    public function __construct(
        public string $search = '',
        public ?int $categoryId = null,
        public ?int $brandId = null,
        public ?int $templateId = null,
        public ?bool $visible = null,
        public ?bool $promo = null,
        public ?bool $new = null,
        public ?float $minQuantity = null,
        public ?float $maxQuantity = null,
        public ?float $minPrice = null,
        public ?float $maxPrice = null,
        public array $featureIds = [],
        public array $attributeIds = [],
        public string $sort = self::DEFAULT_SORT,
        public string $direction = self::DEFAULT_DIRECTION,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $query = $request->query;

        $sort = (string) $query->get('product_order', self::DEFAULT_SORT);
        if (!\in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = self::DEFAULT_SORT;
        }

        $direction = strtolower((string) $query->get('direction', self::DEFAULT_DIRECTION));
        if (!\in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            $direction = self::DEFAULT_DIRECTION;
        }

        $minQuantity = self::parseFloat((string) $query->get('min_quantity', ''));
        $maxQuantity = self::parseFloat((string) $query->get('max_quantity', ''));
        if ($minQuantity !== null && $maxQuantity !== null && $minQuantity > $maxQuantity) {
            [$minQuantity, $maxQuantity] = [$maxQuantity, $minQuantity];
        }

        $minPrice = self::parseFloat((string) $query->get('min_price', ''));
        $maxPrice = self::parseFloat((string) $query->get('max_price', ''));
        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            [$minPrice, $maxPrice] = [$maxPrice, $minPrice];
        }

        return new self(
            search: trim((string) $query->get('q', '')),
            categoryId: self::parsePositiveInt((string) $query->get('category_id', '')),
            brandId: self::parsePositiveInt((string) $query->get('brand_id', '')),
            templateId: self::parsePositiveInt((string) $query->get('template_id', '')),
            visible: self::parseTriStateBool((string) $query->get('visible', '')),
            promo: self::parseTriStateBool((string) $query->get('promo', '')),
            new: self::parseTriStateBool((string) $query->get('new', '')),
            minQuantity: $minQuantity,
            maxQuantity: $maxQuantity,
            minPrice: $minPrice,
            maxPrice: $maxPrice,
            featureIds: self::parseIntArray($query->all('feature_ids')),
            attributeIds: self::parseIntArray($query->all('attribute_ids')),
            sort: $sort,
            direction: $direction,
        );
    }

    public function isEmpty(): bool
    {
        return $this->search === ''
            && $this->categoryId === null
            && $this->brandId === null
            && $this->templateId === null
            && $this->visible === null
            && $this->promo === null
            && $this->new === null
            && $this->minQuantity === null
            && $this->maxQuantity === null
            && $this->minPrice === null
            && $this->maxPrice === null
            && $this->featureIds === []
            && $this->attributeIds === [];
    }

    /**
     * True when a filter forbids the manual drag-and-drop ordering: positions
     * are only meaningful inside a single category.
     */
    public function allowsManualOrdering(): bool
    {
        return $this->sort === self::DEFAULT_SORT && $this->categoryId !== null;
    }

    /**
     * @return array<string, scalar|list<int>>
     */
    public function toQueryParams(): array
    {
        $params = [];

        if ($this->search !== '') {
            $params['q'] = $this->search;
        }
        if ($this->categoryId !== null) {
            $params['category_id'] = $this->categoryId;
        }
        if ($this->brandId !== null) {
            $params['brand_id'] = $this->brandId;
        }
        if ($this->templateId !== null) {
            $params['template_id'] = $this->templateId;
        }
        foreach (['visible' => $this->visible, 'promo' => $this->promo, 'new' => $this->new] as $key => $value) {
            if ($value !== null) {
                $params[$key] = $value ? self::TRISTATE_WITH : self::TRISTATE_WITHOUT;
            }
        }
        if ($this->minQuantity !== null) {
            $params['min_quantity'] = $this->minQuantity;
        }
        if ($this->maxQuantity !== null) {
            $params['max_quantity'] = $this->maxQuantity;
        }
        if ($this->minPrice !== null) {
            $params['min_price'] = $this->minPrice;
        }
        if ($this->maxPrice !== null) {
            $params['max_price'] = $this->maxPrice;
        }
        if ($this->featureIds !== []) {
            $params['feature_ids'] = $this->featureIds;
        }
        if ($this->attributeIds !== []) {
            $params['attribute_ids'] = $this->attributeIds;
        }
        if ($this->sort !== self::DEFAULT_SORT) {
            $params['product_order'] = $this->sort;
        }
        if ($this->direction !== self::DEFAULT_DIRECTION) {
            $params['direction'] = $this->direction;
        }

        return $params;
    }

    public function withoutFilter(string $key): self
    {
        return $this->cloneWith(match ($key) {
            self::KEY_SEARCH => ['search' => ''],
            self::KEY_CATEGORY => ['categoryId' => null],
            self::KEY_BRAND => ['brandId' => null],
            self::KEY_TEMPLATE => ['templateId' => null],
            self::KEY_VISIBLE => ['visible' => null],
            self::KEY_PROMO => ['promo' => null],
            self::KEY_NEW => ['new' => null],
            self::KEY_QUANTITY => ['minQuantity' => null, 'maxQuantity' => null],
            self::KEY_PRICE => ['minPrice' => null, 'maxPrice' => null],
            self::KEY_FEATURES => ['featureIds' => []],
            self::KEY_ATTRIBUTES => ['attributeIds' => []],
            default => [],
        });
    }

    public function applyTo(ProductQuery $query, string $locale): ProductQuery
    {
        if ($this->categoryId !== null) {
            $query->useProductCategoryQuery()
                ->filterByCategoryId($this->categoryId)
                ->endUse();
        }
        if ($this->brandId !== null) {
            $query->filterByBrandId($this->brandId);
        }
        if ($this->templateId !== null) {
            $query->filterByTemplateId($this->templateId);
        }
        if ($this->visible !== null) {
            $query->filterByVisible($this->visible ? 1 : 0);
        }

        $this->applySearch($query, $locale);
        $this->applySaleElementFlag($query, 'promo', $this->promo);
        $this->applySaleElementFlag($query, 'newness', $this->new);
        $this->applyQuantityRange($query);
        $this->applyPriceRange($query);
        $this->applyFeatures($query);
        $this->applyAttributes($query);

        return $query;
    }

    private function applySearch(ProductQuery $query, string $locale): void
    {
        if ($this->search === '') {
            return;
        }

        // The wildcards of LIKE typed in the box are searched for as characters: a
        // "%" alone would otherwise list the whole catalogue.
        $term = addcslashes($this->search, '%_\\');

        $titleIds = ProductI18nQuery::create()
            ->filterByLocale($locale)
            ->filterByTitle('%'.$term.'%', Criteria::LIKE)
            ->select(['Id'])
            ->find()
            ->toArray();
        $ids = array_map(static fn ($id): int => (int) $id, $titleIds);

        // condition+combine groups the title/ref match as a single OR cluster,
        // otherwise chaining _or() bleeds into the other filters and turns the
        // whole WHERE into an OR.
        //
        // A combination is found by its GTIN as scanned, spaces and hyphens dropped,
        // and by the start of its manufacturer part number: both read an indexed
        // column, and Propel binds one value per condition, hence one each.
        $query
            ->condition('search_title', ProductTableMap::COL_ID.' IN ('.implode(',', $ids ?: [0]).')')
            ->condition('search_ref', ProductTableMap::COL_REF.' LIKE ?', '%'.$term.'%', \PDO::PARAM_STR)
            ->condition('search_mpn', $this->combinationExists('pse_mpn', 'mpn LIKE ?'), $term.'%', \PDO::PARAM_STR);
        $conditions = ['search_title', 'search_ref', 'search_mpn'];

        // A term made only of spaces and hyphens leaves no code to look for, and an
        // empty one would match every combination without a GTIN.
        $gtin = Gtin::normalize($this->search);
        if ($gtin !== '') {
            $query->condition('search_gtin', $this->combinationExists('pse_gtin', 'ean_code = ?'), $gtin, \PDO::PARAM_STR);
            $conditions[] = 'search_gtin';
        }

        $query->combine($conditions, Criteria::LOGICAL_OR);
    }

    private function combinationExists(string $alias, string $condition): string
    {
        return 'EXISTS (SELECT 1 FROM product_sale_elements '.$alias
            .' WHERE '.$alias.'.product_id = '.ProductTableMap::COL_ID
            .' AND '.$alias.'.'.$condition.')';
    }

    private function applySaleElementFlag(ProductQuery $query, string $column, ?bool $expected): void
    {
        if ($expected === null) {
            return;
        }

        $clause = $expected ? 'EXISTS' : 'NOT EXISTS';
        $query->where(
            $clause.' (SELECT 1 FROM product_sale_elements pse_'.$column
                .' WHERE pse_'.$column.'.product_id = '.ProductTableMap::COL_ID
                .' AND pse_'.$column.'.'.$column.' = 1)'
        );
    }

    private function applyQuantityRange(ProductQuery $query): void
    {
        if ($this->minQuantity === null && $this->maxQuantity === null) {
            return;
        }

        $total = '(SELECT COALESCE(SUM(pse_qty.quantity), 0) FROM product_sale_elements pse_qty'
            .' WHERE pse_qty.product_id = '.ProductTableMap::COL_ID.')';

        if ($this->minQuantity !== null) {
            $query->where($total.' >= ?', $this->minQuantity, \PDO::PARAM_STR);
        }
        if ($this->maxQuantity !== null) {
            $query->where($total.' <= ?', $this->maxQuantity, \PDO::PARAM_STR);
        }
    }

    private function applyPriceRange(ProductQuery $query): void
    {
        // One clause per bound: Propel binds a single value per where().
        if ($this->minPrice !== null) {
            $query->where($this->defaultPriceExists('min', '>='), $this->minPrice, \PDO::PARAM_STR);
        }
        if ($this->maxPrice !== null) {
            $query->where($this->defaultPriceExists('max', '<='), $this->maxPrice, \PDO::PARAM_STR);
        }
    }

    private function defaultPriceExists(string $suffix, string $operator): string
    {
        $pse = 'pse_price_'.$suffix;
        $price = 'pp_'.$suffix;

        return 'EXISTS (SELECT 1 FROM product_sale_elements '.$pse
            .' INNER JOIN product_price '.$price.' ON '.$price.'.product_sale_elements_id = '.$pse.'.id'
            .' WHERE '.$pse.'.product_id = '.ProductTableMap::COL_ID
            .' AND '.$pse.'.is_default = 1 AND '.$price.'.price '.$operator.' ?)';
    }

    private function applyFeatures(ProductQuery $query): void
    {
        if ($this->featureIds === []) {
            return;
        }

        $query->where(
            'EXISTS (SELECT 1 FROM feature_product fp WHERE fp.product_id = '.ProductTableMap::COL_ID
                .' AND fp.feature_id IN ('.implode(',', $this->featureIds).'))'
        );
    }

    private function applyAttributes(ProductQuery $query): void
    {
        if ($this->attributeIds === []) {
            return;
        }

        $query->where(
            'EXISTS (SELECT 1 FROM product_sale_elements pse_attr'
                .' INNER JOIN attribute_combination ac ON ac.product_sale_elements_id = pse_attr.id'
                .' WHERE pse_attr.product_id = '.ProductTableMap::COL_ID
                .' AND ac.attribute_id IN ('.implode(',', $this->attributeIds).'))'
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function cloneWith(array $overrides): self
    {
        return new self(
            search: $overrides['search'] ?? $this->search,
            categoryId: \array_key_exists('categoryId', $overrides) ? $overrides['categoryId'] : $this->categoryId,
            brandId: \array_key_exists('brandId', $overrides) ? $overrides['brandId'] : $this->brandId,
            templateId: \array_key_exists('templateId', $overrides) ? $overrides['templateId'] : $this->templateId,
            visible: \array_key_exists('visible', $overrides) ? $overrides['visible'] : $this->visible,
            promo: \array_key_exists('promo', $overrides) ? $overrides['promo'] : $this->promo,
            new: \array_key_exists('new', $overrides) ? $overrides['new'] : $this->new,
            minQuantity: \array_key_exists('minQuantity', $overrides) ? $overrides['minQuantity'] : $this->minQuantity,
            maxQuantity: \array_key_exists('maxQuantity', $overrides) ? $overrides['maxQuantity'] : $this->maxQuantity,
            minPrice: \array_key_exists('minPrice', $overrides) ? $overrides['minPrice'] : $this->minPrice,
            maxPrice: \array_key_exists('maxPrice', $overrides) ? $overrides['maxPrice'] : $this->maxPrice,
            featureIds: $overrides['featureIds'] ?? $this->featureIds,
            attributeIds: $overrides['attributeIds'] ?? $this->attributeIds,
            sort: $overrides['sort'] ?? $this->sort,
            direction: $overrides['direction'] ?? $this->direction,
        );
    }

    private static function parseTriStateBool(string $raw): ?bool
    {
        return match ($raw) {
            self::TRISTATE_WITH, '1' => true,
            self::TRISTATE_WITHOUT, '0' => false,
            default => null,
        };
    }

    private static function parsePositiveInt(string $raw): ?int
    {
        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    private static function parseFloat(string $raw): ?float
    {
        $raw = trim(str_replace(',', '.', $raw));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    /**
     * @param array<int|string, mixed> $raw
     *
     * @return list<int>
     */
    private static function parseIntArray(array $raw): array
    {
        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
