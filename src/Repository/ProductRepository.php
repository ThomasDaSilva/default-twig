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

namespace BackOfficeDefaultTwigBundle\Repository;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Propel;
use Thelia\Model\Map\ProductCategoryTableMap;
use Thelia\Model\Product;
use Thelia\Model\ProductCategoryQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

final readonly class ProductRepository
{
    /**
     * Position is stored per category in the product_category pivot, not on the product row.
     * Expose it as a virtual column so the listing reflects the category-scoped order.
     */
    public const CATEGORY_POSITION_COLUMN = 'category_position';

    /**
     * @return ObjectCollection<int, Product>
     */
    public function findByTemplateId(int $templateId): ObjectCollection
    {
        /** @var ObjectCollection<int, Product> $result */
        $result = ProductQuery::create()
            ->filterByTemplateId($templateId)
            ->orderByRef()
            ->find();

        return $result;
    }

    /**
     * @return ObjectCollection<int, Product>
     */
    public function findInCategoryPage(int $categoryId, string $locale, int $offset, int $limit): ObjectCollection
    {
        /** @var ObjectCollection<int, Product> $products */
        $products = ProductQuery::create()
            ->useProductCategoryQuery()
                ->filterByCategoryId($categoryId)
                ->orderByPosition()
            ->endUse()
            ->withColumn(ProductCategoryTableMap::COL_POSITION, self::CATEGORY_POSITION_COLUMN)
            ->offset($offset)
            ->limit($limit)
            ->find();

        foreach ($products as $product) {
            $product->setLocale($locale);
        }

        return $products;
    }

    public function countInCategory(int $categoryId): int
    {
        return ProductCategoryQuery::create()->filterByCategoryId($categoryId)->count();
    }

    public function countAll(): int
    {
        return (int) ProductQuery::create()->count();
    }

    /**
     * Top-selling products on the date range, by units sold and total revenue.
     * Aggregates order_product rows so a sold-out product still appears as long
     * as it has historical sales in the window.
     *
     * @return list<array{id: int, ref: string, title: string, quantity: int, revenue: float}>
     */
    public function findTopSellers(DateRange $range, int $limit, string $locale): array
    {
        $sql = 'SELECT op.product_ref AS ref,
                       SUM(op.quantity) AS quantity_sum,
                       SUM(op.quantity * IF(op.was_in_promo = 1, op.promo_price, op.price)) AS revenue
                FROM order_product op
                JOIN `order` o ON o.id = op.order_id
                WHERE o.created_at BETWEEN :from AND :to
                GROUP BY op.product_ref
                ORDER BY quantity_sum DESC, op.product_ref ASC
                LIMIT '.max(1, $limit);

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute([':from' => $range->fromSql(), ':to' => $range->toSql()]);

        $rows = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $row;
        }
        if ($rows === []) {
            return [];
        }

        $refs = array_column($rows, 'ref');
        /** @var ObjectCollection<int, Product> $products */
        $products = ProductQuery::create()
            ->filterByRef($refs, Criteria::IN)
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->find();

        $byRef = [];
        foreach ($products as $product) {
            $product->setLocale($locale);
            $byRef[(string) $product->getRef()] = $product;
        }

        $items = [];
        foreach ($rows as $row) {
            $product = $byRef[$row['ref']] ?? null;
            $items[] = [
                'id' => $product !== null ? (int) $product->getId() : 0,
                'ref' => (string) $row['ref'],
                'title' => $product !== null ? (string) $product->getTitle() : (string) $row['ref'],
                'quantity' => (int) $row['quantity_sum'],
                'revenue' => (float) $row['revenue'],
            ];
        }

        return $items;
    }

    /**
     * @return list<ProductSaleElements>
     */
    public function findLowStock(int $threshold, int $limit, string $locale): array
    {
        /** @var ObjectCollection<int, ProductSaleElements> $collection */
        $collection = ProductSaleElementsQuery::create()
            ->filterByQuantity($threshold, Criteria::LESS_EQUAL)
            ->orderByQuantity(Criteria::ASC)
            // Several combinations sitting on the same quantity is the normal case;
            // without a tie-break the shop sees a different list on every refresh.
            ->orderById(Criteria::ASC)
            ->limit($limit)
            ->joinWithProduct()
            ->useProductQuery()
                ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->endUse()
            ->find();

        $list = array_values(iterator_to_array($collection));

        foreach ($list as $pse) {
            $pse->getProduct()->setLocale($locale);
        }

        return $list;
    }

    /** @return array{previous: ?int, next: ?int} */
    public function findPreviousNext(Product $current): array
    {
        $defaultCategory = ProductCategoryQuery::create()
            ->filterByProductId($current->getId())
            ->filterByDefaultCategory(true)
            ->findOne();
        if ($defaultCategory === null) {
            return ['previous' => null, 'next' => null];
        }

        $position = (int) $defaultCategory->getPosition();
        $categoryId = (int) $defaultCategory->getCategoryId();

        $previousRow = ProductCategoryQuery::create()
            ->filterByCategoryId($categoryId)
            ->filterByDefaultCategory(true)
            ->filterByPosition($position, Criteria::LESS_THAN)
            ->orderByPosition(Criteria::DESC)
            ->findOne();
        $nextRow = ProductCategoryQuery::create()
            ->filterByCategoryId($categoryId)
            ->filterByDefaultCategory(true)
            ->filterByPosition($position, Criteria::GREATER_THAN)
            ->orderByPosition(Criteria::ASC)
            ->findOne();

        return [
            'previous' => $previousRow !== null ? (int) $previousRow->getProductId() : null,
            'next' => $nextRow !== null ? (int) $nextRow->getProductId() : null,
        ];
    }
}
