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

namespace BackOfficeDefaultTwigBundle\Service\OrderReturn;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Model\Map\OrderReturnTableMap;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnQuery;

/**
 * Single source of truth for the return list filters: parsing, URL
 * serialisation and SQL application live here so the controller stays thin.
 *
 * The merchant sorts returns by state, by period, by customer, by order and by
 * what the customer expects in return, which is the whole filter surface the
 * return screen needs; the free search covers the return reference and the
 * order reference at once.
 */
final readonly class OrderReturnFilters
{
    public const PERIOD_ALL = 'all';
    public const PERIOD_TODAY = 'today';
    public const PERIOD_WEEK = 'week';
    public const ALLOWED_PERIODS = [self::PERIOD_ALL, self::PERIOD_TODAY, self::PERIOD_WEEK];

    public const SORT_ID = 'id';
    public const SORT_REF = 'ref';
    public const SORT_CREATED_AT = 'created_at';
    public const SORT_REFUND_AMOUNT = 'refund_amount';

    public const DEFAULT_SORT = self::SORT_CREATED_AT;
    public const DEFAULT_DIRECTION = 'desc';
    public const ALLOWED_DIRECTIONS = ['asc', 'desc'];
    public const ALLOWED_SORTS = [self::SORT_ID, self::SORT_REF, self::SORT_CREATED_AT, self::SORT_REFUND_AMOUNT];

    public const KEY_STATUS_IDS = 'status_ids';
    public const KEY_CREATED_RANGE = 'created_range';
    public const KEY_PERIOD = 'period';
    public const KEY_CUSTOMER_ID = 'customer_id';
    public const KEY_ORDER_ID = 'order_id';
    public const KEY_RESOLUTION = 'resolution';
    public const KEY_SEARCH = 'search';

    /** @var list<array{code: string, label: string, icon: string}> */
    public const QUICK_CHIPS = [
        ['code' => self::PERIOD_ALL, 'label' => 'All returns', 'icon' => 'bi-list-ul'],
        ['code' => self::PERIOD_TODAY, 'label' => 'Today', 'icon' => 'bi-sun'],
        ['code' => self::PERIOD_WEEK, 'label' => 'Last 7 days', 'icon' => 'bi-calendar-week'],
    ];

    /**
     * @param list<int> $statusIds
     */
    public function __construct(
        public array $statusIds = [],
        public ?\DateTimeImmutable $createdFrom = null,
        public ?\DateTimeImmutable $createdTo = null,
        public ?int $customerId = null,
        public ?int $orderId = null,
        public ?string $resolution = null,
        public string $search = '',
        public string $sort = self::DEFAULT_SORT,
        public string $direction = self::DEFAULT_DIRECTION,
        public string $period = self::PERIOD_ALL,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $query = $request->query;

        $statusIds = self::parseIntArray($query->all('status_ids'));
        if ($statusIds === []) {
            $single = self::parsePositiveInt((string) $query->get('status_id', ''));
            if ($single !== null) {
                $statusIds = [$single];
            }
        }

        $period = (string) $query->get('period', self::PERIOD_ALL);
        if (!\in_array($period, self::ALLOWED_PERIODS, true)) {
            $period = self::PERIOD_ALL;
        }

        $createdFrom = self::parseDate((string) $query->get('created_from', ''), endOfDay: false);
        $createdTo = self::parseDate((string) $query->get('created_to', ''), endOfDay: true);

        if ($createdFrom === null && $createdTo === null) {
            [$createdFrom, $createdTo] = self::periodToRange($period);
        } else {
            // An explicit range wins over a quick-period chip, so the UI never
            // shows a chip that no longer describes what is displayed.
            $period = self::PERIOD_ALL;
        }

        if ($createdFrom !== null && $createdTo !== null && $createdFrom > $createdTo) {
            [$createdFrom, $createdTo] = [$createdTo->setTime(0, 0, 0), $createdFrom->setTime(23, 59, 59)];
        }

        $resolution = (string) $query->get('resolution', '');
        if (!\in_array($resolution, OrderReturn::RESOLUTIONS, true)) {
            $resolution = null;
        }

        $sort = (string) $query->get('order', self::DEFAULT_SORT);
        if (!\in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = self::DEFAULT_SORT;
        }

        $direction = strtolower((string) $query->get('direction', self::DEFAULT_DIRECTION));
        if (!\in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            $direction = self::DEFAULT_DIRECTION;
        }

        return new self(
            statusIds: $statusIds,
            createdFrom: $createdFrom,
            createdTo: $createdTo,
            customerId: self::parsePositiveInt((string) $query->get('customer_id', '')),
            orderId: self::parsePositiveInt((string) $query->get('order_id', '')),
            resolution: $resolution,
            search: trim((string) $query->get('q', '')),
            sort: $sort,
            direction: $direction,
            period: $period,
        );
    }

    public function isEmpty(): bool
    {
        return $this->statusIds === []
            && $this->createdFrom === null
            && $this->createdTo === null
            && $this->customerId === null
            && $this->orderId === null
            && $this->resolution === null;
    }

    /**
     * Omits defaults so URLs stay short and bookmarkable.
     *
     * @return array<string, scalar|list<int>>
     */
    public function toQueryParams(): array
    {
        $params = [];

        if ($this->statusIds !== []) {
            $params['status_ids'] = $this->statusIds;
        }
        if ($this->createdFrom !== null) {
            $params['created_from'] = $this->createdFrom->format('Y-m-d');
        }
        if ($this->createdTo !== null) {
            $params['created_to'] = $this->createdTo->format('Y-m-d');
        }
        if ($this->customerId !== null) {
            $params['customer_id'] = $this->customerId;
        }
        if ($this->orderId !== null) {
            $params['order_id'] = $this->orderId;
        }
        if ($this->resolution !== null) {
            $params['resolution'] = $this->resolution;
        }
        if ($this->search !== '') {
            $params['q'] = $this->search;
        }
        if ($this->sort !== self::DEFAULT_SORT) {
            $params['order'] = $this->sort;
        }
        if ($this->direction !== self::DEFAULT_DIRECTION) {
            $params['direction'] = $this->direction;
        }
        if ($this->period !== self::PERIOD_ALL) {
            $params['period'] = $this->period;
        }

        return $params;
    }

    public function cloneForPeriodShortcut(string $period): self
    {
        if (!\in_array($period, self::ALLOWED_PERIODS, true)) {
            $period = self::PERIOD_ALL;
        }

        [$from, $to] = self::periodToRange($period);

        return $this->cloneWith([
            'period' => $period,
            'createdFrom' => $from,
            'createdTo' => $to,
        ]);
    }

    public function withoutFilter(string $key): self
    {
        $overrides = match ($key) {
            self::KEY_STATUS_IDS => ['statusIds' => []],
            self::KEY_CREATED_RANGE, self::KEY_PERIOD => [
                'createdFrom' => null,
                'createdTo' => null,
                'period' => self::PERIOD_ALL,
            ],
            self::KEY_CUSTOMER_ID => ['customerId' => null],
            self::KEY_ORDER_ID => ['orderId' => null],
            self::KEY_RESOLUTION => ['resolution' => null],
            self::KEY_SEARCH => ['search' => ''],
            default => [],
        };

        return $this->cloneWith($overrides);
    }

    public function applyTo(OrderReturnQuery $query): OrderReturnQuery
    {
        if ($this->statusIds !== []) {
            $query->filterByStatusId($this->statusIds, Criteria::IN);
        }

        if ($this->createdFrom !== null) {
            $query->filterByCreatedAt($this->createdFrom->format('Y-m-d H:i:s'), Criteria::GREATER_EQUAL);
        }
        if ($this->createdTo !== null) {
            $query->filterByCreatedAt($this->createdTo->format('Y-m-d H:i:s'), Criteria::LESS_EQUAL);
        }

        if ($this->customerId !== null) {
            $query->filterByCustomerId($this->customerId);
        }

        if ($this->orderId !== null) {
            $query->filterByOrderId($this->orderId);
        }

        if ($this->resolution !== null) {
            $query->filterByExpectedResolution($this->resolution);
        }

        $this->applySearch($query);

        return $query;
    }

    private function applySearch(OrderReturnQuery $query): void
    {
        if ($this->search === '') {
            return;
        }

        $needle = '%'.$this->search.'%';

        // condition+combine groups the OR cluster as a single AND clause; chaining
        // _or() would bleed into the surrounding filters and turn the whole WHERE
        // into an OR (status_ids OR search instead of status_ids AND search).
        $query
            ->condition('search_ref', OrderReturnTableMap::COL_REF.' LIKE ?', $needle, \PDO::PARAM_STR)
            ->condition(
                'search_order',
                'EXISTS (SELECT 1 FROM `order` o WHERE o.id = '.OrderReturnTableMap::COL_ORDER_ID
                    .' AND o.ref LIKE ?)',
                $needle,
                \PDO::PARAM_STR,
            )
            ->condition(
                'search_customer',
                'EXISTS (SELECT 1 FROM customer c WHERE c.id = '.OrderReturnTableMap::COL_CUSTOMER_ID
                    .' AND CONCAT_WS(\' \', c.firstname, c.lastname, c.email) LIKE ?)',
                $needle,
                \PDO::PARAM_STR,
            )
            ->combine(['search_ref', 'search_order', 'search_customer'], 'OR');
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private static function periodToRange(string $period): array
    {
        return match ($period) {
            self::PERIOD_TODAY => [
                new \DateTimeImmutable('today 00:00:00'),
                new \DateTimeImmutable('today 23:59:59'),
            ],
            self::PERIOD_WEEK => [
                new \DateTimeImmutable('-6 days 00:00:00'),
                new \DateTimeImmutable('today 23:59:59'),
            ],
            default => [null, null],
        };
    }

    /**
     * @param array<int|string, mixed> $overrides
     */
    private function cloneWith(array $overrides): self
    {
        return new self(
            statusIds: \array_key_exists('statusIds', $overrides) ? $overrides['statusIds'] : $this->statusIds,
            createdFrom: \array_key_exists('createdFrom', $overrides) ? $overrides['createdFrom'] : $this->createdFrom,
            createdTo: \array_key_exists('createdTo', $overrides) ? $overrides['createdTo'] : $this->createdTo,
            customerId: \array_key_exists('customerId', $overrides) ? $overrides['customerId'] : $this->customerId,
            orderId: \array_key_exists('orderId', $overrides) ? $overrides['orderId'] : $this->orderId,
            resolution: \array_key_exists('resolution', $overrides) ? $overrides['resolution'] : $this->resolution,
            search: \array_key_exists('search', $overrides) ? $overrides['search'] : $this->search,
            sort: \array_key_exists('sort', $overrides) ? $overrides['sort'] : $this->sort,
            direction: \array_key_exists('direction', $overrides) ? $overrides['direction'] : $this->direction,
            period: \array_key_exists('period', $overrides) ? $overrides['period'] : $this->period,
        );
    }

    /**
     * @return list<int>
     */
    private static function parseIntArray(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $raw) {
            $i = (int) $raw;
            if ($i > 0) {
                $out[$i] = $i;
            }
        }

        return array_values($out);
    }

    private static function parseDate(string $value, bool $endOfDay): ?\DateTimeImmutable
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($trimmed);
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay
            ? $date->setTime(23, 59, 59)
            : $date->setTime(0, 0, 0);
    }

    private static function parsePositiveInt(string $value): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return null;
        }

        $int = (int) $trimmed;

        return $int > 0 ? $int : null;
    }
}
