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

use BackOfficeDefaultTwigBundle\Service\Customer\CustomerPhoneSearch;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Thelia\Model\AttributeAvQuery;
use Thelia\Model\AttributeCombinationQuery;
use Thelia\Model\Brand;
use Thelia\Model\BrandQuery;
use Thelia\Model\Category;
use Thelia\Model\CategoryQuery;
use Thelia\Model\Content;
use Thelia\Model\ContentQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Folder;
use Thelia\Model\FolderQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;

final readonly class SearchRepository
{
    public function __construct(
        private OrderAccess $orderAccess,
    ) {
    }

    public const DISPLAY_LIMIT = 25;

    /** Fetch one extra row so the presenter can flag truncated result sets. */
    private const SEARCH_LIMIT = self::DISPLAY_LIMIT + 1;

    /** @return ObjectCollection<int, Product> */
    public function findProducts(string $term): ObjectCollection
    {
        /** @var ObjectCollection<int, Product> $result */
        $result = ProductQuery::create()
            ->useProductI18nQuery()
                ->filterByTitle('%'.$term.'%', Criteria::LIKE)
            ->endUse()
            ->distinct()
            ->limit(self::SEARCH_LIMIT)
            ->find();

        return $result;
    }

    /**
     * The combinations an order can be given a line for: those of the visible products whose
     * title or reference contains the term, which is what the order editor of the core accepts.
     *
     * @return list<array{pse_id: int, product_ref: string, title: string, pse_ref: string, combination: string, stock: string}>
     */
    public function findSellableCombinations(string $term, string $locale, int $limit = 20): array
    {
        $term = trim($term);

        if ('' === $term) {
            return [];
        }

        $products = ProductQuery::create()
            ->filterByVisible(1)
            ->useProductI18nQuery(null, Criteria::LEFT_JOIN)
                ->filterByLocale($locale)
            ->endUse()
            ->condition('byTitle', 'product_i18n.title LIKE ?', '%'.$term.'%')
            ->condition('byRef', 'product.ref LIKE ?', '%'.$term.'%')
            ->where(['byTitle', 'byRef'], Criteria::LOGICAL_OR)
            ->distinct()
            ->limit($limit)
            ->find();

        $titles = [];
        foreach ($products as $product) {
            $product->setLocale($locale);
            $titles[(int) $product->getId()] = ['ref' => (string) $product->getRef(), 'title' => (string) $product->getTitle()];
        }

        if ([] === $titles) {
            return [];
        }

        $elements = ProductSaleElementsQuery::create()
            ->filterByProductId(array_keys($titles), Criteria::IN)
            ->orderByProductId()
            ->orderByIsDefault(Criteria::DESC)
            ->orderById()
            ->find()
            ->getArrayCopy();

        $labels = $this->combinationLabels(array_map(static fn ($pse): int => (int) $pse->getId(), $elements), $locale);

        $rows = [];
        foreach (\array_slice($elements, 0, $limit * 5) as $pse) {
            $productId = (int) $pse->getProductId();
            $rows[] = [
                'pse_id' => (int) $pse->getId(),
                'product_ref' => $titles[$productId]['ref'],
                'title' => $titles[$productId]['title'],
                'pse_ref' => (string) $pse->getRef(),
                'combination' => $labels[(int) $pse->getId()] ?? '',
                'stock' => rtrim(rtrim(number_format((float) $pse->getQuantity(), 6, '.', ''), '0'), '.') ?: '0',
            ];
        }

        return $rows;
    }

    /**
     * @param list<int> $productSaleElementsIds
     *
     * @return array<int, string>
     */
    private function combinationLabels(array $productSaleElementsIds, string $locale): array
    {
        if ([] === $productSaleElementsIds) {
            return [];
        }

        $combinations = AttributeCombinationQuery::create()
            ->filterByProductSaleElementsId($productSaleElementsIds, Criteria::IN)
            ->find();

        $valueIds = [];
        foreach ($combinations as $combination) {
            $valueIds[] = (int) $combination->getAttributeAvId();
        }

        $titles = [];
        if ([] !== $valueIds) {
            foreach (AttributeAvQuery::create()->filterById($valueIds, Criteria::IN)->find() as $value) {
                $value->setLocale($locale);
                $titles[(int) $value->getId()] = (string) $value->getTitle();
            }
        }

        $labels = [];
        foreach ($combinations as $combination) {
            $labels[(int) $combination->getProductSaleElementsId()][] = $titles[(int) $combination->getAttributeAvId()] ?? '';
        }

        return array_map(static fn (array $parts): string => implode(' / ', array_filter($parts)), $labels);
    }

    /** @return ObjectCollection<int, Category> */
    public function findCategories(string $term): ObjectCollection
    {
        /** @var ObjectCollection<int, Category> $result */
        $result = CategoryQuery::create()
            ->useCategoryI18nQuery()
                ->filterByTitle('%'.$term.'%', Criteria::LIKE)
            ->endUse()
            ->distinct()
            ->limit(self::SEARCH_LIMIT)
            ->find();

        return $result;
    }

    /** @return ObjectCollection<int, Folder> */
    public function findFolders(string $term): ObjectCollection
    {
        /** @var ObjectCollection<int, Folder> $result */
        $result = FolderQuery::create()
            ->useFolderI18nQuery()
                ->filterByTitle('%'.$term.'%', Criteria::LIKE)
            ->endUse()
            ->distinct()
            ->limit(self::SEARCH_LIMIT)
            ->find();

        return $result;
    }

    /** @return ObjectCollection<int, Content> */
    public function findContents(string $term): ObjectCollection
    {
        /** @var ObjectCollection<int, Content> $result */
        $result = ContentQuery::create()
            ->useContentI18nQuery()
                ->filterByTitle('%'.$term.'%', Criteria::LIKE)
            ->endUse()
            ->distinct()
            ->limit(self::SEARCH_LIMIT)
            ->find();

        return $result;
    }

    /** @return ObjectCollection<int, Brand> */
    public function findBrands(string $term): ObjectCollection
    {
        /** @var ObjectCollection<int, Brand> $result */
        $result = BrandQuery::create()
            ->useBrandI18nQuery()
                ->filterByTitle('%'.$term.'%', Criteria::LIKE)
            ->endUse()
            ->distinct()
            ->limit(self::SEARCH_LIMIT)
            ->find();

        return $result;
    }

    /** @return ObjectCollection<int, Customer> */
    public function findCustomers(string $term): ObjectCollection
    {
        $query = CustomerQuery::create()
            ->filterByFirstname('%'.$term.'%', Criteria::LIKE)
            ->_or()->filterByLastname('%'.$term.'%', Criteria::LIKE)
            ->_or()->filterByEmail('%'.$term.'%', Criteria::LIKE)
            ->_or()->filterByRef('%'.$term.'%', Criteria::LIKE);

        $phoneDigits = CustomerPhoneSearch::digits($term, CustomerPhoneSearch::SEARCH_BOX_MINIMUM_DIGITS);
        if ($phoneDigits !== null) {
            $query->_or()->where(CustomerPhoneSearch::clause(), CustomerPhoneSearch::pattern($phoneDigits), \PDO::PARAM_STR);
        }

        /** @var ObjectCollection<int, Customer> $result */
        $result = $query
            ->limit(self::SEARCH_LIMIT)
            ->find();

        return $result;
    }

    /** @return ObjectCollection<int, Order> */
    public function findOrders(string $term): ObjectCollection
    {
        $like = '%'.$term.'%';
        $query = OrderQuery::create()
            ->condition('ref', 'Order.Ref LIKE ?', $like)
            ->condition('invoice_ref', 'Order.InvoiceRef LIKE ?', $like)
            ->condition('transaction_ref', 'Order.TransactionRef LIKE ?', $like)
            ->where(['ref', 'invoice_ref', 'transaction_ref'], Criteria::LOGICAL_OR);
        // Added after the alternatives, as one more condition they all have to meet.
        $this->orderAccess->restrict($query);

        /** @var ObjectCollection<int, Order> $result */
        $result = $query
            ->limit(self::SEARCH_LIMIT)
            ->find();

        return $result;
    }
}
