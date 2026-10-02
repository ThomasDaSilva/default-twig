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

namespace BackOfficeDefaultTwigBundle\Service\Product;

use BackOfficeDefaultTwigBundle\Service\Catalog\ProductFilterCatalog;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Domain\Catalog\Product\Identifier\GtinDuplicateFinder;
use Thelia\Domain\Catalog\Product\Identifier\GtinSharer;
use Thelia\Model\Attribute;
use Thelia\Model\AttributeAv;
use Thelia\Model\AttributeAvQuery;
use Thelia\Model\AttributeQuery;
use Thelia\Model\AttributeTemplateQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\TaxRule;
use Thelia\Model\TaxRuleQuery;

final readonly class CombinationsTabContextBuilder
{
    public function __construct(
        private GtinDuplicateFinder $duplicateFinder = new GtinDuplicateFinder(),
        private ProductFilterCatalog $catalog = new ProductFilterCatalog(),
    ) {
    }

    /**
     * @return array{
     *     product: Product,
     *     pse_rows: list<array<string, mixed>>,
     *     has_combinations: bool,
     *     default_pse: array<string, mixed>|null,
     *     tax_rule_id: int,
     *     tax_rules: list<array{id: int, title: string}>,
     *     currency: array{id: int, symbol: string, name: string, code: string, is_default: bool},
     *     template_attributes: list<array{id: int, title: string, values: list<array{id: int, title: string}>}>
     * }
     */
    public function build(Product $product, ?int $currencyId = null): array
    {
        $locale = $this->defaultLocale();
        $currency = $this->resolveCurrency($currencyId);
        $pseRecords = ProductSaleElementsQuery::create()
            ->filterByProductId((int) $product->getId())
            ->orderByPosition()
            ->orderById()
            ->find();

        $attributePositions = $this->templateAttributePositions($product);

        $entries = [];
        $hasCombinations = false;
        foreach ($pseRecords as $pse) {
            \assert($pse instanceof ProductSaleElements);
            $combinationLabels = [];
            $attributeOrder = [];
            foreach ($pse->getAttributeCombinations() as $combination) {
                $attribute = $combination->getAttribute();
                $attributeAv = $combination->getAttributeAv();
                if ($attribute === null || $attributeAv === null) {
                    continue;
                }
                $attribute->setLocale($locale);
                $attributeAv->setLocale($locale);
                $combinationLabels[] = (string) $attribute->getTitle().': '.(string) $attributeAv->getTitle();
                $attributeOrder[] = [
                    $attributePositions[(int) $attribute->getId()] ?? (int) $attribute->getPosition(),
                    (int) $attributeAv->getPosition(),
                ];
            }
            sort($attributeOrder);
            if ($combinationLabels !== []) {
                $hasCombinations = true;
            }

            $price = $pse->getPricesByCurrency($currency);
            $row = [
                'id' => (int) $pse->getId(),
                'label' => $combinationLabels === [] ? 'default' : implode(' / ', $combinationLabels),
                'ref' => (string) $pse->getRef(),
                'price' => $price !== null ? (float) $price->getPrice() : 0.0,
                'sale_price' => $price !== null ? (float) $price->getPromoPrice() : 0.0,
                'quantity' => (float) $pse->getQuantity(),
                'weight' => (float) $pse->getWeight(),
                'ean_code' => (string) $pse->getEanCode(),
                'mpn' => (string) $pse->getMpn(),
                'manufacturer_brand_id' => (int) $pse->getManufacturerBrandId(),
                'gtin_shared_with' => [],
                'onsale' => (bool) $pse->getPromo(),
                'isnew' => (bool) $pse->getNewness(),
                'isdefault' => (bool) $pse->getIsDefault(),
                'visible' => (bool) $pse->getVisible(),
                'position' => (int) $pse->getPosition(),
            ];
            $entries[] = ['row' => $row, 'attribute_order' => $attributeOrder];
        }

        // The query already ordered by position then id. Combinations tied on their
        // position (every one of them on a shop migrated from Thelia 2) are then read
        // in the order of their attributes and attribute values.
        usort($entries, $this->compareEntries(...));

        $rows = $this->withSharedCodes(array_column($entries, 'row'));
        $defaultPse = null;
        foreach ($rows as $row) {
            if ($row['isdefault']) {
                $defaultPse = $row;
                break;
            }
        }

        if ($defaultPse === null && $rows !== []) {
            $defaultPse = $rows[0];
        }

        return [
            'product' => $product,
            'pse_rows' => $rows,
            'has_combinations' => $hasCombinations,
            'default_pse' => $defaultPse,
            'tax_rule_id' => (int) $product->getTaxRuleId(),
            'tax_rules' => $this->collectTaxRules($locale),
            'currency' => [
                'id' => (int) $currency->getId(),
                'symbol' => (string) $currency->getSymbol(),
                'name' => (string) $currency->getName(),
                'code' => (string) $currency->getCode(),
                'is_default' => (bool) $currency->getByDefault(),
            ],
            'available_currencies' => $this->collectCurrencies(),
            'template_attributes' => $this->collectTemplateAttributes($product, $locale),
            'brand_options' => $this->catalog->brands($locale),
            'product_brand_title' => $this->productBrandTitle($product, $locale),
        ];
    }

    /**
     * Marks the rows whose GTIN another combination of the shop carries too, with the
     * references of those combinations: the tab is rendered as a sub-request, so the
     * warning shown after a save cannot be read from the flash messages here.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function withSharedCodes(array $rows): array
    {
        $sharersById = $this->duplicateFinder->sharersAmong(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        foreach ($rows as $index => $row) {
            $rows[$index]['gtin_shared_with'] = array_map(
                static fn (GtinSharer $sharer): string => $sharer->productRef.' / '.$sharer->productSaleElementsRef,
                $sharersById[(int) $row['id']] ?? [],
            );
        }

        return $rows;
    }

    private function productBrandTitle(Product $product, string $locale): ?string
    {
        $brand = $product->getBrand();

        return $brand === null ? null : (string) $brand->setLocale($locale)->getTitle();
    }

    /**
     * @param array{row: array<string, mixed>, attribute_order: list<array{int, int}>} $left
     * @param array{row: array<string, mixed>, attribute_order: list<array{int, int}>} $right
     */
    private function compareEntries(array $left, array $right): int
    {
        return [$left['row']['position'], $left['attribute_order'], $left['row']['id']]
            <=> [$right['row']['position'], $right['attribute_order'], $right['row']['id']];
    }

    /**
     * Position of each attribute of the product's template, by attribute id.
     *
     * @return array<int, int>
     */
    private function templateAttributePositions(Product $product): array
    {
        $templateId = (int) ($product->getTemplateId() ?? 0);
        if ($templateId <= 0) {
            return [];
        }

        $positions = [];
        foreach (AttributeTemplateQuery::create()->filterByTemplateId($templateId)->find() as $entry) {
            $positions[(int) $entry->getAttributeId()] = (int) $entry->getPosition();
        }

        return $positions;
    }

    /**
     * @return list<array{id: int, code: string, symbol: string}>
     */
    private function collectCurrencies(): array
    {
        $currencies = [];
        foreach (CurrencyQuery::create()->orderByPosition()->find() as $currency) {
            $currencies[] = [
                'id' => (int) $currency->getId(),
                'code' => (string) $currency->getCode(),
                'symbol' => (string) $currency->getSymbol(),
            ];
        }

        return $currencies;
    }

    /**
     * @return list<array{id: int, title: string, values: list<array{id: int, title: string}>}>
     */
    private function collectTemplateAttributes(Product $product, string $locale): array
    {
        $templateId = (int) ($product->getTemplateId() ?? 0);
        if ($templateId <= 0) {
            return [];
        }

        $attributeIds = [];
        foreach (AttributeTemplateQuery::create()->filterByTemplateId($templateId)->orderByPosition()->find() as $entry) {
            $attributeIds[] = (int) $entry->getAttributeId();
        }
        if ($attributeIds === []) {
            return [];
        }

        $attributes = AttributeQuery::create()->filterById($attributeIds, Criteria::IN)->find();
        $byId = [];
        foreach ($attributes as $attribute) {
            \assert($attribute instanceof Attribute);
            $attribute->setLocale($locale);
            $byId[(int) $attribute->getId()] = $attribute;
        }

        $items = [];
        foreach ($attributeIds as $attributeId) {
            if (!isset($byId[$attributeId])) {
                continue;
            }
            $attribute = $byId[$attributeId];

            $values = [];
            $avRecords = AttributeAvQuery::create()
                ->filterByAttributeId($attributeId)
                ->orderByPosition()
                ->find();
            foreach ($avRecords as $av) {
                \assert($av instanceof AttributeAv);
                $av->setLocale($locale);
                $values[] = [
                    'id' => (int) $av->getId(),
                    'title' => (string) $av->getTitle(),
                ];
            }

            if ($values === []) {
                continue;
            }

            $items[] = [
                'id' => $attributeId,
                'title' => (string) $attribute->getTitle(),
                'values' => $values,
            ];
        }

        return $items;
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function collectTaxRules(string $locale): array
    {
        $rules = [];
        foreach (TaxRuleQuery::create()->orderById()->find() as $taxRule) {
            \assert($taxRule instanceof TaxRule);
            $taxRule->setLocale($locale);
            $rules[] = [
                'id' => (int) $taxRule->getId(),
                'title' => (string) $taxRule->getTitle(),
            ];
        }

        return $rules;
    }

    private function defaultLocale(): string
    {
        return LangQuery::create()->findOneByByDefault(1)?->getLocale() ?? 'en_US';
    }

    private function resolveCurrency(?int $currencyId): Currency
    {
        if ($currencyId !== null && $currencyId > 0) {
            $currency = CurrencyQuery::create()->findPk($currencyId);
            if ($currency !== null) {
                return $currency;
            }
        }

        $currency = CurrencyQuery::create()->findOneByByDefault(1);
        if ($currency !== null) {
            return $currency;
        }
        $first = CurrencyQuery::create()->orderByPosition()->findOne();
        if ($first === null) {
            throw new \RuntimeException('No currency configured.');
        }

        return $first;
    }
}
