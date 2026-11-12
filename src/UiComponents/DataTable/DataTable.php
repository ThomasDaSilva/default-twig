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

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'BoDataTable', template: '@BackOfficeDefaultTwig/components/DataTable/DataTable.html.twig')]
final class DataTable
{
    public string $id = '';

    public string $caption = '';

    /** @var list<Column> */
    public array $columns = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public string $emptyMessage = '';

    /** @var array<string, string> Extra HTML attributes appended to the table body (e.g. Stimulus wiring). */
    public array $tbodyAttributes = [];

    /** Sort field currently applied (matches one of {@see Column::sortKey}). */
    public ?string $sortField = null;

    /** Sort direction currently applied ('asc' or 'desc'). */
    public string $sortDirection = 'asc';

    /** Query parameter used to convey the sort field. */
    public string $sortParam = 'order';

    /** Query parameter used to convey the sort direction. */
    public string $sortDirectionParam = 'direction';

    /** Base URL the sortable header links target. Defaults to the current request path. */
    public ?string $sortBaseUrl = null;

    /**
     * Extra query parameters preserved on sort links (current filters, page, etc.).
     *
     * @var array<string, scalar|null>
     */
    public array $sortKeepParams = [];

    /**
     * Hook name dispatched at the end of the row to allow modules to inject extra `<td>` cells.
     * The hook receives `{ table_id, row, row_id }` as context.
     */
    public ?string $extraColumnsHook = null;

    /**
     * Hook name dispatched at the end of the header row to allow modules to inject extra `<th>` cells.
     * The hook receives `{ table_id }`.
     */
    public ?string $extraColumnsHeaderHook = null;

    /**
     * Legacy hook prefix for the `<prefix>.table-header` / `<prefix>.table-row` hooks (Smarty parity).
     * Defaults to {@see $id}; set it when the table id differs from the legacy hook name.
     */
    public string $hook = '';

    /**
     * Extra arguments merged into the row hook context, mapping argument name to row key
     * (e.g. `{ coupon_id: 'id' }` adds `coupon_id` with the row `id` value — Smarty parity).
     *
     * @var array<string, string>
     */
    public array $rowHookArgs = [];

    /**
     * Static arguments merged into the header hook context (e.g. a legacy `location`).
     *
     * @var array<string, mixed>
     */
    public array $headerHookContext = [];

    /**
     * Static arguments merged into the row hook context (e.g. a legacy `location`).
     *
     * @var array<string, mixed>
     */
    public array $rowHookContext = [];

    /**
     * Row key holding a human-readable identifier (reference, name...), used in the detail-toggle
     * button's accessible name ("View details for <value>"). Falls back to the row id when null.
     */
    public ?string $rowIdentifierKey = null;

    /**
     * A table body driven by the `bo-sortable` controller reorders its rows by dragging: each row
     * then starts with the handle that drags it.
     */
    public function isReorderable(): bool
    {
        $controllers = preg_split('/\s+/', $this->tbodyAttributes['data-controller'] ?? '', -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return \in_array('bo-sortable', $controllers, true);
    }
}
