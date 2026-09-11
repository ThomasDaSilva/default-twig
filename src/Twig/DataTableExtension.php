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

namespace BackOfficeDefaultTwigBundle\Twig;

use BackOfficeDefaultTwigBundle\UiComponents\DataTable\Column;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ColumnKind;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class DataTableExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('column', $this->textColumn(...)),
            new TwigFunction('column_text', $this->textColumn(...)),
            new TwigFunction('column_html', $this->htmlColumn(...)),
            new TwigFunction('column_toggle', $this->toggleColumn(...)),
            new TwigFunction('column_badge', $this->badgeColumn(...)),
            new TwigFunction('column_actions', $this->actionsColumn(...)),
            new TwigFunction('column_radio', $this->radioColumn(...)),
            new TwigFunction('column_select', $this->selectColumn(...)),
            new TwigFunction('row_action', $this->rowAction(...)),
            new TwigFunction('datatable_max_breakpoint', Column::maxVisibleFromBreakpoint(...)),
            new TwigFunction('datatable_max_collapse_breakpoint', RowAction::maxCollapseBelowBreakpoint(...)),
            new TwigFunction('datatable_sort_breakpoint', Column::maxSortableBreakpoint(...)),
        ];
    }

    public function textColumn(string $key, string $label, string $cellAlign = 'start', ?string $sortKey = null, ?string $hideBelow = null, string $visibleFrom = 'always'): Column
    {
        return new Column($key, $label, ColumnKind::TEXT, $cellAlign, sortKey: $sortKey, hideBelow: $hideBelow, visibleFrom: $visibleFrom);
    }

    public function htmlColumn(string $key, string $label, string $cellAlign = 'start', ?string $sortKey = null, ?string $hideBelow = null, string $visibleFrom = 'always'): Column
    {
        return new Column($key, $label, ColumnKind::HTML, $cellAlign, sortKey: $sortKey, hideBelow: $hideBelow, visibleFrom: $visibleFrom);
    }

    /**
     * Row checkbox driving the bulk-action toolbar. The header renders the
     * select-all box, so the label is only used as its accessible name.
     *
     * @param string|null $stateKey row key whose value the checkbox carries, for a
     *                              bulk action that only applies to some rows
     */
    public function selectColumn(
        string $key = '_select',
        string $label = 'Select',
        string $valueKey = 'id',
        ?string $labelKey = null,
        ?string $hideBelow = null,
        string $visibleFrom = 'always',
        ?string $stateKey = null,
    ): Column {
        return new Column(
            $key,
            $label,
            ColumnKind::SELECT,
            'center',
            ['value_key' => $valueKey, 'label_key' => $labelKey, 'state_key' => $stateKey],
            hideBelow: $hideBelow,
            visibleFrom: $visibleFrom,
        );
    }

    public function toggleColumn(
        string $key,
        string $label,
        ?string $urlKey,
        string $cellAlign = 'center',
        string $iconOn = 'bi-check-circle-fill text-success',
        string $iconOff = 'bi-circle text-secondary',
        ?string $sortKey = null,
        ?string $hideBelow = null,
        string $visibleFrom = 'always',
    ): Column {
        return new Column(
            $key,
            $label,
            ColumnKind::TOGGLE,
            $cellAlign,
            ['url_key' => $urlKey, 'icon_on' => $iconOn, 'icon_off' => $iconOff],
            $sortKey,
            $hideBelow,
            $visibleFrom,
        );
    }

    /**
     * @param array<scalar, string> $variants
     */
    public function badgeColumn(string $key, string $label, array $variants = [], string $cellAlign = 'start', ?string $labelKey = null, ?string $colorKey = null, ?string $sortKey = null, ?string $hideBelow = null, string $visibleFrom = 'always'): Column
    {
        return new Column(
            $key,
            $label,
            ColumnKind::BADGE,
            $cellAlign,
            ['variants' => $variants, 'label_key' => $labelKey, 'color_key' => $colorKey],
            $sortKey,
            $hideBelow,
            $visibleFrom,
        );
    }

    public function actionsColumn(string $key = '_actions', string $label = 'Actions', string $cellAlign = 'end', string $visibleFrom = 'always'): Column
    {
        return new Column($key, $label, ColumnKind::ACTIONS, $cellAlign, visibleFrom: $visibleFrom);
    }

    public function radioColumn(
        string $key,
        string $label,
        string $name,
        string $valueKey = 'id',
        ?string $checkedWhenKey = null,
        ?string $labelKey = null,
        ?string $urlKey = null,
        string $cellAlign = 'center',
        ?string $hideBelow = null,
        string $visibleFrom = 'always',
    ): Column {
        return new Column(
            $key,
            $label,
            ColumnKind::RADIO,
            $cellAlign,
            ['name' => $name, 'value_key' => $valueKey, 'checked_when_key' => $checkedWhenKey, 'label_key' => $labelKey, 'url_key' => $urlKey],
            hideBelow: $hideBelow,
            visibleFrom: $visibleFrom,
        );
    }

    /**
     * @param string|array<string, mixed>|null $grantedSubject
     * @param array<string, scalar>            $dataAttributes
     */
    public function rowAction(
        string $kind,
        string $label,
        ?string $href = null,
        ?string $modalTarget = null,
        ?string $grantedAttribute = null,
        string|array|null $grantedSubject = null,
        array $dataAttributes = [],
        bool $inMenu = false,
        ?string $inlineFrom = null,
    ): RowAction {
        return new RowAction(
            kind: $kind,
            label: $label,
            href: $href,
            modalTarget: $modalTarget,
            grantedAttribute: $grantedAttribute,
            grantedSubject: $grantedSubject,
            dataAttributes: $dataAttributes,
            inMenu: $inMenu,
            inlineFrom: $inlineFrom,
        );
    }
}
