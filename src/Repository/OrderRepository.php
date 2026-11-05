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
use BackOfficeDefaultTwigBundle\Service\Bundle\BundleCore;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use BackOfficeDefaultTwigBundle\Service\Order\OrderFilters;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderReturnLineQuery;
use Thelia\Model\OrderShipmentItemQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;

/**
 * Centralised Propel queries for the order back-office screens.
 *
 * Not readonly: the reads every page pays for — the status list with its titles,
 * the order count per status, the ids a canonical status code answers for — are
 * memoised for the request. The sidebar asks for them on every screen and the
 * dashboard asks for the same figures again.
 *
 * Every read of orders but the one by identifier goes through the sale units the
 * administrator reaches (see OrderAccess); the controllers ask for that one to be checked.
 */
final class OrderRepository
{
    public const SORT_FIELDS = [
        'id' => 'id',
        'ref' => 'ref',
        'created_at' => 'createdAt',
        'amount' => 'id', // total is computed; we fall back to id (insertion order ≈ creation order).
    ];

    /**
     * What "the shop earned it" means: the money is in, whatever is left to do with the
     * parcel. A cancelled or refunded order is not revenue, and one still waiting for its
     * payment is not revenue yet.
     */
    private const REVENUE_STATUS_CODES = [OrderStatus::CODE_PAID, OrderStatus::CODE_PROCESSING, OrderStatus::CODE_SENT];

    private const AWAITING_SHIPMENT_STATUS_CODES = [OrderStatus::CODE_PAID, OrderStatus::CODE_PROCESSING];

    private const FALLBACK_STATUS_COLOR = '#6c757d';

    /** @var array<string, list<array{id: int, code: string, title: string, color: string, count: int}>> */
    private array $statusesWithCounts = [];

    public function __construct(
        private readonly OrderAccess $access,
    ) {
    }

    /**
     * The status rows change rarely and through these events only; the counts
     * change with every order, and a memo per request is what the sidebar wants.
     */
    #[AsEventListener(event: TheliaEvents::ORDER_STATUS_CREATE, priority: -128)]
    #[AsEventListener(event: TheliaEvents::ORDER_STATUS_UPDATE, priority: -128)]
    #[AsEventListener(event: TheliaEvents::ORDER_STATUS_DELETE, priority: -128)]
    #[AsEventListener(event: TheliaEvents::ORDER_STATUS_UPDATE_POSITION, priority: -128)]
    public function reset(): void
    {
        $this->statusesWithCounts = [];
    }

