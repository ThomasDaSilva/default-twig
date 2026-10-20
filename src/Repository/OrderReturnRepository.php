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

use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnFilters;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Thelia\Model\Map\OrderReturnTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductAttributeCombinationQuery;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnLine;
use Thelia\Model\OrderReturnLineQuery;
use Thelia\Model\OrderReturnQuery;
use Thelia\Model\OrderReturnReason;
use Thelia\Model\OrderReturnReasonQuery;
use Thelia\Model\OrderReturnStatus;
use Thelia\Model\OrderReturnStatusQuery;
use Thelia\Model\ProductQuery;

/**
 * Centralised Propel queries for the return back-office screens.
 *
 * Not readonly: the reads every screen pays for - the statuses with their
 * titles and the return count they carry, the titles of the reasons - are
 * memoised for the request.
 */
final class OrderReturnRepository
{
    /**
     * The sortable columns of the list, named by their fully qualified SQL column
     * so the ORDER BY never depends on how Propel resolves a bare PHP name.
     *
     * @var array<string, string>
     */
    public const SORT_FIELDS = [
        OrderReturnFilters::SORT_ID => OrderReturnTableMap::COL_ID,
        OrderReturnFilters::SORT_REF => OrderReturnTableMap::COL_REF,
        OrderReturnFilters::SORT_CREATED_AT => OrderReturnTableMap::COL_CREATED_AT,
        OrderReturnFilters::SORT_REFUND_AMOUNT => OrderReturnTableMap::COL_REFUND_AMOUNT,
    ];

    private const FALLBACK_STATUS_COLOR = '#6c757d';

    /** @var array<string, list<array{id: int, code: string, effective_code: string, title: string, color: string, count: int}>> */
    private array $statusesWithCounts = [];

    /** @var array<string, list<array{id: int, code: string, effective_code: string, title: string, color: string}>> */
    private array $statusesLocalized = [];

    /** @var array<string, array<int, string>> */
    private array $reasonTitles = [];

    public function __construct(
        private readonly OrderAccess $access,
    ) {
    }

    /**
     * A return is reached through its order: only those of the sale units the administrator reaches.
     */
    private function restrict(OrderReturnQuery $query): OrderReturnQuery
    {
        $this->access->restrictByOrderId($query, OrderReturnTableMap::COL_ORDER_ID);

        return $query;
    }

