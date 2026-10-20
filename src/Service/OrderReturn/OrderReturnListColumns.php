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
 * Each key names a cell of OrderReturnListRowPresenter.
 */
final readonly class OrderReturnListColumns
{
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
            new Column('ref_html', $this->translator->trans('Return'), ColumnKind::HTML, sortKey: 'ref'),
            new Column('order_html', $this->translator->trans('Order'), ColumnKind::HTML, visibleFrom: 'md'),
            new Column('customer_html', $this->translator->trans('Customer'), ColumnKind::HTML, visibleFrom: 'lg'),
            new Column('products_html', $this->translator->trans('Products'), ColumnKind::HTML, visibleFrom: 'lg'),
            new Column('reason', $this->translator->trans('Reason'), options: ['wrap' => true], visibleFrom: 'xl'),
            new Column('resolution', $this->translator->trans('Expected resolution'), options: ['wrap' => true], visibleFrom: 'xl'),
            new Column(
                'status_code',
                $this->translator->trans('Status'),
                ColumnKind::BADGE,
                options: ['variants' => [], 'label_key' => 'status', 'color_key' => 'status_color'],
            ),
            new Column('refund_amount', $this->translator->trans('Refundable'), cellAlign: 'end', sortKey: 'refund_amount'),
            new Column('date_html', $this->translator->trans('Requested on'), ColumnKind::HTML, sortKey: 'created_at', visibleFrom: 'lg'),
            new Column('_actions', $this->translator->trans('Actions'), ColumnKind::ACTIONS, 'end'),
        ];
    }
}
