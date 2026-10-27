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

namespace BackOfficeDefaultTwigBundle\Tests\Service\Customer;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use BackOfficeDefaultTwigBundle\Repository\CustomerRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderReturnRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerOverviewProvider;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Symfony\Component\Translation\IdentityTranslator;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The figures at the top of the customer sheet: computed on the orders the shop earned
 * money from, the same statuses as the dashboard revenue, and hidden from an
 * administrator who may not see the orders.
 */
final class CustomerOverviewProviderTest extends IntegrationTestCase
{
    private FixtureFactory $factory;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
        $this->now = new \DateTimeImmutable('2026-09-29 12:00:00');
    }

    public function testACancelledOrderIsLeftOutOfTheTotalTheCountAndTheAverage(): void
    {
        $customer = $this->customer();
        $this->order($customer, OrderStatus::CODE_PAID, '10', '2026-01-10 09:00:00');
        $this->order($customer, OrderStatus::CODE_PROCESSING, '30', '2026-02-01 09:00:00');
        $this->order($customer, OrderStatus::CODE_SENT, '20', '2026-03-01 09:00:00');
        $this->order($customer, OrderStatus::CODE_PAID, '40', '2026-04-01 09:00:00');
        $this->order($customer, OrderStatus::CODE_CANCELED, '1000', '2026-05-01 09:00:00');

        $kpis = $this->provider(true)->compute($customer, $this->now)->kpis;

        self::assertNotNull($kpis);
        self::assertSame(4, $kpis->orderCount);
        self::assertSame(1, $kpis->excludedOrderCount);
        self::assertEqualsWithDelta(100.0, $kpis->totalSpent, 0.001);
        self::assertEqualsWithDelta(25.0, $kpis->averageBasket, 0.001, 'The average is the total over the orders it counts.');
        self::assertSame('2026-01-10', $kpis->firstOrderAt?->format('Y-m-d'));
        self::assertSame('2026-04-01', $kpis->lastOrderAt?->format('Y-m-d'), 'The cancelled order is not the last order the customer paid for.');
        self::assertFalse($kpis->mixesCurrencies);
    }

    /**
     * The sheet and the dashboard must not hold two definitions of revenue: over a period
     * where this customer is the only one to order, the total spent the sheet shows is the
     * revenue the dashboard reads, whatever mix of statuses the orders sit in.
     */
    public function testTheTotalSpentReadsTheSameOrdersAsTheDashboardRevenue(): void
    {
        $customer = $this->customer();
        $ownSent = $this->factory->orderStatus(['equivalentCode' => OrderStatus::CODE_SENT]);
        $amounts = [
            OrderStatus::CODE_PAID => '11', OrderStatus::CODE_PROCESSING => '22', OrderStatus::CODE_SENT => '33',
            OrderStatus::CODE_NOT_PAID => '440', OrderStatus::CODE_CANCELED => '550', OrderStatus::CODE_REFUNDED => '660',
        ];
        $day = 1;
        foreach ($amounts as $code => $amount) {
            $this->order($customer, $code, $amount, \sprintf('2001-03-%02d 10:00:00', $day++));
        }
        $order = $this->order($customer, OrderStatus::CODE_NOT_PAID, '7', '2001-03-20 10:00:00');
        $order->setStatusId($ownSent->getId())->save($this->getPropelConnection());

        $kpis = $this->provider(true)->compute($customer, $this->now)->kpis;
        $revenue = (new OrderRepository(new OrderAccess(new AdminSaleUnitScope())))->getRevenue(new DateRange(
            new \DateTimeImmutable('2001-03-01 00:00:00'),
            new \DateTimeImmutable('2001-03-31 23:59:59'),
            'custom',
        ));

        self::assertNotNull($kpis);
        self::assertEqualsWithDelta(73.0, $kpis->totalSpent, 0.001, '11 + 22 + 33 + 7 on a status answering for sent.');
        self::assertEqualsWithDelta($revenue, $kpis->totalSpent, 0.001, 'The sheet total is the dashboard revenue over the same orders.');
    }

    /**
     * The customer list and the sheet show the same customer side by side: whatever mix of
     * statuses the orders sit in, the order count and the total spent of the list row are
     * the ones the sheet shows.
     */
    public function testTheSheetShowsTheOrderCountAndTotalOfTheCustomerList(): void
    {
        $customer = $this->customer();
        $ownPaid = $this->factory->orderStatus(['equivalentCode' => OrderStatus::CODE_PAID]);
        $day = 1;
        foreach ([OrderStatus::CODE_PAID, OrderStatus::CODE_PROCESSING, OrderStatus::CODE_SENT, OrderStatus::CODE_NOT_PAID, OrderStatus::CODE_CANCELED, OrderStatus::CODE_REFUNDED] as $code) {
            $this->order($customer, $code, (string) (10 * $day), \sprintf('2026-03-%02d 10:00:00', $day++));
        }
        $order = $this->order($customer, OrderStatus::CODE_NOT_PAID, '7', '2026-03-20 10:00:00');
        $order->setStatusId($ownPaid->getId())->save($this->getPropelConnection());

        $kpis = $this->provider(true)->compute($customer, $this->now)->kpis;
        $list = new CustomerRepository(new OrderRepository(new OrderAccess(new AdminSaleUnitScope())), new OrderAccess(new AdminSaleUnitScope()));
        $customerId = (int) $customer->getId();

        self::assertNotNull($kpis);
        self::assertSame(4, $kpis->orderCount, 'Paid, processing, sent, and the status answering for paid.');
        self::assertSame($list->findOrderCounts([$customerId])[$customerId] ?? 0, $kpis->orderCount, 'The list counts the orders the sheet counts.');
        self::assertEqualsWithDelta($list->findTotalSpentByCustomer([$customerId])[$customerId] ?? 0.0, $kpis->totalSpent, 0.001, 'The list adds up the orders the sheet adds up.');
    }

    public function testACustomerWithoutOrderHasFiguresAtZeroAndNoAverage(): void
    {
        $kpis = $this->provider(true)->compute($this->customer(), $this->now)->kpis;

        self::assertNotNull($kpis);
        self::assertSame(0, $kpis->orderCount);
        self::assertSame(0, $kpis->excludedOrderCount);
        self::assertSame(0.0, $kpis->totalSpent);
        self::assertNull($kpis->averageBasket, 'No average on zero orders, rather than a division by zero.');
        self::assertNull($kpis->firstOrderAt);
        self::assertNull($kpis->lastOrderAt);
    }

    public function testAnOrderStillUnpaidIsCountedApartAndNotAsSpent(): void
    {
        $customer = $this->customer();
        $this->order($customer, OrderStatus::CODE_NOT_PAID, '80', '2026-06-01 09:00:00');

        $kpis = $this->provider(true)->compute($customer, $this->now)->kpis;

        self::assertNotNull($kpis);
        self::assertSame(0, $kpis->orderCount);
        self::assertSame(1, $kpis->excludedOrderCount);
        self::assertNull($kpis->averageBasket);
    }

    public function testAnAdministratorWhoMayNotSeeTheOrdersGetsNoFigure(): void
    {
        $customer = $this->customer();
        $this->order($customer, OrderStatus::CODE_PAID, '10', '2026-01-10 09:00:00');

        $overview = $this->provider(false)->compute($customer, $this->now);

        self::assertNull($overview->kpis);
        self::assertFalse($overview->canViewOrders);
        self::assertNotSame('', $overview->customerAge, 'The account age does not come from the orders.');
    }

    public function testOrdersPlacedInTwoCurrenciesAreFlagged(): void
    {
        $customer = $this->customer();
        $this->order($customer, OrderStatus::CODE_PAID, '10', '2026-01-10 09:00:00');
        $other = $this->factory->currency(['code' => 'XTS', 'symbol' => 'X$']);
        $order = $this->order($customer, OrderStatus::CODE_PAID, '10', '2026-01-11 09:00:00');
        $order->setCurrencyId($other->getId())->save($this->getPropelConnection());

        $kpis = $this->provider(true)->compute($customer, $this->now)->kpis;

        self::assertNotNull($kpis);
        self::assertTrue($kpis->mixesCurrencies);
        self::assertSame((string) Currency::getDefaultCurrency()->getCode(), $kpis->currencyCode);
    }

    public function testTheAccountAgeReadsInWholeUnits(): void
    {
        $customer = $this->customer('2024-06-15 10:00:00');

        self::assertSame('2 years', $this->provider(true)->compute($customer, $this->now)->customerAge);

        $customer = $this->customer('2026-07-20 10:00:00');

        self::assertSame('2 months', $this->provider(true)->compute($customer, $this->now)->customerAge);
    }

    public function testTheFiguresCostAFixedNumberOfQueries(): void
    {
        $customer = $this->customer();
        for ($i = 0; $i < 30; ++$i) {
            $this->order($customer, OrderStatus::CODE_PAID, '10', '2026-01-10 09:00:00');
        }
        $provider = $this->provider(true);

        $queries = QueryCounter::count(function () use ($provider, $customer): void {
            $provider->compute($customer, $this->now);
        });

        self::assertLessThanOrEqual(3, $queries, 'The status list, the figures, the currency: not one query per order.');
    }

    private function provider(bool $canViewOrders): CustomerOverviewProvider
    {
        $access = $this->createStub(AdminAccessChecker::class);
        // The orders only: the figures are what this test measures, the returns have their own section.
        $access->method('canView')->willReturnCallback(static fn (string $resource): bool => $resource === AdminResources::ORDER && $canViewOrders);

        $orderAccess = new OrderAccess(new AdminSaleUnitScope());

        return new CustomerOverviewProvider(new OrderRepository($orderAccess), $access, new IdentityTranslator(), static::getContainer()->get(ReturnEligibilityChecker::class), new OrderReturnRepository($orderAccess));
    }

    private function customer(?string $createdAt = null): Customer
    {
        $customer = $this->factory->customer($this->factory->customerTitle());

        if ($createdAt !== null) {
            $customer->setCreatedAt(new \DateTime($createdAt))->save($this->getPropelConnection());
        }

        return $customer;
    }

    private function order(Customer $customer, string $statusCode, string $amount, string $createdAt): \Thelia\Model\Order
    {
        $order = $this->factory->order($customer, ['statusCode' => $statusCode, 'postage' => $amount]);
        $order->setCreatedAt(new \DateTime($createdAt))->save($this->getPropelConnection());

        return $order;
    }
}
