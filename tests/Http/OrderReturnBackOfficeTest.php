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

namespace BackOfficeDefaultTwigBundle\Tests\Http;

use BackOfficeDefaultTwigBundle\Event\OrderReturnListEvent;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\Column;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Action\OrderReturn as OrderReturnAction;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductAttributeCombination;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnLine;
use Thelia\Model\OrderReturnQuery;
use Thelia\Model\OrderReturnReason;
use Thelia\Model\OrderReturnStatus;
use Thelia\Model\OrderReturnStatusQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The back-office screens of the product returns, over HTTP: the queue, its
 * filter, the sheet of one request, the accord followed by the reception, the
 * reasons configuration screen, and the refusal served to an administrator who
 * does not hold the returns permission.
 *
 * The Twig back-office only registers its routes when it is the active admin
 * template of the shop the kernel boots on (see BackOfficeDefaultTwigBundle),
 * so the suite states that requirement instead of reading a 404 as a routing
 * bug.
 */
final class OrderReturnBackOfficeTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        // Built without createFixtureFactory(): that helper pushes a synthetic
        // request when the stack is empty, and it would then be the "main"
        // request the SecurityContext reads its session from.
        $this->factory = new FixtureFactory($this->getPropelConnection());

        ConfigQuery::write('order_return_enabled', '1');
        ConfigQuery::write('order_return_restock_mode', OrderReturnAction::RESTOCK_MODE_RESELLABLE);
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheQueueOfRequestsIsServedToAGrantedAdministrator(): void
    {
        $this->loginFullAdmin();
        [$return] = $this->requestedReturn();

        $this->assertPageRenders('/admin/returns');

        self::assertStringContainsString(
            (string) $return->getRef(),
            (string) $this->client->getResponse()->getContent(),
            'The queue must list the return that is waiting for an answer.',
        );
    }

    public function testTheQueueFiltersByStatus(): void
    {
        $this->loginFullAdmin();
        [$requested] = $this->requestedReturn();
        [$refused] = $this->requestedReturn(OrderReturnStatus::CODE_REFUSED);

        $refusedStatusId = $this->statusId(OrderReturnStatus::CODE_REFUSED);

        $this->assertPageRenders('/admin/returns?status_ids%5B%5D='.$refusedStatusId);

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $refused->getRef(), $html);
        self::assertStringNotContainsString(
            (string) $requested->getRef(),
            $html,
            'A return outside the filtered status must not reach the page.',
        );
    }

    public function testTheQueueShowsWhatIsReturnedAndWhy(): void
    {
        $this->loginFullAdmin();
        [$return] = $this->requestedReturn();

        $crawler = $this->client->request('GET', '/admin/returns');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $row = $crawler->filter('[data-testid="datatable-order-returns-row"][data-row-id="'.$return->getId().'"]');
        self::assertCount(1, $row);
        self::assertStringContainsString('A returnable product', $row->filter('[data-testid="datatable-order-returns-cell-products_html"]')->text());
        self::assertStringContainsString('Size: XL', $row->filter('[data-testid="datatable-order-returns-cell-products_html"]')->text(), 'The line names the values of the ordered combination.');
        self::assertStringContainsString("×\u{a0}2", $row->filter('[data-testid="datatable-order-returns-cell-products_html"]')->text(), 'Two units go back.');
        self::assertSame('Damaged on arrival', trim($row->filter('[data-testid="datatable-order-returns-cell-reason"]')->text()));
        self::assertSame('Refund', trim($row->filter('[data-testid="datatable-order-returns-cell-resolution"]')->text()));

        $reason = $row->filter('[data-testid="datatable-order-returns-cell-reason"] .bo-datatable__wrap');
        self::assertCount(1, $reason, 'A reason typed by a person breaks over several lines instead of widening the grid.');
        self::assertSame('Damaged on arrival', $reason->attr('title'), 'The whole text stays reachable once the cell is cut to four lines.');
        $cellKeys = $row->filter('td')->each(static fn ($cell): string => (string) $cell->attr('data-testid'));
        $reasonHeader = $crawler->filter('[data-testid="datatable-order-returns-table"] thead th')
            ->eq((int) array_search('datatable-order-returns-cell-reason', $cellKeys, true));
        self::assertStringContainsString('bo-datatable__wrap-head', (string) $reasonHeader->attr('class'), 'The header of the column breaks too.');
    }

    public function testTheQueueKeepsTheReasonOfADeletedReasonAndShowsAReturnWithoutLine(): void
    {
        $this->loginFullAdmin();
        [$return, , $line] = $this->requestedReturn();
        $line->delete($this->getPropelConnection());
        $return->setReasonId(null)->setReasonTitle('Arrived too late')->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/returns');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $row = $crawler->filter('[data-testid="datatable-order-returns-row"][data-row-id="'.$return->getId().'"]');
        self::assertSame('Arrived too late', trim($row->filter('[data-testid="datatable-order-returns-cell-reason"]')->text()), 'The return keeps the label it was opened with.');
        self::assertSame('-', trim($row->filter('[data-testid="datatable-order-returns-cell-products_html"]')->text()));
    }

    public function testTheQueueFiltersByExpectedResolution(): void
    {
        $this->loginFullAdmin();
        [$refund] = $this->requestedReturn();
        [$exchange] = $this->requestedReturn(resolution: OrderReturn::RESOLUTION_EXCHANGE);

        $this->assertPageRenders('/admin/returns?resolution='.OrderReturn::RESOLUTION_EXCHANGE);

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $exchange->getRef(), $html);
        self::assertStringNotContainsString(
            (string) $refund->getRef(),
            $html,
            'A return expecting another resolution must not reach the page.',
        );
        self::assertStringContainsString('order-return-active-chip-resolution', $html, 'The applied filter shows as a removable chip.');
    }

    public function testAnUnknownResolutionIsIgnored(): void
    {
        $this->loginFullAdmin();
        [$return] = $this->requestedReturn();

        $this->assertPageRenders('/admin/returns?resolution=%27%20OR%201%3D1');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $return->getRef(), $html);
        self::assertStringNotContainsString('order-return-active-chip-resolution', $html);
    }

    public function testTheProductOfALineIsEscapedInTheQueue(): void
    {
        $this->loginFullAdmin();
        $this->requestedReturn(productTitle: '<script>alert(1)</script>');

        $this->assertPageRenders('/admin/returns');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAModuleReshapesTheQueue(): void
    {
        $this->loginFullAdmin();
        [$return] = $this->requestedReturn();

        $this->getService(EventDispatcherInterface::class)->addListener(
            OrderReturnListEvent::class,
            static function (OrderReturnListEvent $event): void {
                $event->removeColumn('ref_html');
                $event->insertColumnAfter('resolution', new Column('wish', 'Wished product'));
                $event->setRows(array_map(
                    static fn (array $row): array => $row + ['wish' => 'Wish of return '.$row['id']],
                    $event->getRows(),
                ));
                $event->addHeaderAction('Export the returns', '/admin/export/42', 'bi-download');
            },
        );

        $crawler = $this->client->request('GET', '/admin/returns');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $row = $crawler->filter('[data-testid="datatable-order-returns-row"][data-row-id="'.$return->getId().'"]');
        $cells = $row->filter('td[data-testid^="datatable-order-returns-cell-"]')->each(
            static fn ($cell): string => substr((string) $cell->attr('data-testid'), \strlen('datatable-order-returns-cell-')),
        );
        self::assertNotContains('ref_html', $cells, 'A removed column leaves the table.');
        $resolution = array_search('resolution', $cells, true);
        self::assertIsInt($resolution);
        self::assertSame('wish', $cells[$resolution + 1], 'The column sits where the module placed it.');
        self::assertContains('Wished product', $crawler->filter('[data-testid="datatable-order-returns-table"] thead th')->each(
            static fn ($header): string => trim($header->text()),
        ));
        self::assertSame('Wish of return '.$return->getId(), trim($row->filter('[data-testid="datatable-order-returns-cell-wish"]')->text()));

        $action = $crawler->filter('[data-testid="order-return-header-action"][href="/admin/export/42"]');
        self::assertCount(1, $action);
        self::assertStringContainsString('Export the returns', $action->text());
    }

    public function testTheSheetShowsTheRequestAndOffersTheCycleActions(): void
    {
        $this->loginFullAdmin();
        [$return] = $this->requestedReturn();

        $this->assertPageRenders('/admin/return/'.$return->getId());

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('A returnable product', $html, 'The sheet must list the requested line.');
        self::assertStringContainsString('Damaged on arrival', $html, 'The sheet must name the reason of the request.');
        self::assertStringContainsString('order-return-action-accepted', $html);
        self::assertStringContainsString('order-return-action-refused', $html);
        self::assertStringNotContainsString(
            'order-return-action-settled',
            $html,
            'A requested return cannot jump straight to settled: the actions come from the state machine.',
        );
    }

    public function testTheCustomerCommentIsEscapedOnTheSheet(): void
    {
        $this->loginFullAdmin();
        [$return] = $this->requestedReturn(comment: '<script>alert(1)</script>');

        $this->assertPageRenders('/admin/return/'.$return->getId());

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAcceptingThenReceivingRestocksTheLine(): void
    {
        $this->loginFullAdmin();
        [$return, $pse, $line] = $this->requestedReturn();
        $stockBefore = $this->stockOf($pse);

        $this->client->request('POST', '/admin/return/'.$return->getId().'/transition', [
            '_token' => $this->tokenOf('/admin/return/'.$return->getId()),
            'status_id' => $this->statusId(OrderReturnStatus::CODE_ACCEPTED),
        ]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(OrderReturnStatus::CODE_ACCEPTED, $this->statusCodeOf($return));

        $this->client->request('POST', '/admin/return/'.$return->getId().'/reception', [
            '_token' => $this->tokenOf('/admin/return/'.$return->getId().'/reception'),
            'quantity_received' => [(string) $line->getId() => '1'],
            'resellable' => [(string) $line->getId() => '1'],
            'received_condition' => [(string) $line->getId() => OrderReturnLine::CONDITION_GOOD],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(OrderReturnStatus::CODE_RECEIVED, $this->statusCodeOf($return));
        self::assertSame(
            $stockBefore + 1.0,
            $this->stockOf($pse),
            'One unit received in a resellable condition goes back into the stock.',
        );
    }

    public function testTheReceptionRefusesMoreThanWhatWasRequested(): void
    {
        $this->loginFullAdmin();
        [$return, $pse, $line] = $this->requestedReturn(OrderReturnStatus::CODE_ACCEPTED);
        $stockBefore = $this->stockOf($pse);

        $this->client->request('POST', '/admin/return/'.$return->getId().'/reception', [
            '_token' => $this->tokenOf('/admin/return/'.$return->getId().'/reception'),
            // The line asks for 2 units back.
            'quantity_received' => [(string) $line->getId() => '99'],
            'resellable' => [(string) $line->getId() => '1'],
            'received_condition' => [(string) $line->getId() => OrderReturnLine::CONDITION_GOOD],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            OrderReturnStatus::CODE_ACCEPTED,
            $this->statusCodeOf($return),
            'A reception larger than the request must not move the return.',
        );
        self::assertSame($stockBefore, $this->stockOf($pse), 'Nothing enters the stock on a refused reception.');
    }

    public function testTheReasonsConfigurationScreenIsServed(): void
    {
        $this->loginFullAdmin();
        $this->reason('Too big');

        $this->assertPageRenders('/admin/configuration/order-return-reason');

        self::assertStringContainsString('Too big', (string) $this->client->getResponse()->getContent());
    }

    public function testAnAdministratorWithoutTheReturnsPermissionIsRefused(): void
    {
        $admin = $this->factory->restrictedAdmin([
            AdminResources::ORDER => [AccessManager::VIEW],
        ]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        [$return] = $this->requestedReturn();

        foreach ([
            '/admin/returns',
            '/admin/return/'.$return->getId(),
            '/admin/return/'.$return->getId().'/reception',
            '/admin/configuration/order-return-reason',
        ] as $url) {
            $this->client->request('GET', $url);

            self::assertSame(
                403,
                $this->client->getResponse()->getStatusCode(),
                \sprintf('"%s" must be refused to an administrator without the returns permission.', $url),
            );
        }
    }

    public function testEveryScreenIsGoneWhileTheFeatureIsOff(): void
    {
        $this->loginFullAdmin();
        [$return] = $this->requestedReturn();

        ConfigQuery::write('order_return_enabled', '0');

        foreach ([
            '/admin/returns',
            '/admin/return/'.$return->getId(),
            '/admin/return/'.$return->getId().'/reception',
            '/admin/configuration/order-return-reason',
        ] as $url) {
            $this->client->request('GET', $url);

            self::assertSame(
                404,
                $this->client->getResponse()->getStatusCode(),
                \sprintf('"%s" must not exist while the shop has not turned the returns on.', $url),
            );
        }
    }

    private function loginFullAdmin(): void
    {
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    /**
     * A return of two units of one returnable product, in the given status.
     *
     * @return array{OrderReturn, ProductSaleElements, OrderReturnLine}
     */
    private function requestedReturn(
        string $statusCode = OrderReturnStatus::CODE_REQUESTED,
        string $comment = 'The parcel arrived open.',
        string $resolution = OrderReturn::RESOLUTION_REFUND,
        string $productTitle = 'A returnable product',
    ): array {
        $connection = $this->getPropelConnection();

        $customer = $this->factory->customer($this->factory->customerTitle());
        $product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->factory->currency(),
        );
        $pse = $this->factory->productSaleElement($product, ['quantity' => 10]);

        $order = $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID]);
        $orderProduct = $this->orderProduct($order, $pse, 4.0, $productTitle);

        $return = (new OrderReturn())
            ->setOrder($order)
            ->setCustomer($customer)
            ->setOrderReturnStatus(OrderReturnStatusQuery::create()->findOneByCode($statusCode))
            ->setOrderReturnReason($this->reason('Damaged on arrival'))
            ->setReasonTitle('Damaged on arrival')
            ->setCustomerComment($comment)
            ->setExpectedResolution($resolution);
        $return->save($connection);

        $line = (new OrderReturnLine())
            ->setOrderReturn($return)
            ->setOrderProduct($orderProduct)
            ->setProductSaleElementsId((int) $pse->getId())
            ->setQuantity(2.0);
        $line->save($connection);

        return [$return, $pse, $line];
    }

    private function reason(string $title): OrderReturnReason
    {
        $reason = new OrderReturnReason();
        $reason
            ->setLocale('en_US')
            ->setTitle($title)
            ->setVisible(true)
            ->setPosition(1);
        $reason->save($this->getPropelConnection());

        return $reason;
    }

    private function orderProduct(Order $order, ProductSaleElements $pse, float $quantity, string $title): OrderProduct
    {
        $orderProduct = (new OrderProduct())
            ->setOrderId((int) $order->getId())
            ->setProductRef('REF-'.uniqid())
            ->setProductSaleElementsRef((string) $pse->getRef())
            ->setProductSaleElementsId((int) $pse->getId())
            ->setTitle($title)
            ->setQuantity($quantity)
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

    /**
     * The CSRF token the given page renders, so a POST is submitted the way the
     * browser submits it.
     */
    private function tokenOf(string $url): string
    {
        $crawler = $this->client->request('GET', $url);
        $token = $crawler->filter('input[name="_token"]')->first();

        self::assertGreaterThan(0, $token->count(), \sprintf('"%s" renders no CSRF token.', $url));

        return (string) $token->attr('value');
    }

    private function statusId(string $code): int
    {
        return (int) OrderReturnStatusQuery::create()->findOneByCode($code)?->getId();
    }

    private function statusCodeOf(OrderReturn $return): string
    {
        $fresh = OrderReturnQuery::create()->findPk($return->getId(), $this->getPropelConnection());
        $fresh?->reload(true, $this->getPropelConnection());

        return (string) $fresh?->getOrderReturnStatus()?->getCode();
    }

    private function stockOf(ProductSaleElements $pse): float
    {
        ProductSaleElementsTableMap::clearInstancePool();

        return (float) ProductSaleElementsQuery::create()
            ->findPk($pse->getId(), $this->getPropelConnection())
            ?->getQuantity();
    }
}
