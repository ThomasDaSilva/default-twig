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

namespace BackOfficeDefaultTwigBundle\Service\Customer;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Map\CustomerTableMap;
use Thelia\Model\TagElement;

/**
 * Single source of truth for the customer list filters: parsing, URL
 * serialisation and SQL application live here so the controller stays thin.
 */
final readonly class CustomerFilters
{
    public const PERIOD_ALL = 'all';
    public const PERIOD_NEW_THIS_MONTH = 'new_this_month';
    public const PERIOD_TOP_SPENDERS = 'top_spenders';
    public const ALLOWED_PERIODS = [self::PERIOD_ALL, self::PERIOD_NEW_THIS_MONTH, self::PERIOD_TOP_SPENDERS];

    public const DEFAULT_SORT = 'created_at';
    public const DEFAULT_DIRECTION = 'desc';
    public const ALLOWED_DIRECTIONS = ['asc', 'desc'];
    public const ALLOWED_SORTS = [
        'lastname', 'firstname', 'email', 'ref', 'created_at', 'total_spent', 'order_count',
    ];

    public const TRISTATE_WITH = 'with';
    public const TRISTATE_WITHOUT = 'without';

    public const KEY_NEWSLETTER = 'newsletter';
    public const KEY_GUEST = 'guest';
    public const KEY_VAT_VERIFIED = 'vat_verified';
    public const KEY_CREATED_RANGE = 'created_range';
    public const KEY_TOTAL_SPENT = 'total_spent';
    public const KEY_ORDER_COUNT = 'order_count';
    public const KEY_COUNTRY = 'country_id';
    public const KEY_LANG_IDS = 'lang_ids';
    public const KEY_TITLE_IDS = 'title_ids';
    public const KEY_TAG_IDS = 'tag_ids';
    public const KEY_PHONE = 'phone';
    public const KEY_SEARCH = 'search';
    public const KEY_PERIOD = 'period';

    /** @var list<array{code: string, label: string, icon: string}> */
    public const QUICK_CHIPS = [
        ['code' => self::PERIOD_ALL, 'label' => 'All customers', 'icon' => 'bi-people'],
        ['code' => self::PERIOD_NEW_THIS_MONTH, 'label' => 'New this month', 'icon' => 'bi-calendar-plus'],
        ['code' => self::PERIOD_TOP_SPENDERS, 'label' => 'Top spenders', 'icon' => 'bi-trophy'],
    ];

    /**
     * @param list<int> $langIds
     * @param list<int> $titleIds
     */
    public function __construct(
        public ?bool $newsletter = null,
        public ?bool $guest = null,
        public ?bool $vatVerified = null,
        public ?\DateTimeImmutable $createdFrom = null,
        public ?\DateTimeImmutable $createdTo = null,
        public ?float $minTotalSpent = null,
        public ?float $maxTotalSpent = null,
        public ?int $minOrderCount = null,
        public ?int $maxOrderCount = null,
        public ?int $countryId = null,
        public array $langIds = [],
        public array $titleIds = [],
        public array $tagIds = [],
        public string $phone = '',
        public string $search = '',
        public string $sort = self::DEFAULT_SORT,
        public string $direction = self::DEFAULT_DIRECTION,
        public string $period = self::PERIOD_ALL,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $query = $request->query;

        $period = (string) $query->get('period', self::PERIOD_ALL);
        if (!\in_array($period, self::ALLOWED_PERIODS, true)) {
            $period = self::PERIOD_ALL;
        }

        $createdFrom = self::parseDate((string) $query->get('created_from', ''), endOfDay: false);
        $createdTo = self::parseDate((string) $query->get('created_to', ''), endOfDay: true);

        $minOrderCount = self::parsePositiveOrZeroInt((string) $query->get('min_orders', ''));
        $maxOrderCount = self::parsePositiveOrZeroInt((string) $query->get('max_orders', ''));

        $minTotalSpent = self::parseFloat((string) $query->get('min_total', ''));
        $maxTotalSpent = self::parseFloat((string) $query->get('max_total', ''));

        $sort = (string) $query->get('order', self::DEFAULT_SORT);
        if (!\in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = self::DEFAULT_SORT;
        }

        $direction = strtolower((string) $query->get('direction', self::DEFAULT_DIRECTION));
        if (!\in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            $direction = self::DEFAULT_DIRECTION;
        }

        // Quick chips put nothing but `period` in the URL, then we derive the
        // associated filters here. User-supplied query params win, so editing a
        // chip-fed filter never silently flips back to the chip default.
        if ($period === self::PERIOD_NEW_THIS_MONTH && $createdFrom === null && $createdTo === null) {
            $createdFrom = new \DateTimeImmutable('first day of this month 00:00:00');
            $createdTo = new \DateTimeImmutable('today 23:59:59');
        } elseif ($period === self::PERIOD_TOP_SPENDERS) {
            if ($minOrderCount === null) {
                $minOrderCount = 1;
            }
            if ($sort === self::DEFAULT_SORT) {
                $sort = 'total_spent';
            }
        }

        if ($createdFrom !== null && $createdTo !== null && $createdFrom > $createdTo) {
            [$createdFrom, $createdTo] = [$createdTo->setTime(0, 0, 0), $createdFrom->setTime(23, 59, 59)];
        }

        if ($minTotalSpent !== null && $maxTotalSpent !== null && $minTotalSpent > $maxTotalSpent) {
            [$minTotalSpent, $maxTotalSpent] = [$maxTotalSpent, $minTotalSpent];
        }
        if ($minOrderCount !== null && $maxOrderCount !== null && $minOrderCount > $maxOrderCount) {
            [$minOrderCount, $maxOrderCount] = [$maxOrderCount, $minOrderCount];
        }

        return new self(
            newsletter: self::parseTriStateBool((string) $query->get('newsletter', '')),
            guest: self::parseTriStateBool((string) $query->get('guest', '')),
            vatVerified: self::parseTriStateBool((string) $query->get('vat_verified', '')),
            createdFrom: $createdFrom,
            createdTo: $createdTo,
            minTotalSpent: $minTotalSpent,
            maxTotalSpent: $maxTotalSpent,
            minOrderCount: $minOrderCount,
            maxOrderCount: $maxOrderCount,
            countryId: self::parsePositiveInt((string) $query->get('country_id', '')),
            langIds: self::parseIntArray($query->all('lang_ids')),
            titleIds: self::parseIntArray($query->all('title_ids')),
            tagIds: self::parseIntArray($query->all('tag_ids')),
            phone: trim((string) $query->get('phone', '')),
            search: trim((string) $query->get('q', '')),
            sort: $sort,
            direction: $direction,
            period: $period,
        );
    }

    public function isEmpty(): bool
    {
        return $this->newsletter === null
            && $this->guest === null
            && $this->vatVerified === null
            && $this->createdFrom === null
            && $this->createdTo === null
            && $this->minTotalSpent === null
            && $this->maxTotalSpent === null
            && $this->minOrderCount === null
            && $this->maxOrderCount === null
            && $this->countryId === null
            && $this->langIds === []
            && $this->titleIds === []
            && $this->tagIds === []
            && $this->phone === '';
    }

    /**
     * @return array<string, scalar|list<int>>
     */
    public function toQueryParams(): array
    {
        $params = [];

        if ($this->newsletter !== null) {
            $params['newsletter'] = $this->newsletter ? self::TRISTATE_WITH : self::TRISTATE_WITHOUT;
        }
        if ($this->guest !== null) {
            $params['guest'] = $this->guest ? self::TRISTATE_WITH : self::TRISTATE_WITHOUT;
        }
        if ($this->vatVerified !== null) {
            $params['vat_verified'] = $this->vatVerified ? self::TRISTATE_WITH : self::TRISTATE_WITHOUT;
        }
        if ($this->createdFrom !== null) {
            $params['created_from'] = $this->createdFrom->format('Y-m-d');
        }
        if ($this->createdTo !== null) {
            $params['created_to'] = $this->createdTo->format('Y-m-d');
        }
        if ($this->minTotalSpent !== null) {
            $params['min_total'] = $this->minTotalSpent;
        }
        if ($this->maxTotalSpent !== null) {
            $params['max_total'] = $this->maxTotalSpent;
        }
        if ($this->minOrderCount !== null) {
            $params['min_orders'] = $this->minOrderCount;
        }
        if ($this->maxOrderCount !== null) {
            $params['max_orders'] = $this->maxOrderCount;
        }
        if ($this->countryId !== null) {
            $params['country_id'] = $this->countryId;
        }
        if ($this->langIds !== []) {
            $params['lang_ids'] = $this->langIds;
        }
        if ($this->titleIds !== []) {
            $params['title_ids'] = $this->titleIds;
        }
        if ($this->tagIds !== []) {
            $params['tag_ids'] = $this->tagIds;
        }
        if ($this->phone !== '') {
            $params['phone'] = $this->phone;
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

    /**
     * Apply a quick-chip shortcut: returns a fresh VO with only `period` set.
     * The associated filters (date range, sort, min order count) are derived
     * during fromRequest() so the URL stays short and bookmarkable.
     */
    public function cloneForPeriodShortcut(string $period): self
    {
        if (!\in_array($period, self::ALLOWED_PERIODS, true)) {
            $period = self::PERIOD_ALL;
        }

        return new self(period: $period);
    }

    public function withoutFilter(string $key): self
    {
        $overrides = match ($key) {
            self::KEY_NEWSLETTER => ['newsletter' => null],
            self::KEY_GUEST => ['guest' => null],
            self::KEY_VAT_VERIFIED => ['vatVerified' => null],
            self::KEY_CREATED_RANGE, self::KEY_PERIOD => [
                'createdFrom' => null,
                'createdTo' => null,
                'period' => self::PERIOD_ALL,
            ],
            self::KEY_TOTAL_SPENT => ['minTotalSpent' => null, 'maxTotalSpent' => null],
            self::KEY_ORDER_COUNT => ['minOrderCount' => null, 'maxOrderCount' => null],
            self::KEY_COUNTRY => ['countryId' => null],
            self::KEY_LANG_IDS => ['langIds' => []],
            self::KEY_TITLE_IDS => ['titleIds' => []],
            self::KEY_TAG_IDS => ['tagIds' => []],
            self::KEY_PHONE => ['phone' => ''],
            self::KEY_SEARCH => ['search' => ''],
            default => [],
        };

        return $this->cloneWith($overrides);
    }

    /**
     * Applies the filters on the customer row. The ranges on the total spent and the order
     * count are figures computed from the orders: the repository applies them.
     */
    public function applyTo(CustomerQuery $query): CustomerQuery
    {
        if ($this->createdFrom !== null) {
            $query->filterByCreatedAt($this->createdFrom->format('Y-m-d H:i:s'), Criteria::GREATER_EQUAL);
        }
        if ($this->createdTo !== null) {
            $query->filterByCreatedAt($this->createdTo->format('Y-m-d H:i:s'), Criteria::LESS_EQUAL);
        }

        if ($this->langIds !== []) {
            $query->filterByLangId($this->langIds, Criteria::IN);
        }
        if ($this->titleIds !== []) {
            $query->filterByTitleId($this->titleIds, Criteria::IN);
        }

        $this->applyNewsletter($query);
        $this->applyGuest($query);
        $this->applyVatVerified($query);
        $this->applyCountry($query);
        $this->applyPhone($query);
        $this->applyTags($query);
        $this->applySearch($query);

        return $query;
    }

    private function applySearch(CustomerQuery $query): void
    {
        if ($this->search === '') {
            return;
        }

        $needle = '%'.$this->search.'%';

        // condition+combine groups the OR cluster as a single AND clause, otherwise
        // chaining _or() bleeds into the surrounding filters and turns the whole
        // WHERE into an OR (status AND newsletter OR search instead of AND search).
        $query
            ->condition('search_ref', CustomerTableMap::COL_REF.' LIKE ?', $needle, \PDO::PARAM_STR)
            ->condition('search_email', CustomerTableMap::COL_EMAIL.' LIKE ?', $needle, \PDO::PARAM_STR)
            ->condition('search_firstname', CustomerTableMap::COL_FIRSTNAME.' LIKE ?', $needle, \PDO::PARAM_STR)
            ->condition('search_lastname', CustomerTableMap::COL_LASTNAME.' LIKE ?', $needle, \PDO::PARAM_STR)
            ->combine(['search_ref', 'search_email', 'search_firstname', 'search_lastname'], 'OR');
    }

    private function applyNewsletter(CustomerQuery $query): void
    {
        if ($this->newsletter === null) {
            return;
        }

        $clause = $this->newsletter ? 'EXISTS' : 'NOT EXISTS';
        $query->where(
            $clause.' (SELECT 1 FROM newsletter n WHERE n.email = '
                .CustomerTableMap::COL_EMAIL.' AND n.unsubscribed = 0)'
        );
    }

    private function applyGuest(CustomerQuery $query): void
    {
        if ($this->guest === null) {
            return;
        }

        // Direct column filter: is_guest lives on customer itself, unlike
        // newsletter (a join by email) or country (a join through address).
        $query->filterByIsGuest($this->guest ? 1 : 0);
    }

    private function applyVatVerified(CustomerQuery $query): void
    {
        if ($this->vatVerified === null) {
            return;
        }

        $freshSince = (new \DateTimeImmutable())
            ->modify(\sprintf('-%d days', ConfigQuery::getVatVerificationLifetimeDays()))
            ->format('Y-m-d H:i:s');

        $clause = $this->vatVerified ? 'EXISTS' : 'NOT EXISTS';
        $query->where(
            $clause.' (SELECT 1 FROM address a WHERE a.customer_id = '
                .CustomerTableMap::COL_ID.' AND a.vat_verified_at >= ?)',
            $freshSince,
            \PDO::PARAM_STR,
        );
    }

    private function applyCountry(CustomerQuery $query): void
    {
        if ($this->countryId === null) {
            return;
        }

        $query->where(
            'EXISTS (SELECT 1 FROM address a WHERE a.customer_id = '.CustomerTableMap::COL_ID
                .' AND a.country_id = ?)',
            $this->countryId,
            \PDO::PARAM_INT,
        );
    }

    private function applyPhone(CustomerQuery $query): void
    {
        if ($this->phone === '') {
            return;
        }

        $needle = '%'.$this->phone.'%';
        $query->where(
            'EXISTS (SELECT 1 FROM address a WHERE a.customer_id = '.CustomerTableMap::COL_ID
                .' AND (a.phone LIKE ? OR a.cellphone LIKE ?))',
            [$needle, $needle],
        );
    }

    /**
     * Customers carrying at least one of the selected tags.
     *
     * One correlated EXISTS whatever the number of tags, and never an AND of
     * several: ticking a second tag widens the result the way the language and
     * title filters of this same screen do.
     *
     * A raw EXISTS rather than a join: tag_element points at a customer by an
     * element key and an identifier, with no foreign key Propel could follow,
     * and a join would multiply the rows of a customer carrying several tags.
     */
    private function applyTags(CustomerQuery $query): void
    {
        if ($this->tagIds === []) {
            return;
        }

        // Placeholders counted, never interpolated: the identifiers themselves
        // are bound, and the only thing built into the SQL is their number.
        $placeholders = implode(',', array_fill(0, \count($this->tagIds), '?'));

        $query->where(
            'EXISTS (SELECT 1 FROM tag_element te WHERE te.element_id = '.CustomerTableMap::COL_ID
                .' AND te.element_key = ? AND te.tag_id IN ('.$placeholders.'))',
            [TagElement::ELEMENT_KEY_CUSTOMER, ...$this->tagIds],
        );
    }

    /**
     * @param array<int|string, mixed> $overrides
     */
    private function cloneWith(array $overrides): self
    {
        return new self(
            newsletter: \array_key_exists('newsletter', $overrides) ? $overrides['newsletter'] : $this->newsletter,
            guest: \array_key_exists('guest', $overrides) ? $overrides['guest'] : $this->guest,
            vatVerified: \array_key_exists('vatVerified', $overrides) ? $overrides['vatVerified'] : $this->vatVerified,
            createdFrom: \array_key_exists('createdFrom', $overrides) ? $overrides['createdFrom'] : $this->createdFrom,
            createdTo: \array_key_exists('createdTo', $overrides) ? $overrides['createdTo'] : $this->createdTo,
            minTotalSpent: \array_key_exists('minTotalSpent', $overrides) ? $overrides['minTotalSpent'] : $this->minTotalSpent,
            maxTotalSpent: \array_key_exists('maxTotalSpent', $overrides) ? $overrides['maxTotalSpent'] : $this->maxTotalSpent,
            minOrderCount: \array_key_exists('minOrderCount', $overrides) ? $overrides['minOrderCount'] : $this->minOrderCount,
            maxOrderCount: \array_key_exists('maxOrderCount', $overrides) ? $overrides['maxOrderCount'] : $this->maxOrderCount,
            countryId: \array_key_exists('countryId', $overrides) ? $overrides['countryId'] : $this->countryId,
            langIds: \array_key_exists('langIds', $overrides) ? $overrides['langIds'] : $this->langIds,
            titleIds: \array_key_exists('titleIds', $overrides) ? $overrides['titleIds'] : $this->titleIds,
            tagIds: \array_key_exists('tagIds', $overrides) ? $overrides['tagIds'] : $this->tagIds,
            phone: \array_key_exists('phone', $overrides) ? $overrides['phone'] : $this->phone,
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

    private static function parseFloat(string $value): ?float
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $trimmed);
        if (!is_numeric($normalized)) {
            return null;
        }

        $float = (float) $normalized;

        return $float >= 0 ? $float : null;
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

    private static function parsePositiveOrZeroInt(string $value): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return null;
        }

        return (int) $trimmed;
    }

    private static function parseTriStateBool(string $value): ?bool
    {
        return match ($value) {
            self::TRISTATE_WITH => true,
            self::TRISTATE_WITHOUT => false,
            default => null,
        };
    }
}
