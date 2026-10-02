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

namespace BackOfficeDefaultTwigBundle\UiComponents\DataTable;

final readonly class Column
{
    /**
     * Bootstrap breakpoint names, in ascending order of viewport width. Array index doubles as a
     * comparable rank: the higher the rank, the wider the viewport must be before the column shows.
     */
    public const array BREAKPOINT_ORDER = ['always', 'sm', 'md', 'lg', 'xl', 'xxl'];

    /**
     * @param string               $cellAlign   bootstrap text-alignment utility, one of: 'start', 'center', 'end'
     * @param array<string, mixed> $options     Kind-specific options. Schemas per ColumnKind:
     *                                          - TOGGLE: { url_key: string|null, icon_on: string, icon_off: string } (null url_key → readonly)
     *                                          - BADGE:  { variants: array<scalar, string> mapping row value → Bootstrap variant, label_key?: string optional row key holding the visible label, color_key?: string optional row key holding a CSS color taking precedence over variants }
     *                                          - RADIO:  { name: string, value_key: string, checked_when_key: string|null, label_key: string|null }
     *                                          - SELECT: { value_key: string, label_key: string|null } row checkbox feeding a bulk toolbar
     *                                          - TEXT:   { left_to_right?: bool } value isolated in a left-to-right run (amounts, figures)
     *                                          - HTML / ACTIONS: empty
     * @param ?string              $sortKey     Sort field this column maps to. When set, the header becomes a sortable link.
     *                                          Use the same string the consuming controller expects in its order query param.
     * @param ?string              $hideBelow   Bootstrap breakpoint under which the column is dropped from the render entirely,
     *                                          one of: 'sm', 'md', 'lg', 'xl', 'xxl'. The older, simpler mechanism: no recovery
     *                                          path for the value below that breakpoint. Kept as-is for the screens already on
     *                                          it; new screens needing a recovery path should use $visibleFrom instead.
     * @param string               $visibleFrom Smallest Bootstrap breakpoint at which this column is shown in the main
     *                                          table, one of self::BREAKPOINT_ORDER. 'always' (default) never hides it;
     *                                          below its breakpoint the column moves into the row's detail line instead.
     *                                          Do not use on an ACTIONS column: the detail line renders it inside a
     *                                          <dl> definition, where its button group has no room to sit correctly.
     *
     * @throws \InvalidArgumentException if $visibleFrom is not one of self::BREAKPOINT_ORDER, or is combined with ColumnKind::ACTIONS
     */
    public function __construct(
        public string $key,
        public string $label,
        public ColumnKind $kind = ColumnKind::TEXT,
        public string $cellAlign = 'start',
        public array $options = [],
        public ?string $sortKey = null,
        public ?string $hideBelow = null,
        public string $visibleFrom = 'always',
    ) {
        self::breakpointRank($this->visibleFrom);

        if ($this->kind === ColumnKind::ACTIONS && $this->visibleFrom !== 'always') {
            throw new \InvalidArgumentException(sprintf(
                'Column "%s" is ACTIONS: visibleFrom must stay "always", the detail row cannot host an action button group.',
                $this->key,
            ));
        }
    }

    /**
     * @throws \InvalidArgumentException if $visibleFrom is not one of self::BREAKPOINT_ORDER
     */
    public static function breakpointRank(string $visibleFrom): int
    {
        $rank = array_search($visibleFrom, self::BREAKPOINT_ORDER, true);

        if ($rank === false) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid visibleFrom "%s", expected one of: %s.',
                $visibleFrom,
                implode(', ', self::BREAKPOINT_ORDER),
            ));
        }

        return $rank;
    }

    /**
     * Largest breakpoint declared among $columns, or null if every column is 'always' (i.e. no
     * column ever hides, so the detail-toggle cell and row have nothing to show).
     *
     * @param list<self> $columns
     */
    public static function maxVisibleFromBreakpoint(array $columns): ?string
    {
        $maxRank = 0;
        $max = null;

        foreach ($columns as $column) {
            $rank = self::breakpointRank($column->visibleFrom);
            if ($rank > $maxRank) {
                $maxRank = $rank;
                $max = $column->visibleFrom;
            }
        }

        return $max;
    }

    /**
     * Largest breakpoint declared among $columns that are sortable (sortKey set), or null if none
     * of the sortable columns ever hides (i.e. every sortable <th> - and its sort link - stays
     * reachable, so no mobile sort fallback is needed).
     *
     * @param list<self> $columns
     */
    public static function maxSortableBreakpoint(array $columns): ?string
    {
        $maxRank = 0;
        $max = null;

        foreach ($columns as $column) {
            if ($column->sortKey === null) {
                continue;
            }

            $rank = self::breakpointRank($column->visibleFrom);
            if ($rank > $maxRank) {
                $maxRank = $rank;
                $max = $column->visibleFrom;
            }
        }

        return $max;
    }

    /**
     * Bootstrap display utilities hiding the cell below `$hideBelow`.
     */
    public function responsiveClass(): string
    {
        return null === $this->hideBelow ? '' : ' d-none d-'.$this->hideBelow.'-table-cell';
    }
}
