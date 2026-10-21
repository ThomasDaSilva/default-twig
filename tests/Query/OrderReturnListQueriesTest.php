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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\Repository\OrderReturnRepository;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnFilters;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnListRowPresenter;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductAttributeCombination;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnLine;
use Thelia\Model\OrderReturnStatus;
use Thelia\Model\OrderReturnStatusQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * What the return list costs.
 *
 * The returned lines of every row are what invites queries per row: they are
 * read for the whole page at once, and this test states that budget so a later
 * change cannot quietly go back to reading them return by return.
 */
final class OrderReturnListQueriesTest extends IntegrationTestCase
{
    private OrderReturnRepository $returns;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->returns = new OrderReturnRepository(new OrderAccess(new AdminSaleUnitScope()));
        $this->factory = $this->createFixtureFactory();
    }

    public function testAWholePageOfReturnedLinesCostsThreeQueries(): void
    {
        $product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->factory->currency(),
        );
        $twoLines = $this->returnWith(2, (string) $product->getRef());
        $oneLine = $this->returnWith(1);
        $noLine = $this->returnWith(0);

        $lines = [];
        $queries = QueryCounter::count(function () use ($twoLines, $oneLine, $noLine, &$lines): void {
            $lines = $this->returns->findLinesByReturns([
                (int) $twoLines->getId(),
                (int) $oneLine->getId(),
                (int) $noLine->getId(),
            ]);
        });

        self::assertSame(3, $queries, 'The lines, their products and their attribute values: three queries for the page, not three per return.');
        self::assertCount(2, $lines[(int) $twoLines->getId()]);
        self::assertCount(1, $lines[(int) $oneLine->getId()]);
        self::assertArrayNotHasKey(
            (int) $noLine->getId(),
            $lines,
            'A return with no line has nothing to show.',
        );

        $line = $lines[(int) $twoLines->getId()][0];
        self::assertSame('A returnable product', $line['title']);
        self::assertSame(1.0, $line['quantity']);
        self::assertSame((int) $product->getId(), $line['product_id'], 'A line points to the catalogue product of its reference.');
        self::assertSame([['attribute' => 'Size', 'value' => 'XL']], $line['attributes']);
        self::assertNull(
            $lines[(int) $oneLine->getId()][0]['product_id'],
            'A product gone from the catalogue keeps its line, without a link.',
        );
    }

    public function testAnEmptyPageAsksNothing(): void
    {
        $lines = [];
        $queries = QueryCounter::count(function () use (&$lines): void {
            $lines = $this->returns->findLinesByReturns([]);
        });

        self::assertSame(0, $queries);
        self::assertSame([], $lines);
    }

    public function testTheReasonTitlesAreReadOncePerLocale(): void
    {
        $first = QueryCounter::count(function (): void {
            $this->returns->findReasonTitles('en_US');
        });
        $second = QueryCounter::count(function (): void {
            $this->returns->findReasonTitles('en_US');
        });

        self::assertSame(1, $first);
        self::assertSame(0, $second, 'The titles are memoised for the request.');
    }

    public function testTheStatusBreakdownIsReadOncePerLocale(): void
    {
        $this->returnWith(1);

        $first = QueryCounter::count(function (): void {
            $this->returns->findStatusesWithCounts('en_US');
        });
        $second = QueryCounter::count(function (): void {
            $this->returns->findStatusesWithCounts('en_US');
        });

        self::assertGreaterThan(0, $first);
        self::assertSame(0, $second, 'The breakdown is memoised for the request.');
    }

    public function testAPageCostsTheSameWhateverTheNumberOfReturns(): void
    {
        $presenter = $this->getService(OrderReturnListRowPresenter::class);
        self::assertInstanceOf(OrderReturnListRowPresenter::class, $presenter);

        $one = $this->factory->customer($this->factory->customerTitle());
        $this->returnWith(2, null, $one);
        $three = $this->factory->customer($this->factory->customerTitle());
        for ($i = 0; $i < 3; ++$i) {
            $this->returnWith(2, null, $three);
        }

        $cost = function (Customer $customer) use ($presenter): int {
            $rows = [];
            $queries = QueryCounter::count(function () use ($customer, $presenter, &$rows): void {
                $page = $this->returns->findPaginated(new OrderReturnFilters(customerId: (int) $customer->getId()), 1, 25);
                $rows = $presenter->presentAll($page['rows'], 'en_US');
            });
            self::assertNotSame([], $rows);

            return $queries;
        };

        $cost($one);
        self::assertSame($cost($one), $cost($three), 'Three returns on the page ask what one asks: nothing is read row by row.');
    }

    private function returnWith(int $lineCount, ?string $productRef = null, ?Customer $customer = null): OrderReturn
    {
        $connection = $this->getPropelConnection();

        $customer ??= $this->factory->customer($this->factory->customerTitle());
        $order = $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID]);

        $orderReturn = (new OrderReturn())
            ->setOrder($order)
            ->setCustomer($customer)
            ->setOrderReturnStatus(
                OrderReturnStatusQuery::create()->findOneByCode(OrderReturnStatus::CODE_REQUESTED),
            );
        $orderReturn->save($connection);

        for ($i = 0; $i < $lineCount; ++$i) {
            (new OrderReturnLine())
                ->setOrderReturn($orderReturn)
                ->setOrderProduct($this->orderProduct($order, $productRef))
                ->setQuantity(1.0)
                ->save($connection);
        }

        return $orderReturn;
    }

    private function orderProduct(Order $order, ?string $productRef = null): OrderProduct
    {
        $orderProduct = (new OrderProduct())
            ->setOrderId((int) $order->getId())
            ->setProductRef($productRef ?? 'REF-'.uniqid())
            ->setProductSaleElementsRef('PSE-'.uniqid())
            ->setTitle('A returnable product')
            ->setQuantity(1.0)
            ->setPrice('10.000000')
            ->setPromoPrice('10.000000')
            ->setWasNew(1)
            ->setWasInPromo(0)
            ->setVirtual(0);
        $orderProduct->save($this->getPropelConnection());

        (new OrderProductAttributeCombination())
            ->setOrderProductId((int) $orderProduct->getId())
            ->setAttributeTitle('Size')
            ->setAttributeAvTitle('XL')
            ->save($this->getPropelConnection());

        return $orderProduct;
    }
}