    /**
     * @return array{rows: ObjectCollection<int, Order>, total: int, lastPage: int}
     */
    public function findPaginated(OrderFilters $filters, int $page, int $perPage): array
    {
        $query = $this->buildFilteredQuery($filters);

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $lastPage));

        $sortColumn = self::SORT_FIELDS[$filters->sort] ?? self::SORT_FIELDS[OrderFilters::DEFAULT_SORT];
        $sortDirection = $filters->direction === 'asc' ? Criteria::ASC : Criteria::DESC;

        /** @var ObjectCollection<int, Order> $rows */
        $rows = $query
            ->joinWithCustomer()
            ->joinWithOrderAddressRelatedByDeliveryOrderAddressId()
            // Left joins: an order whose module has been deleted names it through the frozen
            // title and no longer points at a module row. An inner join would drop it from
            // the page without dropping it from the count above.
            ->joinModuleRelatedByPaymentModuleId('PaymentModule', Criteria::LEFT_JOIN)
            ->with('PaymentModule')
            ->joinModuleRelatedByDeliveryModuleId('DeliveryModule', Criteria::LEFT_JOIN)
            ->with('DeliveryModule')
            ->orderBy('order.'.$sortColumn, $sortDirection)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->find();

        return ['rows' => $rows, 'total' => $total, 'lastPage' => $lastPage];
    }

    public function findById(int $orderId): ?Order
    {
        return OrderQuery::create()->findPk($orderId);
    }

    /**
     * What a bulk status change needs to decide: the identifier, the reference to
     * name a skipped order with, and the status the graph starts from. Three
     * columns, no object, nothing the decision does not read.
     *
     * @param list<int> $orderIds
     *
     * @return list<array{id: int, ref: string, status_id: int}>
     */
    public function findStatusDecisionRows(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $decisions = [];

        $query = OrderQuery::create()->filterById($orderIds, Criteria::IN);
        $this->access->restrict($query);

        foreach ($query->select(['Id', 'Ref', 'StatusId'])->find() as $row) {
            $decisions[] = [
                'id' => (int) $row['Id'],
                'ref' => (string) $row['Ref'],
                'status_id' => (int) $row['StatusId'],
            ];
        }

        return $decisions;
    }

    /**
     * @param list<int> $orderIds
     *
     * @return list<Order>
     */
    public function findByIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $query = OrderQuery::create()->filterById($orderIds, Criteria::IN);
        $this->access->restrict($query);

        return iterator_to_array($query->find(), false);
    }

    public function countItemsForOrder(int $orderId): int
    {
        return OrderProductQuery::create()->filterByOrderId($orderId)->count();
    }

    /**
     * How many lines each of these orders holds, in one grouped query. An order
     * with no line is absent from the result, as a count of zero.
     *
     * @param list<int> $orderIds
     *
     * @return array<int, int>
     */
    public function countItemsByOrder(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $counts = [];
        $rows = OrderProductQuery::create()
            ->filterByOrderId($orderIds, Criteria::IN)
            ->withColumn('COUNT(*)', 'item_count')
            ->groupByOrderId()
            ->select(['OrderId', 'item_count'])
            ->find();

        foreach ($rows as $row) {
            $counts[(int) $row['OrderId']] = (int) $row['item_count'];
        }

        return $counts;
    }

    /**
     * The orders, among these, that hold at least one preorder line, in one query.
     * An order whose lines are all available is absent from the result.
     *
     * @param list<int> $orderIds
     *
     * @return list<int>
     */
    public function findPreorderOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $found = OrderProductQuery::create()
            ->filterByOrderId($orderIds, Criteria::IN)
            ->filterByIsPreorder(1)
            ->select(['OrderId'])
            ->distinct()
            ->find();

        return array_map(intval(...), $found->toArray());
    }

    /**
     * The amount each of these orders is worth, in one query instead of one per
     * order, and to the cent what Order::getTotalAmount() answers.
     *
     * Only the taxed total of the lines is read in SQL — the very figure the model
     * reads back — and the discount, its clamp at zero and the postage are then
     * applied here in the same order and the same arithmetic as the model applies
     * them. Doing the whole sum in SQL would settle a half-cent tie on DECIMAL
     * rather than on the float the model rounds, and the two can land on different
     * cents.
     *
     * @param list<int> $orderIds
     *
     * @return array<int, float>
     */
    public function findTotalAmountByOrder(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, \count($orderIds), '?'));

        $sql = 'SELECT '.OrderTableMap::COL_ID.' AS order_id,
                       '.OrderFilters::taxedItemsTotalSqlExpression().' AS items_total,
                       '.OrderTableMap::COL_DISCOUNT.' AS discount,
                       '.OrderTableMap::COL_POSTAGE.' AS postage
                FROM `order`
                WHERE '.OrderTableMap::COL_ID.' IN ('.$placeholders.')';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute($orderIds);

        $totals = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $total = (float) $row['items_total'] - (float) $row['discount'];

            if ($total < 0) {
                $total = 0.0;
            }

            $totals[(int) $row['order_id']] = $total + (float) $row['postage'];
        }

        return $totals;
    }

    /**
     * The figures of one customer's orders, in one query: how many orders the shop earned
     * money from and how much, their first and last dates, the currencies they were placed
     * in, and how many orders were left out (cancelled, refunded, still unpaid).
     *
     * The amounts are summed per order in a derived table first, like the dashboard
     * revenue, because MariaDB resolves a correlated sub-query unexpectedly inside an
     * aggregate.
     *
     * @return array{revenue_count: int, excluded_count: int, total: float, first_at: ?\DateTimeImmutable, last_at: ?\DateTimeImmutable, currency_count: int, currency_id: ?int}
     */
    public function findCustomerRevenueStats(int $customerId): array
    {
        $sql = 'SELECT
                COALESCE(SUM(is_revenue), 0) AS revenue_count,
                COUNT(*) - COALESCE(SUM(is_revenue), 0) AS excluded_count,
                COALESCE(SUM(CASE WHEN is_revenue = 1 THEN computed ELSE 0 END), 0) AS total,
                MIN(CASE WHEN is_revenue = 1 THEN created_at END) AS first_at,
                MAX(CASE WHEN is_revenue = 1 THEN created_at END) AS last_at,
                COUNT(DISTINCT CASE WHEN is_revenue = 1 THEN currency_id END) AS currency_count,
                MIN(CASE WHEN is_revenue = 1 THEN currency_id END) AS currency_id
            FROM (
                SELECT '.OrderTableMap::COL_CREATED_AT.' AS created_at,
                       '.OrderTableMap::COL_CURRENCY_ID.' AS currency_id,
                       CASE WHEN '.OrderTableMap::COL_STATUS_ID.' IN ('.$this->statusIdListSql(self::REVENUE_STATUS_CODES).') THEN 1 ELSE 0 END AS is_revenue,
                       '.OrderFilters::totalAmountSqlExpression().' AS computed
                FROM `order`
                WHERE '.OrderTableMap::COL_CUSTOMER_ID.' = :customer'.$this->access->sqlCondition().'
            ) AS per_order';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute([':customer' => $customerId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'revenue_count' => (int) ($row['revenue_count'] ?? 0),
            'excluded_count' => (int) ($row['excluded_count'] ?? 0),
            'total' => (float) ($row['total'] ?? 0),
            'first_at' => !empty($row['first_at']) ? new \DateTimeImmutable((string) $row['first_at']) : null,
            'last_at' => !empty($row['last_at']) ? new \DateTimeImmutable((string) $row['last_at']) : null,
            'currency_count' => (int) ($row['currency_count'] ?? 0),
            'currency_id' => isset($row['currency_id']) ? (int) $row['currency_id'] : null,
        ];
    }

    public function countByCustomer(int $customerId): int
    {
        $query = OrderQuery::create()->filterByCustomerId($customerId);
        $this->access->restrict($query);

        return (int) $query->count();
    }

    /**
     * @return ObjectCollection<int, Order>
     */
    public function findRecentByCustomer(int $customerId, int $limit = 25): ObjectCollection
    {
        $query = OrderQuery::create()->filterByCustomerId($customerId);
        $this->access->restrict($query);

        /** @var ObjectCollection<int, Order> $result */
        $result = $query
            ->orderByCreatedAt(Criteria::DESC)
            ->limit($limit)
            ->find();

        return $result;
    }

    /**
     * @return ObjectCollection<int, Order>
     */
    public function findByCustomerPage(int $customerId, int $page, int $perPage): ObjectCollection
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $query = OrderQuery::create()->filterByCustomerId($customerId);
        $this->access->restrict($query);

        /** @var ObjectCollection<int, Order> $result */
        $result = $query
            ->orderByCreatedAt(Criteria::DESC)
            ->offset($offset)
            ->limit($perPage)
            ->find();

        return $result;
    }

    /**
     * The lines that stand on their own: a bundle line counts as one, its components do not.
     */
    public function countOrderProducts(int $orderId): int
    {
        $query = OrderProductQuery::create()->filterByOrderId($orderId);

        // A core without bundles has no line type: every line stands on its own. The parent column
        // is older than the bundles and says nothing of them: the type of the line does.
        return (BundleCore::present() ? $query->filterByLineType(OrderProduct::LINE_TYPE_BUNDLE_COMPONENT, Criteria::NOT_EQUAL) : $query)->count();
    }

    /**
     * @return list<OrderProduct>
     */
    public function findAllOrderProducts(int $orderId): array
    {
        return array_values(OrderProductQuery::create()->filterByOrderId($orderId)->orderById()->find()->getArrayCopy());
    }

    /**
     * @param list<int> $lineIds
     *
     * @return array<int, true>
     */
    public function findOrderProductIdsInUse(array $lineIds): array
    {
        if ([] === $lineIds) {
            return [];
        }

        $inUse = [];
        $shipped = OrderShipmentItemQuery::create()->filterByOrderProductId($lineIds)->select('OrderProductId')->find();
        $returned = OrderReturnLineQuery::create()->filterByOrderProductId($lineIds)->select('OrderProductId')->find();

        foreach ([...$shipped, ...$returned] as $lineId) {
            $inUse[(int) $lineId] = true;
        }

        return $inUse;
    }

    /**
     * A page of the lines that stand on their own, each followed by its own components: the
     * pages are cut between lines, never between a bundle and what it holds.
     *
     * @return list<OrderProduct>
     */
    public function findOrderProductsPage(int $orderId, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $query = OrderProductQuery::create()->filterByOrderId($orderId);
        if (BundleCore::present()) {
            $query->filterByLineType(OrderProduct::LINE_TYPE_BUNDLE_COMPONENT, Criteria::NOT_EQUAL);
        }
        $roots = $query->orderById()->offset($offset)->limit($perPage)->find();

        $rootIds = [];
        foreach ($roots as $root) {
            $rootIds[] = (int) $root->getId();
        }

        $componentsByRoot = [];
        if (BundleCore::present() && [] !== $rootIds) {
            foreach (OrderProductQuery::create()->filterByOrderId($orderId)->filterByLineType(OrderProduct::LINE_TYPE_BUNDLE_COMPONENT)->filterByParent($rootIds, Criteria::IN)->orderById()->find() as $component) {
                $componentsByRoot[(int) $component->getParent()][] = $component;
            }
        }

        $lines = [];
        foreach ($roots as $root) {
            $lines[] = $root;
            foreach ($componentsByRoot[(int) $root->getId()] ?? [] as $component) {
                $lines[] = $component;
            }
        }

        return $lines;
    }

    /**
     * @return ObjectCollection<int, OrderStatus>
     */
    public function findAllStatuses(): ObjectCollection
    {
        /** @var ObjectCollection<int, OrderStatus> $result */
        $result = OrderStatusQuery::create()->orderById()->find();

        return $result;
    }

    /**
     * First entry is a synthetic "all statuses" option (id = 0).
     *
     * @return list<array{id: int, title: string, code: string}>
     */
    public function findStatusChoices(string $locale, string $allLabel): array
    {
        $items = [['id' => 0, 'title' => $allLabel, 'code' => '']];

        $statuses = OrderStatusQuery::create()
            ->orderByPosition()
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->find();

        foreach ($statuses as $status) {
            $status->setLocale($locale);
            $items[] = [
                'id' => (int) $status->getId(),
                'title' => (string) $status->getTitle(),
                'code' => (string) $status->getCode(),
            ];
        }

        return $items;
    }

    /**
     * Includes native colour. No synthetic "all" option (the multi-select handles it).
     *
     * @return list<array{id: int, title: string, code: string, color: string}>
     */
    public function findStatusesLocalized(string $locale): array
    {
        $items = [];
        $statuses = OrderStatusQuery::create()
            ->orderByPosition()
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->find();

        foreach ($statuses as $status) {
            $status->setLocale($locale);
            $items[] = [
                'id' => (int) $status->getId(),
                'title' => (string) $status->getTitle(),
                'code' => (string) $status->getCode(),
                'color' => (string) ($status->getColor() ?: self::FALLBACK_STATUS_COLOR),
            ];
        }

        return $items;
    }

    /**
     * Restricted to statuses currently hosting at least one order. Used by the sidebar counters.
     *
     * @return list<array{id: int, code: string, title: string, color: string, count: int}>
     */
    public function findStatusesWithCounts(string $locale): array
    {
        // An administrator's reach is part of the answer: the memo is kept per reach.
        return $this->statusesWithCounts[$locale.$this->access->sqlCondition()] ??= $this->readStatusesWithCounts($locale);
    }

    /**
     * One order count per status and one read of the statuses with their titles,
     * against one count query per status plus one title query per status.
     *
     * @return list<array{id: int, code: string, title: string, color: string, count: int}>
     */
    private function readStatusesWithCounts(string $locale): array
    {
        $counts = [];
        $groupedQuery = OrderQuery::create();
        $this->access->restrict($groupedQuery);
        $grouped = $groupedQuery
            ->withColumn('COUNT(*)', 'order_count')
            ->groupByStatusId()
            ->select(['StatusId', 'order_count'])
            ->find();

        foreach ($grouped as $row) {
            $counts[(int) $row['StatusId']] = (int) $row['order_count'];
        }

        $statuses = OrderStatusQuery::create()
            ->orderById()
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->find();

        $items = [];
        foreach ($statuses as $status) {
            $statusId = (int) $status->getId();
            // A status currently hosting no order stays off the sidebar.
            if (($counts[$statusId] ?? 0) === 0) {
                continue;
            }

            $status->setLocale($locale);

            $items[] = [
                'id' => $statusId,
                'code' => (string) $status->getCode(),
                'title' => (string) $status->getTitle(),
                'color' => (string) ($status->getColor() ?: self::FALLBACK_STATUS_COLOR),
                'count' => $counts[$statusId],
            ];
        }

        return $items;
    }

    public function countAll(): int
    {
        $query = OrderQuery::create();
        $this->access->restrict($query);

        return $query->count();
    }

    /**
     * Sum of computed totals on the orders created in $range the shop has been paid for.
     * Used by the dashboard revenue KPI.
     */
    public function getRevenue(DateRange $range): float
    {
        $sql = 'SELECT COALESCE(SUM(computed), 0) AS revenue FROM (
            SELECT '.OrderFilters::totalAmountSqlExpression().' AS computed
            FROM `order`
            WHERE created_at BETWEEN :from AND :to
              AND status_id IN ('.$this->statusIdListSql(self::REVENUE_STATUS_CODES).')'.$this->access->sqlCondition().'
        ) AS sub';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute([':from' => $range->fromSql(), ':to' => $range->toSql()]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return (float) ($row['revenue'] ?? 0);
    }

    public function countOrders(DateRange $range): int
    {
        $query = OrderQuery::create();
        $this->access->restrict($query);

        return (int) $query
            ->filterByCreatedAt($range->fromSql(), Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($range->toSql(), Criteria::LESS_EQUAL)
            ->count();
    }

    /**
     * Daily revenue and order count buckets, one row per day in the range
     * (filled with zeroes when no order). Single SQL pass with GROUP BY.
     *
     * @return array{labels: list<string>, revenue: list<float>, orders: list<int>}
     */
    public function getDailyBuckets(DateRange $range): array
    {
        $sql = 'SELECT DATE(`order`.created_at) AS day, COUNT(*) AS orders_count,
                COALESCE(SUM(IF(`order`.status_id IN ('.$this->statusIdListSql(self::REVENUE_STATUS_CODES).'),
                    '.OrderFilters::totalAmountSqlExpression().', 0)), 0) AS revenue
                FROM `order`
                WHERE created_at BETWEEN :from AND :to'.$this->access->sqlCondition().'
                GROUP BY DATE(`order`.created_at)';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute([':from' => $range->fromSql(), ':to' => $range->toSql()]);

        $rows = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $rows[$row['day']] = ['revenue' => (float) $row['revenue'], 'orders' => (int) $row['orders_count']];
        }

        $labels = [];
        $revenue = [];
        $orders = [];
        $cursor = $range->from->setTime(0, 0);
        $end = $range->to->setTime(0, 0);
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $labels[] = $cursor->format('d/m');
            $revenue[] = $rows[$key]['revenue'] ?? 0.0;
            $orders[] = $rows[$key]['orders'] ?? 0;
            $cursor = $cursor->modify('+1 day');
        }

        return ['labels' => $labels, 'revenue' => $revenue, 'orders' => $orders];
    }

    /**
     * Order-count breakdown by status (only statuses with at least one order).
     *
     * @return list<array{id: int, code: string, title: string, color: string, count: int}>
     */
    public function getStatusBreakdown(string $locale): array
    {
        return $this->findStatusesWithCounts($locale);
    }

    public function countUnpaidOlderThan(int $hours): int
    {
        $statusIds = $this->unpaidStatusIds();

        if ([] === $statusIds) {
            return 0;
        }

        $threshold = (new \DateTimeImmutable())->modify('-'.$hours.' hours')->format('Y-m-d H:i:s');

        $query = OrderQuery::create();
        $this->access->restrict($query);

        return (int) $query
            ->filterByStatusId($statusIds, Criteria::IN)
            ->filterByCreatedAt($threshold, Criteria::LESS_EQUAL)
            ->count();
    }

    public function countAwaitingShipment(): int
    {
        $statusIds = $this->awaitingShipmentStatusIds();

        if ([] === $statusIds) {
            return 0;
        }

        $query = OrderQuery::create();
        $this->access->restrict($query);

        return (int) $query
            ->filterByStatusId($statusIds, Criteria::IN)
            ->count();
    }

    /**
     * The statuses an order still waiting for its payment sits in. Exposed so that the
     * dashboard alert and the order list it links to select the same orders.
     *
     * @return list<int>
     */
    public function unpaidStatusIds(): array
    {
        return $this->statusIdsAnsweringFor([OrderStatus::CODE_NOT_PAID]);
    }

    /**
     * @return list<int>
     */
    public function awaitingShipmentStatusIds(): array
    {
        return $this->statusIdsAnsweringFor(self::AWAITING_SHIPMENT_STATUS_CODES);
    }

    /**
     * Restricted to countries actually appearing on a delivery address (avoids
     * a 250+ entry dropdown).
     *
     * @return list<int>
     */
    public function findReferencedDeliveryCountryIds(): array
    {
        $sql = '
            SELECT DISTINCT oa.country_id
            FROM `order` o
            JOIN order_address oa ON oa.id = o.delivery_order_address_id
            WHERE oa.country_id IS NOT NULL'.$this->access->sqlCondition('o.sale_unit_id').'
        ';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute();

        $ids = [];
        while (($row = $statement->fetch(\PDO::FETCH_NUM)) !== false) {
            $id = (int) $row[0];
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Adaptive bounds for the amount range slider. Reuses the same total
     * formula as the filter to stay consistent across UI and SQL.
     *
     * @return array{min: float, max: float, step: int}
     */
    public function getAmountBounds(): array
    {
        $sql = 'SELECT MAX(computed) AS max_val FROM (
            SELECT '.OrderFilters::totalAmountSqlExpression().' AS computed
            FROM `order`'.$this->access->sqlWhere().'
        ) AS sub';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $rawMax = (float) ($row['max_val'] ?? 0);

        $niceMax = self::roundUpToNice($rawMax);
        $step = $niceMax >= 5000 ? 100 : ($niceMax >= 1000 ? 50 : 10);

        return ['min' => 0.0, 'max' => (float) $niceMax, 'step' => $step];
    }

    /**
     * Adaptive bounds for the item-count range slider.
     *
     * @return array{min: int, max: int, step: int}
     */
    public function getItemsBounds(): array
    {
        $sql = 'SELECT MAX(item_count) AS max_val FROM (
            SELECT COUNT(*) AS item_count FROM order_product'.$this->itemsOfReachedOrdersSql().'
            GROUP BY order_id
        ) AS sub';

        $statement = Propel::getConnection()->prepare($sql);
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $rawMax = (int) ($row['max_val'] ?? 0);

        $niceMax = max(10, (int) (ceil(max(1, $rawMax) / 5) * 5));

        return ['min' => 0, 'max' => $niceMax, 'step' => 1];
    }

    /**
     * Ids of the statuses that answer for one of these canonical codes. A shop adds
     * statuses of its own and says which native one each of them stands for, so a status
     * is matched on the code it answers for and never on the code it is named by.
     *
     * @param list<string> $codes
     *
     * @return list<int>
     */
    private function statusIdsAnsweringFor(array $codes): array
    {
        // Deliberately not memoised: a caller holding this repository across a
        // status being created expects the next read to see it, and the dashboard
        // integration tests do exactly that.
        $ids = [];

        /** @var OrderStatus $status */
        foreach (OrderStatusQuery::create()->find() as $status) {
            if ($status->hasStatusHelper($codes)) {
                $ids[] = (int) $status->getId();
            }
        }

        return $ids;
    }

    /**
     * The statuses an order counts as revenue in, as a SQL list for the customer queries
     * written in raw SQL: paid, processing, sent, and any status of the shop's own that
     * answers for one of them. Exposed so that every figure calling itself revenue or money
     * spent (the dashboard, the customer list, the customer sheet) adds up the same orders.
     */
    public function revenueStatusIdListSql(): string
    {
        return $this->statusIdListSql(self::REVENUE_STATUS_CODES);
    }

    /**
     * The same ids as a SQL list, for the queries written in raw SQL. Every value comes from
     * a primary key read back from the database and is cast to an integer here; an empty
     * list becomes `0`, which no primary key matches.
     *
     * @param list<string> $codes
     */
    private function statusIdListSql(array $codes): string
    {
        $ids = $this->statusIdsAnsweringFor($codes);

        return [] === $ids ? '0' : implode(', ', $ids);
    }

    private function itemsOfReachedOrdersSql(): string
    {
        $condition = $this->access->sqlWhere();

        return '' === $condition ? '' : ' WHERE order_id IN (SELECT id FROM `order`'.$condition.')';
    }

    private function buildFilteredQuery(OrderFilters $filters): OrderQuery
    {
        $query = OrderQuery::create();
        $filters->applyTo($query);
        $this->access->restrict($query);

        return $query;
    }

    private static function roundUpToNice(float $value): int
    {
        if ($value <= 100) {
            return 100;
        }
        if ($value <= 500) {
            return 500;
        }
        if ($value <= 1000) {
            return 1000;
        }
        if ($value <= 5000) {
            return (int) (ceil($value / 500) * 500);
        }

        return (int) (ceil($value / 1000) * 1000);
    }

    /** @return array{previous: ?int, next: ?int} */
    public function findPreviousNext(Order $current): array
    {
        $previousQuery = OrderQuery::create();
        $this->access->restrict($previousQuery);
        $previous = $previousQuery
            ->filterById($current->getId(), Criteria::LESS_THAN)
            ->filterByStatusId($current->getStatusId(), Criteria::EQUAL)
            ->orderById(Criteria::DESC)
            ->findOne();
        $nextQuery = OrderQuery::create();
        $this->access->restrict($nextQuery);
        $next = $nextQuery
            ->filterById($current->getId(), Criteria::GREATER_THAN)
            ->filterByStatusId($current->getStatusId(), Criteria::EQUAL)
            ->orderById(Criteria::ASC)
            ->findOne();

        return [
            'previous' => $previous !== null ? (int) $previous->getId() : null,
            'next' => $next !== null ? (int) $next->getId() : null,
        ];
    }
}
