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
use Thelia\Model\ConfigQuery;
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

    private function orderLink(string $html): \DOMElement
    {
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', \LIBXML_NOERROR | \LIBXML_NOWARNING);
        $link = (new \DOMXPath($document))->query('//a[@data-testid="bo-order-ref-link"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $link);

        return $link;
    }
}
