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

namespace BackOfficeDefaultTwigBundle\Event;

use BackOfficeDefaultTwigBundle\UiComponents\DataTable\Column;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ColumnKind;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once per page of the return queue, once its rows are presented and
 * before they render, so a module reshapes the table to the way its shop works:
 * it removes, replaces or inserts columns, fills in each row the cells of the
 * columns it adds, and puts buttons in the page header (an export, a report).
 *
 * The `<entities>.table-row` hooks of BoDataTable only append cells after the
 * actions column; this event places a column anywhere and reaches the existing
 * ones. Each row carries the return id under `id`: a listener reads what it
 * needs for the whole page at once from getReturnIds().
 *
 * The columns and cells of the theme are named by the constants of
 * OrderReturnListColumns. Several modules may listen: each sees the table as
 * the listeners before it left it. A listener that removes, replaces or moves
 * columns of the theme listens with a positive priority, one that only adds its
 * own columns or buttons with priority 0 or lower, so the additions find the
 * columns they are placed after.
 *
 * A cell of an HTML column is rendered as is: the listener escapes what it
 * writes there. Labels and URLs of the header actions are escaped by the page.
 */
final class OrderReturnListEvent extends Event
{
    /** @var list<array{label: string, url: string, icon: string}> */
    private array $headerActions = [];

    /**
     * @param list<Column>               $columns
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        private array $columns,
        private array $rows,
        private readonly string $locale,
    ) {
    }

    /**
     * @return list<Column>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @param array<int, Column> $columns
     */
    public function setColumns(array $columns): void
    {
        $this->columns = array_values($columns);
    }

    public function hasColumn(string $key): bool
    {
        foreach ($this->columns as $column) {
            if ($column->key === $key) {
                return true;
            }
        }

        return false;
    }

    public function removeColumn(string $key): void
    {
        $this->setColumns(array_filter($this->columns, static fn (Column $column): bool => $column->key !== $key));
    }

    /**
     * Inserts the column right after the one holding $key. Without such a column
     * (renamed, or removed by another module), it goes before the actions column,
     * which stays pinned to the right edge of the table, or last when there is none.
     */
    public function insertColumnAfter(string $key, Column $column): void
    {
        $position = null;
        $beforeActions = null;
        foreach ($this->columns as $index => $existing) {
            if ($existing->key === $key) {
                $position = $index + 1;
                break;
            }
            if ($beforeActions === null && $existing->kind === ColumnKind::ACTIONS) {
                $beforeActions = $index;
            }
        }

        array_splice($this->columns, $position ?? $beforeActions ?? \count($this->columns), 0, [$column]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function setRows(array $rows): void
    {
        $this->rows = array_values($rows);
    }

    /**
     * @return list<int>
     */
    public function getReturnIds(): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $this->rows);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * @return list<array{label: string, url: string, icon: string}>
     */
    public function getHeaderActions(): array
    {
        return $this->headerActions;
    }

    /**
     * @param string $icon a Bootstrap Icons class
     */
    public function addHeaderAction(string $label, string $url, string $icon = 'bi-box-arrow-up-right'): void
    {
        $this->headerActions[] = ['label' => $label, 'url' => $url, 'icon' => $icon];
    }
}