    /**
     * @return array{rows: ObjectCollection<int, OrderReturn>, total: int, lastPage: int}
     */
    public function findPaginated(OrderReturnFilters $filters, int $page, int $perPage): array
    {
        $query = $this->restrict(OrderReturnQuery::create());
        $filters->applyTo($query);

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $lastPage));

        $sortColumn = self::SORT_FIELDS[$filters->sort] ?? self::SORT_FIELDS[OrderReturnFilters::DEFAULT_SORT];
        $sortDirection = $filters->direction === 'asc' ? Criteria::ASC : Criteria::DESC;

        /** @var ObjectCollection<int, OrderReturn> $rows */
        $rows = $query
            ->joinWithCustomer()
            ->joinWithOrder()
            // Left join: a return always has a status, but a status row deleted by
            // hand would silently drop the return from the page without dropping it
            // from the count above.
            ->joinOrderReturnStatus('ReturnStatus', Criteria::LEFT_JOIN)
            ->with('ReturnStatus')
            ->orderBy($sortColumn, $sortDirection)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->find();

        return ['rows' => $rows, 'total' => $total, 'lastPage' => $lastPage];
    }

    public function findById(int $returnId): ?OrderReturn
    {
        return OrderReturnQuery::create()->findPk($returnId);
    }

    public function findOrder(int $orderId): ?Order
    {
        return OrderQuery::create()->findPk($orderId);
    }

    public function findOrderProduct(int $orderProductId): ?OrderProduct
    {
        return OrderProductQuery::create()->findPk($orderProductId);
    }

    public function findReason(int $reasonId): ?OrderReturnReason
    {
        return OrderReturnReasonQuery::create()->findPk($reasonId);
    }

    /**
     * The returns opened on one order, newest first.
     *
     * @return ObjectCollection<int, OrderReturn>
     */
    public function findByOrder(int $orderId): ObjectCollection
    {
        /** @var ObjectCollection<int, OrderReturn> $result */
        $result = OrderReturnQuery::create()
            ->filterByOrderId($orderId)
            ->joinOrderReturnStatus('ReturnStatus', Criteria::LEFT_JOIN)
            ->with('ReturnStatus')
            ->orderById(Criteria::DESC)
            ->find();

        return $result;
    }

    /**
     * @return ObjectCollection<int, OrderReturnLine>
     */
    public function findLines(int $returnId): ObjectCollection
    {
        /** @var ObjectCollection<int, OrderReturnLine> $result */
        $result = OrderReturnLineQuery::create()
            ->filterByOrderReturnId($returnId)
            ->joinWithOrderProduct()
            ->orderById()
            ->find();

        return $result;
    }

    /**
     * What each of these returns sends back, for a whole page of the queue in three
     * queries whatever its size: the lines with the product as it was ordered, the
     * catalogue product each one still points to, and the attribute values of the
     * ordered combination. A return with no line is absent from the result.
     *
     * The title and the attribute values are those frozen on the order, in the
     * language it was placed in: the product may have been renamed or deleted since.
     *
     * @param list<int> $returnIds
     *
     * @return array<int, list<array{title: string, quantity: float, product_id: ?int, attributes: list<array{attribute: string, value: string}>}>>
     */
    public function findLinesByReturns(array $returnIds): array
    {
        if ($returnIds === []) {
            return [];
        }

        $lines = OrderReturnLineQuery::create()
            ->filterByOrderReturnId($returnIds, Criteria::IN)
            ->joinWithOrderProduct()
            ->orderByOrderReturnId()
            ->orderById()
            ->find();

        if ($lines->isEmpty()) {
            return [];
        }

        $orderProductIds = [];
        $productRefs = [];
        foreach ($lines as $line) {
            $orderProduct = $line->getOrderProduct();
            $orderProductIds[] = (int) $orderProduct->getId();
            $productRefs[(string) $orderProduct->getProductRef()] = true;
        }

        $productIds = [];
        $products = ProductQuery::create()
            ->filterByRef(array_keys($productRefs), Criteria::IN)
            ->select(['Id', 'Ref'])
            ->find();
        foreach ($products as $product) {
            $productIds[(string) $product['Ref']] = (int) $product['Id'];
        }

        $attributes = [];
        $combinations = OrderProductAttributeCombinationQuery::create()
            ->filterByOrderProductId($orderProductIds, Criteria::IN)
            ->orderById()
            ->find();
        foreach ($combinations as $combination) {
            $attributes[(int) $combination->getOrderProductId()][] = [
                'attribute' => (string) $combination->getAttributeTitle(),
                'value' => (string) $combination->getAttributeAvTitle(),
            ];
        }

        $byReturn = [];
        foreach ($lines as $line) {
            $orderProduct = $line->getOrderProduct();
            $byReturn[(int) $line->getOrderReturnId()][] = [
                'title' => (string) $orderProduct->getTitle(),
                'quantity' => (float) $line->getQuantity(),
                'product_id' => $productIds[(string) $orderProduct->getProductRef()] ?? null,
                'attributes' => $attributes[(int) $orderProduct->getId()] ?? [],
            ];
        }

        return $byReturn;
    }

    /**
     * The current title of every reason, by id, in one query memoised for the
     * request: the queue names the reason of each row without a read per row.
     *
     * @return array<int, string>
     */
    public function findReasonTitles(string $locale): array
    {
        return $this->reasonTitles[$locale] ??= $this->readReasonTitles($locale);
    }

    public function countAll(): int
    {
        return (int) $this->restrict(OrderReturnQuery::create())->count();
    }

    /**
     * The statuses with their localized title, for the filter form.
     *
     * @return list<array{id: int, code: string, effective_code: string, title: string, color: string}>
     */
    public function findStatusesLocalized(string $locale): array
    {
        return $this->statusesLocalized[$locale] ??= $this->readStatusesLocalized($locale);
    }

    /**
     * Return-count breakdown by status, for the sidebar and the list header.
     *
     * @return list<array{id: int, code: string, effective_code: string, title: string, color: string, count: int}>
     */
    public function findStatusesWithCounts(string $locale): array
    {
        return $this->statusesWithCounts[$locale.$this->access->sqlCondition()] ??= $this->readStatusesWithCounts($locale);
    }

    /**
     * The previous and next return of the same status, so the merchant walks a
     * queue of requests instead of going back to the list between two of them.
     *
     * @return array{previous: ?int, next: ?int}
     */
    public function findPreviousNext(OrderReturn $current): array
    {
        $previous = $this->restrict(OrderReturnQuery::create())
            ->filterById($current->getId(), Criteria::LESS_THAN)
            ->filterByStatusId($current->getStatusId(), Criteria::EQUAL)
            ->orderById(Criteria::DESC)
            ->findOne();
        $next = $this->restrict(OrderReturnQuery::create())
            ->filterById($current->getId(), Criteria::GREATER_THAN)
            ->filterByStatusId($current->getStatusId(), Criteria::EQUAL)
            ->orderById(Criteria::ASC)
            ->findOne();

        return [
            'previous' => $previous !== null ? (int) $previous->getId() : null,
            'next' => $next !== null ? (int) $next->getId() : null,
        ];
    }

    /**
     * How many requests have been waiting for an answer for more than N hours.
     * One indexed count query, whatever the number of returns.
     */
    public function countPendingOlderThan(int $hours): int
    {
        $statusIds = $this->pendingStatusIds();

        if ($statusIds === []) {
            return 0;
        }

        $threshold = (new \DateTimeImmutable())->modify('-'.$hours.' hours')->format('Y-m-d H:i:s');

        return (int) $this->restrict(OrderReturnQuery::create())
            ->filterByStatusId($statusIds, Criteria::IN)
            ->filterByCreatedAt($threshold, Criteria::LESS_EQUAL)
            ->count();
    }

    /**
     * The ids of the statuses standing for a request still waiting for the
     * merchant. A custom status declaring an equivalence answers for the
     * canonical code it stands for.
     *
     * @return list<int>
     */
    public function pendingStatusIds(): array
    {
        $ids = [];
        foreach (OrderReturnStatusQuery::create()->find() as $status) {
            if ($status->hasStatusHelper(OrderReturnStatus::CODE_REQUESTED)) {
                $ids[] = (int) $status->getId();
            }
        }

        return $ids;
    }

    public function findStatusIdByCode(string $code): ?int
    {
        foreach (OrderReturnStatusQuery::create()->find() as $status) {
            if ($status->getEffectiveCode() === $code) {
                return (int) $status->getId();
            }
        }

        return null;
    }

    /**
     * The visible reasons a merchant may pick when opening a return, localized.
     *
     * @return list<array{id: int, title: string}>
     */
    public function findVisibleReasons(string $locale): array
    {
        $reasons = OrderReturnReasonQuery::create()
            ->filterByVisible(true)
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->orderByPosition()
            ->find();

        $items = [];
        foreach ($reasons as $reason) {
            $reason->setLocale($locale);
            $items[] = ['id' => (int) $reason->getId(), 'title' => (string) $reason->getTitle()];
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    private function readReasonTitles(string $locale): array
    {
        $titles = [];
        $reasons = OrderReturnReasonQuery::create()
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->find();
        foreach ($reasons as $reason) {
            $reason->setLocale($locale);
            $titles[(int) $reason->getId()] = (string) $reason->getTitle();
        }

        return $titles;
    }

    /**
     * @return list<array{id: int, code: string, effective_code: string, title: string, color: string}>
     */
    private function readStatusesLocalized(string $locale): array
    {
        $statuses = OrderReturnStatusQuery::create()
            ->orderByPosition()
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->find();

        $items = [];
        foreach ($statuses as $status) {
            $status->setLocale($locale);
            $items[] = [
                'id' => (int) $status->getId(),
                'code' => (string) $status->getCode(),
                // What the status stands for in the state machine: a custom status
                // declaring an equivalence answers for the canonical code.
                'effective_code' => $status->getEffectiveCode(),
                'title' => (string) $status->getTitle(),
                'color' => (string) ($status->getColor() ?: self::FALLBACK_STATUS_COLOR),
            ];
        }

        return $items;
    }

    /**
     * One return count per status and one read of the statuses with their titles,
     * against one count query per status plus one title query per status.
     *
     * @return list<array{id: int, code: string, effective_code: string, title: string, color: string, count: int}>
     */
    private function readStatusesWithCounts(string $locale): array
    {
        $counts = [];
        $grouped = $this->restrict(OrderReturnQuery::create())
            ->withColumn('COUNT(*)', 'return_count')
            ->groupByStatusId()
            ->select(['StatusId', 'return_count'])
            ->find();

        foreach ($grouped as $row) {
            $counts[(int) $row['StatusId']] = (int) $row['return_count'];
        }

        $items = [];
        foreach ($this->findStatusesLocalized($locale) as $status) {
            $items[] = $status + ['count' => $counts[$status['id']] ?? 0];
        }

        return $items;
    }
}
