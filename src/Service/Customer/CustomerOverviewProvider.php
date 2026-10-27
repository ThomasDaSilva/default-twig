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

use BackOfficeDefaultTwigBundle\DTO\Customer\CustomerKpis;
use BackOfficeDefaultTwigBundle\DTO\Customer\CustomerOverview;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderReturnRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Customer;

/**
 * Assembles what the customer sheet shows around the customer's record, and decides what
 * the administrator may see of it: the figures come from the orders, so an administrator
 * who may not see the orders gets none of them, and none of their queries is run.
 */
final readonly class CustomerOverviewProvider
{
    public function __construct(
        private OrderRepository $orders,
        private AdminAccessChecker $access,
        private TranslatorInterface $translator,
        private ReturnEligibilityChecker $returnEligibility,
        private OrderReturnRepository $returns,
    ) {
    }

    public function compute(Customer $customer, \DateTimeImmutable $now): CustomerOverview
    {
        $canViewOrders = $this->access->canView(AdminResources::ORDER);
        $customerSince = self::immutable($customer->getCreatedAt());

        return new CustomerOverview(
            kpis: $canViewOrders ? $this->kpis((int) $customer->getId()) : null,
            canViewOrders: $canViewOrders,
            customerSince: $customerSince,
            customerAge: $customerSince !== null ? $this->age($customerSince, $now) : '',
            returnCount: $this->returnCount((int) $customer->getId()),
        );
    }

    /**
     * The number of returns of the customer the administrator reaches, or null when the
     * sheet shows no returns section: returns off, or not allowed to see them. Nothing is
     * read then.
     */
    private function returnCount(int $customerId): ?int
    {
        if (!$this->returnEligibility->isFeatureEnabled() || !$this->access->canView(AdminResources::ORDER_RETURN)) {
            return null;
        }

        return $this->returns->countByCustomer($customerId);
    }

    private function kpis(int $customerId): CustomerKpis
    {
        $stats = $this->orders->findCustomerRevenueStats($customerId);
        $count = $stats['revenue_count'];
        $total = round($stats['total'], 2);

        // One currency: its own symbol. None or several: the shop's, and a flag, because
        // nothing converts the amounts of an order placed in another currency.
        $currency = $stats['currency_count'] === 1 && $stats['currency_id'] !== null
            ? CurrencyQuery::create()->findPk($stats['currency_id'])
            : null;
        $currency ??= Currency::getDefaultCurrency();

        return new CustomerKpis(
            orderCount: $count,
            excludedOrderCount: $stats['excluded_count'],
            totalSpent: $total,
            averageBasket: $count > 0 ? round($total / $count, 2) : null,
            firstOrderAt: $stats['first_at'],
            lastOrderAt: $stats['last_at'],
            currencyCode: (string) $currency->getCode(),
            mixesCurrencies: $stats['currency_count'] > 1,
        );
    }

    private function age(\DateTimeImmutable $since, \DateTimeImmutable $now): string
    {
        $interval = $since->diff($now);

        if ($interval->invert === 1 || $interval->days === 0) {
            return $this->translator->trans('Less than a day');
        }
        if ($interval->y > 0) {
            return $interval->y === 1
                ? $this->translator->trans('1 year')
                : $this->translator->trans('%count% years', ['%count%' => $interval->y]);
        }
        if ($interval->m > 0) {
            return $interval->m === 1
                ? $this->translator->trans('1 month')
                : $this->translator->trans('%count% months', ['%count%' => $interval->m]);
        }

        return $interval->d === 1
            ? $this->translator->trans('1 day')
            : $this->translator->trans('%count% days', ['%count%' => $interval->d]);
    }

    private static function immutable(mixed $date): ?\DateTimeImmutable
    {
        return match (true) {
            $date instanceof \DateTimeImmutable => $date,
            $date instanceof \DateTime => \DateTimeImmutable::createFromMutable($date),
            default => null,
        };
    }
}
