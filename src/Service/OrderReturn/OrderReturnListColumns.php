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

use BackOfficeDefaultTwigBundle\UiComponents\DataTable\Column;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ColumnKind;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the return queue, built here rather than in the template so a
 * module can rework them through OrderReturnListEvent before the page renders.
 * Each key names a cell of OrderReturnListRowPresenter: the constants are the
 * keys a module may rely on, the README lists what each cell holds.
 */
final readonly class OrderReturnListColumns
{
    public const string REF = 'ref_html';
    public const string ORDER = 'order_html';
    public const string CUSTOMER = 'customer_html';
    public const string PRODUCTS = 'products_html';
    public const string REASON = 'reason';
    public const string RESOLUTION = 'resolution';
    public const string STATUS = 'status_code';
    public const string REFUND_AMOUNT = 'refund_amount';
    public const string DATE = 'date_html';
    public const string ACTIONS = '_actions';

    /** Cells no column shows: the return id, its reference, and the title and color the status badge reads. */
    public const string ID = 'id';
    public const string REFERENCE = 'ref';
    public const string STATUS_TITLE = 'status';
    public const string STATUS_COLOR = 'status_color';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<Column>
     */
    public function columns(): array
    {
        return [
            new Column(self::REF, $this->translator->trans('Return'), ColumnKind::HTML, sortKey: OrderReturnFilters::SORT_REF),
            new Column(self::ORDER, $this->translator->trans('Order'), ColumnKind::HTML, visibleFrom: 'md'),
            new Column(self::CUSTOMER, $this->translator->trans('Customer'), ColumnKind::HTML, visibleFrom: 'lg'),
            new Column(self::PRODUCTS, $this->translator->trans('Products'), ColumnKind::HTML, visibleFrom: 'lg'),
            new Column(self::REASON, $this->translator->trans('Reason'), options: ['wrap' => true], visibleFrom: 'xl'),
            new Column(self::RESOLUTION, $this->translator->trans('Expected resolution'), options: ['wrap' => true], visibleFrom: 'xl'),
            new Column(
                self::STATUS,
                $this->translator->trans('Status'),
                ColumnKind::BADGE,
                options: ['variants' => [], 'label_key' => self::STATUS_TITLE, 'color_key' => self::STATUS_COLOR],
            ),
            new Column(self::REFUND_AMOUNT, $this->translator->trans('Refundable'), cellAlign: 'end', sortKey: OrderReturnFilters::SORT_REFUND_AMOUNT),
            new Column(self::DATE, $this->translator->trans('Requested on'), ColumnKind::HTML, sortKey: OrderReturnFilters::SORT_CREATED_AT, visibleFrom: 'lg'),
            new Column(self::ACTIONS, $this->translator->trans('Actions'), ColumnKind::ACTIONS, 'end'),
        ];
    }
}
