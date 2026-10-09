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

namespace BackOfficeDefaultTwigBundle\Service\Dashboard;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DashboardData;
use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use BackOfficeDefaultTwigBundle\DTO\Dashboard\Kpi;
use BackOfficeDefaultTwigBundle\Repository\CustomerRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderReturnRepository;
use BackOfficeDefaultTwigBundle\Repository\ProductRepository;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Domain\Order\Reminder\UnpaidOrderReminderSettings;

/**
 * Composes every dashboard widget (KPIs, chart series, breakdowns, alerts) for
 * a given date range. Repository queries are cheap (indexed SUM/GROUP BY) so
 * we re-run them on every render; cache layer can be added later if needed.
 *
 * Each widget is gated on the ACL resource backing its data: an administrator
 * limited to the catalog gets the stock widget, not the revenue or the orders.
 * The gate skips the query too, so denied figures are never computed.
 */
final readonly class DashboardStatsProvider
{
    /**
     * When the shop sets no reminder schedule; otherwise its first step says when an
     * unpaid order needs a follow-up.
     */
    private const DEFAULT_UNPAID_FOLLOWUP_HOURS = 48;
    /**
     * How long a return request may wait for an answer before the dashboard says
     * so. A merchant who leaves a customer without a word for two days has an
     * angry customer, whatever the eventual answer is.
     */
    private const RETURN_FOLLOWUP_HOURS = 48;
    private const RECENT_ORDERS_LIMIT = 8;
    private const TOP_PRODUCTS_LIMIT = 5;
    private const LOW_STOCK_LIMIT = 5;
    private const LOW_STOCK_THRESHOLD = 5;

    public function __construct(
        private OrderRepository $orders,
        private OrderReturnRepository $returns,
        private ReturnEligibilityChecker $returnEligibility,
        private CustomerRepository $customers,
        private ProductRepository $products,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private SecurityContext $securityContext,
        private PeriodOptions $periodOptions,
        private UnpaidOrderReminderSettings $reminderSettings,
    ) {
    }

    public function compute(DateRange $range, string $locale): DashboardData
    {
        $previous = $range->previous();
        $canViewOrders = $this->canView(AdminResources::ORDER);
        $canViewCustomers = $this->canView(AdminResources::CUSTOMER);
        $canViewProducts = $this->canView(AdminResources::PRODUCT);

        $kpis = [];

        if ($canViewOrders) {
            $revenue = $this->orders->getRevenue($range);
            $orderCount = $this->orders->countOrders($range);
            $previousRevenue = $this->orders->getRevenue($previous);
            $previousOrderCount = $this->orders->countOrders($previous);

            $aov = $orderCount > 0 ? $revenue / $orderCount : 0.0;
            $previousAov = $previousOrderCount > 0 ? $previousRevenue / $previousOrderCount : 0.0;

            $kpis[] = new Kpi(
                label: $this->translator->trans('Revenue'),
                value: $this->formatCurrency($revenue),
                variationPercent: $this->variation($revenue, $previousRevenue),
                icon: 'bi-cash-stack',
                accent: 'primary',
                href: $this->urls->generate('admin.order.list'),
                testid: 'kpi-revenue',
            );
            $kpis[] = new Kpi(
                label: $this->translator->trans('Orders'),
                value: (string) $orderCount,
                variationPercent: $this->variation((float) $orderCount, (float) $previousOrderCount),
                icon: 'bi-cart-check',
                accent: 'info',
                href: $this->urls->generate('admin.order.list'),
                testid: 'kpi-orders',
            );
            $kpis[] = new Kpi(
                label: $this->translator->trans('Average order'),
                value: $this->formatCurrency($aov),
                variationPercent: $this->variation($aov, $previousAov),
                icon: 'bi-receipt',
                accent: 'success',
                testid: 'kpi-aov',
            );
        }

        if ($canViewCustomers) {
            $newCustomers = $this->customers->countNew($range);
            $previousNewCustomers = $this->customers->countNew($previous);

            $kpis[] = new Kpi(
                label: $this->translator->trans('New customers'),
                value: (string) $newCustomers,
                variationPercent: $this->variation((float) $newCustomers, (float) $previousNewCustomers),
                icon: 'bi-person-plus',
                accent: 'warning',
                href: $this->urls->generate('admin.customers'),
                testid: 'kpi-new-customers',
            );
        }

        $recentOrders = $canViewOrders ? $this->loadRecentOrders() : [];

        return new DashboardData(
            range: $range,
            kpis: $kpis,
            chart: $canViewOrders ? $this->orders->getDailyBuckets($range) : ['labels' => [], 'revenue' => []],
            statusBreakdown: $canViewOrders ? $this->orders->getStatusBreakdown($locale) : [],
            recentOrders: $recentOrders,
            recentOrderAmounts: $this->orders->findTotalAmountByOrder(
                array_map(static fn (Order $order): int => (int) $order->getId(), $recentOrders),
            ),
            // Top sellers expose order amounts, so they follow the orders resource.
            topProducts: $canViewOrders ? $this->products->findTopSellers($range, self::TOP_PRODUCTS_LIMIT, $locale) : [],
            lowStockProducts: $canViewProducts ? $this->products->findLowStock(self::LOW_STOCK_THRESHOLD, self::LOW_STOCK_LIMIT, $locale) : [],
            lowStockThreshold: self::LOW_STOCK_THRESHOLD,
            alerts: $this->buildAlerts($canViewOrders),
            periodOptions: $this->periodOptions->build($range, 'admin.home'),
            locale: $locale,
            showRevenueChart: $canViewOrders,
            showOrderStatus: $canViewOrders,
            showRecentOrders: $canViewOrders,
            showTopProducts: $canViewOrders,
            showLowStock: $canViewProducts,
        );
    }

    private function canView(string $resource): bool
    {
        return $this->securityContext->isGranted(['ADMIN'], [$resource], [], [AccessManager::VIEW]);
    }

    /**
     * @return list<Order>
     */
    private function loadRecentOrders(): array
    {
        return array_values(iterator_to_array(
            OrderQuery::create()
                ->joinWithCustomer()
                ->orderByCreatedAt(Criteria::DESC)
                ->limit(self::RECENT_ORDERS_LIMIT)
                ->find(),
        ));
    }

    /**
     * @return list<array{label: string, count: int, href: ?string, icon: string, level: string}>
     */
    private function buildAlerts(bool $canViewOrders): array
    {
        $alerts = [];

        if (!$canViewOrders) {
            return $this->appendReturnAlert($alerts);
        }

        $unpaidFollowupHours = $this->reminderSettings->schedule()->firstDelayInHours() ?? self::DEFAULT_UNPAID_FOLLOWUP_HOURS;
        $unpaid = $this->orders->countUnpaidOlderThan($unpaidFollowupHours);
        if ($unpaid > 0) {
            $alerts[] = [
                'label' => $this->translator->trans('%count% unpaid orders over %hours%h', ['%count%' => $unpaid, '%hours%' => $unpaidFollowupHours]),
                'count' => $unpaid,
                'href' => $this->urls->generate('admin.order.list', ['status_ids' => $this->orders->unpaidStatusIds()]),
                'icon' => 'bi-exclamation-triangle-fill',
                'level' => 'danger',
            ];
        }

        $awaiting = $this->orders->countAwaitingShipment();
        if ($awaiting > 0) {
            $alerts[] = [
                'label' => $this->translator->trans('%count% orders awaiting shipment', ['%count%' => $awaiting]),
                'count' => $awaiting,
                'href' => $this->urls->generate('admin.order.list', ['status_ids' => $this->orders->awaitingShipmentStatusIds()]),
                'icon' => 'bi-truck',
                'level' => 'info',
            ];
        }

        return $this->appendReturnAlert($alerts);
    }

    /**
     * The requests waiting for an answer, when the shop runs returns and the
     * administrator is allowed to see them. One indexed count query.
     *
     * @param list<array{label: string, count: int, href: ?string, icon: string, level: string}> $alerts
     *
     * @return list<array{label: string, count: int, href: ?string, icon: string, level: string}>
     */
    private function appendReturnAlert(array $alerts): array
    {
        if (!$this->returnEligibility->isFeatureEnabled() || !$this->canView(AdminResources::ORDER_RETURN)) {
            return $alerts;
        }

        $pending = $this->returns->countPendingOlderThan(self::RETURN_FOLLOWUP_HOURS);

        if ($pending === 0) {
            return $alerts;
        }

        $alerts[] = [
            'label' => $this->translator->trans(
                $pending === 1 ? '%count% return request waiting for over 48h' : '%count% return requests waiting for over 48h',
                ['%count%' => $pending],
            ),
            'count' => $pending,
            'href' => $this->urls->generate('admin.order-return.list', ['status_ids' => $this->returns->pendingStatusIds()]),
            'icon' => 'bi-arrow-return-left',
            'level' => 'warning',
        ];

        return $alerts;
    }

    private function variation(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            return $current > 0.0 ? null : 0.0;
        }

        return (($current - $previous) / $previous) * 100;
    }

    private function formatCurrency(float $amount): string
    {
        return number_format($amount, 2, ',', ' ').' €';
    }
}
