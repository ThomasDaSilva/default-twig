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

use BackOfficeDefaultTwigBundle\Repository\OrderReturnRepository;
use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnStatus;

/**
 * Maps an OrderReturn model to the DataTable row shape used by
 * `order-return/list.html.twig`.
 */
final readonly class OrderReturnListRowPresenter
{
    private const DETAIL_ROUTE = 'admin.order-return.detail';
    private const RECEPTION_ROUTE = 'admin.order-return.reception.view';
    private const ORDER_ROUTE = 'admin.order.update.view';
    private const PRODUCT_ROUTE = 'admin.products.update';
    private const FALLBACK_STATUS_COLOR = '#6c757d';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private OrderReturnRepository $returns,
        private MoneyFormatter $money,
        private OrderReturnResolutionLabels $resolutionLabels,
    ) {
    }

    /**
     * A whole page of rows, reading the per-page figures once instead of once per
     * row: the returned lines of every return of the page come back in a fixed
     * number of queries, and the reason titles in one.
     *
     * @param iterable<OrderReturn> $returns
     *
     * @return list<array<string, mixed>>
     */
    public function presentAll(iterable $returns, string $locale): array
    {
        $page = [];
        $returnIds = [];
        foreach ($returns as $orderReturn) {
            $page[] = $orderReturn;
            $returnIds[] = (int) $orderReturn->getId();
        }

        if ($page === []) {
            return [];
        }

        $lines = $this->returns->findLinesByReturns($returnIds);
        $reasonTitles = $this->returns->findReasonTitles($locale);

        $rows = [];
        foreach ($page as $orderReturn) {
            $rows[] = $this->present($orderReturn, $locale, $lines, $reasonTitles);
        }

        return $rows;
    }

    /**
     * @param array<int, list<array{title: string, quantity: float, product_id: ?int, attributes: list<array{attribute: string, value: string}>}>> $lines        returned lines per return id, when the caller has them
     * @param array<int, string>                                                                                                                  $reasonTitles current title of each reason, when the caller has them
     *
     * @return array<string, mixed>
     */
    public function present(OrderReturn $orderReturn, string $locale, array $lines = [], array $reasonTitles = []): array
    {
        $returnId = (int) $orderReturn->getId();
        // status_id is a required column behind a RESTRICT foreign key: a return
        // always carries its status.
        $status = $orderReturn->getOrderReturnStatus();
        $status->setLocale($locale);
        $effectiveCode = $status->getEffectiveCode();

        return [
            'id' => $returnId,
            'ref' => (string) $orderReturn->getRef(),
            'order_html' => $this->renderOrder($orderReturn, $returnId),
            'customer_html' => $this->renderCustomer($orderReturn),
            'products_html' => $this->renderProducts($lines[$returnId] ?? []),
            'reason' => $this->reason($orderReturn, $reasonTitles),
            'resolution_html' => $this->renderResolution($orderReturn),
            'status' => (string) $status->getTitle(),
            'status_code' => $effectiveCode,
            'status_color' => (string) ($status->getColor() ?: self::FALLBACK_STATUS_COLOR),
            'refund_amount' => $this->formatAmount($orderReturn, $locale),
            '_actions' => $this->buildActions($returnId, $effectiveCode),
        ];
    }

    /**
     * The order the return is about, with the reference of the return and the date
     * it was asked for underneath, and a mark when the merchant opened it.
     */
    private function renderOrder(OrderReturn $orderReturn, int $returnId): string
    {
        // order_id is a required column behind a RESTRICT foreign key.
        $order = $orderReturn->getOrder();
        $ref = (string) $orderReturn->getRef();

        $html = \sprintf(
            '<a href="%s" class="text-decoration-none">%s</a><div class="text-muted small"><a href="%s" class="text-reset text-decoration-none" data-testid="bo-order-return-ref-link">%s</a></div><div class="text-muted small">%s</div>',
            htmlspecialchars($this->urls->generate(self::ORDER_ROUTE, ['order_id' => (int) $order->getId()]), \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars((string) $order->getRef(), \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars($this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => $returnId]), \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars($ref !== '' ? $ref : '#'.$returnId, \ENT_QUOTES | \ENT_HTML5),
            $this->renderDate($orderReturn),
        );

        if (!$orderReturn->getCreatedByAdmin()) {
            return $html;
        }

        return $html.\sprintf(
            '<div class="text-muted small"><i class="bi bi-shop me-1" aria-hidden="true"></i>%s</div>',
            htmlspecialchars($this->translator->trans('Opened by the merchant'), \ENT_QUOTES | \ENT_HTML5),
        );
    }

    private function renderCustomer(OrderReturn $orderReturn): string
    {
        // customer_id is a required column behind a RESTRICT foreign key.
        $customer = $orderReturn->getCustomer();

        return \sprintf(
            '<div class="bo-order-customer bo-order-customer--compact"><div class="fw-semibold">%s %s</div><div class="text-muted small text-truncate" title="%s">%s</div></div>',
            htmlspecialchars((string) $customer->getFirstname(), \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars((string) $customer->getLastname(), \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars((string) $customer->getEmail(), \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars((string) $customer->getEmail(), \ENT_QUOTES | \ENT_HTML5),
        );
    }

    /**
     * The product of each returned line as it was ordered, with the values of its
     * combination underneath, and the quantity when more than one unit goes back.
     *
     * @param list<array{title: string, quantity: float, product_id: ?int, attributes: list<array{attribute: string, value: string}>}> $lines
     */
    private function renderProducts(array $lines): string
    {
        if ($lines === []) {
            return '<span class="text-muted">-</span>';
        }

        $items = [];
        foreach ($lines as $line) {
            $title = htmlspecialchars($line['title'], \ENT_QUOTES | \ENT_HTML5);

            if ($line['product_id'] !== null) {
                $title = \sprintf(
                    '<a href="%s" class="text-decoration-none">%s</a>',
                    htmlspecialchars($this->urls->generate(self::PRODUCT_ROUTE, ['product_id' => $line['product_id']]), \ENT_QUOTES | \ENT_HTML5),
                    $title,
                );
            }

            if ($line['quantity'] !== 1.0) {
                $title .= \sprintf(' <span class="text-muted">&times;&nbsp;%s</span>', rtrim(rtrim(number_format($line['quantity'], 2, ',', ' '), '0'), ','));
            }

            $values = array_map(
                fn (array $attribute): string => htmlspecialchars(
                    $this->translator->trans('%attribute%: %value%', ['%attribute%' => $attribute['attribute'], '%value%' => $attribute['value']]),
                    \ENT_QUOTES | \ENT_HTML5,
                ),
                $line['attributes'],
            );

            $items[] = \sprintf(
                '<li>%s%s</li>',
                $title,
                $values === [] ? '' : '<div class="text-muted small">'.implode(', ', $values).'</div>',
            );
        }

        return '<ul class="list-unstyled mb-0 d-flex flex-column gap-1 bo-datatable__wrap" data-testid="bo-order-return-products">'.implode('', $items).'</ul>';
    }

    /**
     * What the customer expects in return, with what they wish to receive underneath
     * when they asked for an exchange.
     */
    private function renderResolution(OrderReturn $orderReturn): string
    {
        $html = \sprintf('<div>%s</div>', htmlspecialchars($this->resolutionLabels->label((string) $orderReturn->getExpectedResolution()), \ENT_QUOTES | \ENT_HTML5));
        $wish = $this->resolutionLabels->wishedExchange($orderReturn);

        if ($wish === null) {
            return $html;
        }

        $wish = htmlspecialchars($wish, \ENT_QUOTES | \ENT_HTML5);

        return $html.\sprintf(
            '<div class="text-muted small bo-datatable__wrap bo-datatable__clamp" title="%s" data-testid="bo-order-return-expected-exchange">%s</div>',
            $wish,
            $wish,
        );
    }

    /**
     * Why the customer sends the goods back: the reason picked in the shop's list,
     * or their own words when they picked none.
     *
     * @param array<int, string> $reasonTitles
     */
    private function reason(OrderReturn $orderReturn, array $reasonTitles): string
    {
        $title = $reasonTitles[(int) $orderReturn->getReasonId()] ?? '';

        // The reason may have been deleted since: the return keeps the label it
        // was opened with.
        $title = $title !== '' ? $title : (string) $orderReturn->getReasonTitle();

        return $title !== '' ? $title : trim((string) $orderReturn->getCustomerComment());
    }

    private function renderDate(OrderReturn $orderReturn): string
    {
        $createdAt = $orderReturn->getCreatedAt();

        if ($createdAt === null) {
            return '<span class="text-muted">-</span>';
        }

        $display = $createdAt->format('d/m/Y H:i');

        return \sprintf(
            '<time datetime="%s" title="%s">%s</time>',
            htmlspecialchars($createdAt->format(\DateTimeInterface::ATOM), \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars($display, \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars($display, \ENT_QUOTES | \ENT_HTML5),
        );
    }

    private function formatAmount(OrderReturn $orderReturn, string $locale): string
    {
        return $this->money->format(
            (float) $orderReturn->getRefundAmount(),
            $locale,
            $orderReturn->getOrder()->getCurrency()->getCode(),
        );
    }

    /**
     * @return list<RowAction>
     */
    private function buildActions(int $returnId, string $effectiveCode): array
    {
        $actions = [
            new RowAction(
                kind: 'view',
                label: $this->translator->trans('View return'),
                href: $this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => $returnId]),
                grantedAttribute: AccessManager::VIEW,
                grantedSubject: AdminResources::ORDER_RETURN,
            ),
        ];

        if ($effectiveCode === OrderReturnStatus::CODE_ACCEPTED) {
            $actions[] = new RowAction(
                kind: 'receive',
                label: $this->translator->trans('Record the reception'),
                href: $this->urls->generate(self::RECEPTION_ROUTE, ['order_return_id' => $returnId]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: AdminResources::ORDER_RETURN,
                inlineFrom: 'md',
            );
        }

        return $actions;
    }
}
