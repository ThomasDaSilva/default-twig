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

namespace BackOfficeDefaultTwigBundle\Tests\Service\Order;

use BackOfficeDefaultTwigBundle\Service\Order\OrderListRowPresenter;
use Propel\Runtime\Connection\ConnectionWrapper;
use Propel\Runtime\Propel;
use Psr\Log\AbstractLogger;
use Thelia\Model\ConfigQuery;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductAttributeCombination;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

final class OrderListRowPresenterTest extends IntegrationTestCase
{
    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
    }

    public function testTheAmountOfAnOrderInDollarsIsShownInDollars(): void
    {
        $dollar = $this->factory->currency(['code' => 'USD', 'symbol' => '$', 'rate' => 1.1, 'byDefault' => 0]);
        $order = $this->factory->order(
            $this->factory->customer($this->factory->customerTitle()),
            ['statusCode' => OrderStatus::CODE_PAID, 'postage' => '1234.50'],
        );
        $order->setCurrencyId($dollar->getId())->save($this->getPropelConnection());
        $presenter = static::getContainer()->get(OrderListRowPresenter::class);

        $row = $presenter->present($order, 'en_US', totals: [(int) $order->getId() => 1234.5]);

        self::assertSame('$1,234.50', $row['amount']);
        self::assertSame("1\u{202F}234,50\u{a0}\$US", $presenter->present($order, 'fr_FR', totals: [(int) $order->getId() => 1234.5])['amount']);
    }

    public function testTheLinesOfAnOrderShowOverItsNumberOfArticles(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        $helmet = $this->factory->orderProduct($order, ['productRef' => 'HELMET-1', 'title' => 'Leather helmet']);
        $this->combination($helmet, 'Size', 'M');
        $this->combination($helmet, 'Colour', 'Brown');
        $this->factory->orderProduct($order, ['productRef' => 'GLOVES-2', 'title' => 'Winter gloves']);
        $presenter = static::getContainer()->get(OrderListRowPresenter::class);

        $items = $presenter->presentAll([$order], 'en_US')[0]['items_html'];

        self::assertStringContainsString('data-bs-toggle="tooltip"', $items);
        self::assertStringContainsString(
            'title="Ref.: HELMET-1 - Leather helmet - Size: M - Colour: Brown'."\n".'Ref.: GLOVES-2 - Winter gloves"',
            $items,
        );
        // Read alone, the order shows the same lines.
        self::assertSame($items, $presenter->present($order, 'en_US')['items_html']);
    }

    public function testTheTooltipEscapesWhatTheCatalogueHolds(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        $line = $this->factory->orderProduct($order, ['productRef' => 'R"1', 'title' => '<script>alert(1)</script>']);
        $this->combination($line, 'Size', '"><img src=x>');
        $presenter = static::getContainer()->get(OrderListRowPresenter::class);

        $items = $presenter->presentAll([$order], 'en_US')[0]['items_html'];

        self::assertStringNotContainsString('<script>', $items);
        self::assertStringNotContainsString('<img', $items);
        self::assertStringContainsString('R&quot;1 - &lt;script&gt;alert(1)&lt;/script&gt; - Size: &quot;&gt;&lt;img src=x&gt;', $items);
    }

    public function testALongOrderNamesTheLinesTheTooltipLeavesOut(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        for ($n = 1; $n <= 12; ++$n) {
            $this->factory->orderProduct($order, ['productRef' => 'LINE-'.$n, 'title' => 'Line '.$n]);
        }
        $presenter = static::getContainer()->get(OrderListRowPresenter::class);

        $items = $presenter->presentAll([$order], 'en_US')[0]['items_html'];

        self::assertStringContainsString('Ref.: LINE-10 - Line 10'."\n".'and 2 more"', $items);
        self::assertStringNotContainsString('LINE-11', $items);
    }

    public function testTheListShowsTheReferenceOfAnOrderByDefault(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        $presenter = static::getContainer()->get(OrderListRowPresenter::class);

        $link = $this->orderLink($presenter->present($order, 'en_US')['order_html']);

        self::assertSame((string) $order->getRef(), $link->textContent);
        self::assertFalse($link->hasAttribute('title'));
    }

    public function testAShopThatReadsItsOrdersByNumberSeesTheIdWithTheReferenceOverIt(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        $order->setRef('ORD"<b>1')->save($this->getPropelConnection());
        ConfigQuery::write(OrderListRowPresenter::SHOWS_ORDER_ID, '1');
        $presenter = static::getContainer()->get(OrderListRowPresenter::class);

        try {
            $html = $presenter->present($order, 'en_US')['order_html'];
        } finally {
            ConfigQuery::write(OrderListRowPresenter::SHOWS_ORDER_ID, '0');
        }

        $link = $this->orderLink($html);
        self::assertSame((string) $order->getId(), $link->textContent);
        self::assertSame('ORD"<b>1', $link->getAttribute('title'));
        self::assertStringNotContainsString('<b>', $html);
    }

    public function testAnOrderWithoutLinesHasNoTooltip(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        $presenter = static::getContainer()->get(OrderListRowPresenter::class);

        $items = $presenter->presentAll([$order], 'en_US')[0]['items_html'];

        self::assertStringNotContainsString('tooltip', $items);
        self::assertStringContainsString('0 articles', $items);
    }

    public function testTheLinesOfAPageAreReadInOneQueryWhateverItsSize(): void
    {
        $customer = $this->factory->customer($this->factory->customerTitle());
        $orders = [];
        for ($n = 0; $n < 3; ++$n) {
            $orders[] = $order = $this->factory->order($customer);
            $this->combination($this->factory->orderProduct($order), 'Size', 'L');
        }
        // An order without lines must not be read again on its own either.
        $orders[] = $this->factory->order($customer);
        $presenter = static::getContainer()->get(OrderListRowPresenter::class);

        $queries = $this->queriesOf(static fn () => $presenter->presentAll($orders, 'en_US'));

        $lineReads = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'order_product_attribute_combination'));
        self::assertCount(1, $lineReads, implode("\n", $lineReads));
    }

    /**
     * The SQL statements $work sends, as Propel logs them in debug mode.
     *
     * @return list<string>
     */
    private function queriesOf(callable $work): array
    {
        $connection = Propel::getConnection('TheliaMain');
        self::assertInstanceOf(ConnectionWrapper::class, $connection);
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $previousLogger = $connection->getLogger();
        $wasDebug = $connection->isInDebugMode();
        $connection->setLogger($logger);
        $connection->useDebug(true);

        try {
            $work();
        } finally {
            $connection->setLogger($previousLogger);
            $connection->useDebug($wasDebug);
        }

        return $logger->messages;
    }

    private function orderLink(string $html): \DOMElement
    {
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', \LIBXML_NOERROR | \LIBXML_NOWARNING);
        $link = (new \DOMXPath($document))->query('//a[@data-testid="bo-order-ref-link"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $link);

        return $link;
    }

    private function combination(OrderProduct $line, string $attribute, string $value): void
    {
        (new OrderProductAttributeCombination())
            ->setOrderProductId((int) $line->getId())
            ->setAttributeTitle($attribute)
            ->setAttributeAvTitle($value)
            ->save($this->getPropelConnection());
    }
}
