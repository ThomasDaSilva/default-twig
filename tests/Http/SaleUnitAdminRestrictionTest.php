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

use BackOfficeDefaultTwigBundle\Twig\SidebarCountersExtension;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Administrator\AdministratorSaleUnitsEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;
use Thelia\Domain\SaleUnit\SaleUnitActivityChecker;
use Thelia\Model\Admin;
use Thelia\Model\AdminSaleUnitQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderHistoryQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnStatus;
use Thelia\Model\OrderReturnStatusQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\SaleUnit;
use Thelia\Model\SaleUnitProductPriceQuery;
use Thelia\Model\SaleUnitQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\Trait\RecordsSqlQueries;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * An administrator restricted to some sale units in the back office: it reaches the orders,
 * the prices and stocks and the units themselves of those units only, and the sheet of an
 * administrator is where the restriction is chosen. An administrator attached to none, and a
 * shop without sale units, see nothing of it.
 */
final class SaleUnitAdminRestrictionTest extends WebIntegrationTestCase
{
    use RecordsSqlQueries;

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    private SaleUnit $north;

    private SaleUnit $south;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());

        // The suite may run on a shop that already declares sale units.
        SaleUnitQuery::create()->find($this->getPropelConnection())->delete($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testALimitedAdministratorSeesTheOrdersOfItsUnitsOnly(): void
    {
        [$northOrder, $southOrder, $nationalOrder] = $this->ordersOfTwoUnitsAndANationalOne();
        $this->actAs($this->limitedAdmin([$this->north]));

        $crawler = $this->client->request('GET', '/admin/orders');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $refs = $crawler->filter('[data-testid="bo-order-ref-link"]')->each(static fn ($link): string => trim($link->text()));
        self::assertContains($northOrder->getRef(), $refs);
        self::assertNotContains($southOrder->getRef(), $refs, 'The orders of another unit are not listed.');
        self::assertNotContains($nationalOrder->getRef(), $refs, 'A national order belongs to no unit it works on.');
        self::assertSame(['north title'], $crawler->filter('[data-testid="order-filter-sale-unit"] label')->each(static fn ($option): string => trim($option->text())), 'Its filter only offers its own units.');

        $this->actAs($this->limitedAdmin([]));
        $crawler = $this->client->request('GET', '/admin/orders');
        $refs = $crawler->filter('[data-testid="bo-order-ref-link"]')->each(static fn ($link): string => trim($link->text()));
        self::assertContains($southOrder->getRef(), $refs, 'An administrator attached to no unit reaches every order.');
        self::assertContains($nationalOrder->getRef(), $refs);
    }

    /**
     * The returns section of the customer sheet counts and lists the returns of the units
     * the administrator works on only, as the return queue does.
     */
    public function testTheCustomerSheetShowsALimitedAdministratorTheReturnsOfItsUnitsOnly(): void
    {
        ConfigQuery::write('order_return_enabled', '1');
        $this->saleUnits();
        $customer = $this->factory->customer($this->factory->customerTitle());
        $northReturn = $this->returnOf($this->orderOf($customer, $this->north));
        $southReturn = $this->returnOf($this->orderOf($customer, $this->south));
        $this->actAs($this->limitedAdmin([$this->north], [
            AdminResources::CUSTOMER => [AccessManager::VIEW],
            AdminResources::ORDER_RETURN => [AccessManager::VIEW],
        ]));

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$customer->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('1', trim($crawler->filter('[data-testid="customer-returns-section-count"]')->text()), 'The return of the other unit is not counted.');

        $crawler = $this->client->request('GET', '/admin/customer/returns?customer_id='.$customer->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $refs = $crawler->filter('[data-testid="bo-order-return-ref-link"]')->each(static fn ($link): string => trim($link->text()));
        self::assertSame([(string) $northReturn->getRef()], $refs, 'Nor listed: '.$southReturn->getRef());
    }

    public function testTheSheetOfAnOrderOfAnotherUnitDoesNotExistForALimitedAdministrator(): void
    {
        [$northOrder, $southOrder, $nationalOrder] = $this->ordersOfTwoUnitsAndANationalOne();
        $this->actAs($this->limitedAdmin([$this->north]));

        $this->client->request('GET', '/admin/order/update/'.$northOrder->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ([$southOrder, $nationalOrder] as $order) {
            $this->client->request('GET', '/admin/order/update/'.$order->getId());
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'The sheet of order '.$order->getRef().' is out of reach.');

            $this->client->request('GET', '/admin/order/pdf/invoice/'.$order->getId().'/1');
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'So is its invoice.');
        }
    }

    public function testTheActionsOnAnOrderOfAnotherUnitAreRefused(): void
    {
        [, $southOrder] = $this->ordersOfTwoUnitsAndANationalOne();
        $this->actAs($this->limitedAdmin([$this->north]));
        $statusBefore = (int) $southOrder->getStatusId();
        $paid = OrderStatusQuery::create()->findOneByCode('paid');
        self::assertNotNull($paid);
        $token = $this->sessionToken();

        $this->client->request('POST', '/admin/order/update/'.$southOrder->getId().'/status', ['status_id' => $paid->getId(), 'force' => '1', '_token' => $token]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/admin/order/list/cancel/'.$southOrder->getId(), ['_token' => $token]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/admin/order/update/'.$southOrder->getId().'/delivery-ref', ['delivery_ref' => 'X1', '_token' => $token]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/admin/order/'.$southOrder->getId().'/history/note', ['comment' => 'hello', '_token' => $token]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/admin/order/update/'.$southOrder->getId().'/shipment', ['_token' => $token]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/admin/order/update/status', ['order_ids' => [$southOrder->getId()], 'status_id' => $paid->getId(), '_token' => $token]);
        self::assertTrue($this->client->getResponse()->isRedirection());

        $fresh = OrderQuery::create()->findPk($southOrder->getId());
        self::assertSame($statusBefore, (int) $fresh?->getStatusId(), 'The order did not move, whatever the road.');
        self::assertSame(0, OrderHistoryQuery::create()->filterByOrderId($southOrder->getId())->filterByComment('hello')->count());
    }

    public function testTheNumbersOfTheOrdersFollowTheReachOfTheAdministrator(): void
    {
        $this->ordersOfTwoUnitsAndANationalOne();
        $unrestricted = $this->limitedAdmin([]);
        $this->actAs($unrestricted);
        $this->client->request('GET', '/admin/orders');
        $all = $this->orderCountOfTheSidebar();

        $this->actAs($this->limitedAdmin([$this->north]));
        // The test kernel serves every request of the test: what a process remembers for one administrator is forgotten here.
        $this->getService('twig')->getExtension(SidebarCountersExtension::class)->reset();
        $this->client->request('GET', '/admin/orders');

        self::assertSame(1, $this->orderCountOfTheSidebar(), 'The sidebar counts the orders of its unit only.');
        self::assertGreaterThan(1, $all);
    }

    public function testTheSheetOfAnAdministratorOffersTheSaleUnitsAndSavesTheChoice(): void
    {
        $this->saleUnits();
        $admin = $this->limitedAdmin([]);
        $this->actAs($this->limitedAdmin([], [AdminResources::ADMINISTRATOR => [AccessManager::VIEW, AccessManager::UPDATE]], false));

        $crawler = $this->client->request('GET', '/admin/configuration/administrators');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $field = $crawler->filter('[data-testid="administrator-sale-units-'.$admin->getId().'"] input[type="checkbox"]');
        self::assertCount(2, $field, 'Both units are offered.');

        $this->saveAdministrator($admin, [$this->north->getId()]);
        self::assertSame([(int) $this->north->getId()], $this->assignedTo($admin));

        $this->saveAdministrator($admin, [$this->north->getId(), $this->south->getId()]);
        self::assertSame([(int) $this->north->getId(), (int) $this->south->getId()], $this->assignedTo($admin));

        $crawler = $this->client->request('GET', '/admin/configuration/administrators');
        self::assertCount(2, $crawler->filter('[data-testid="administrator-sale-units-'.$admin->getId().'"] input[type="checkbox"][checked]'), 'The sheet shows what is attached.');

        $this->saveAdministrator($admin, []);
        self::assertSame([], $this->assignedTo($admin), 'Checking none lifts the restriction.');
    }

    public function testTheSheetOfAnAdministratorOffersNothingWhileTheShopHasNoSaleUnit(): void
    {
        $admin = $this->limitedAdmin([], [AdminResources::ADMINISTRATOR => [AccessManager::VIEW, AccessManager::UPDATE]], false);
        $this->actAs($admin);

        // A first request writes the answer of the shop in the shared pool, as any earlier request does.
        $this->client->request('GET', '/admin/configuration/administrators');

        $statements = $this->recordSqlQueries(function () use ($admin): void {
            $this->getService(SaleUnitActivityChecker::class)->reset();
            $this->getService(AdminSaleUnitScope::class)->reset();
            $crawler = $this->client->request('GET', '/admin/configuration/administrators');
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertCount(0, $crawler->filter('[data-testid="administrator-sale-units-'.$admin->getId().'"]'));
        });

        self::assertNotSame([], $statements);
        self::assertSame([], array_values(array_filter(
            $statements,
            static fn (string $statement): bool => 1 === preg_match('/FROM `(admin_)?sale_unit(_\w+)?`/', $statement),
        )), 'A single shop asks nothing about sale units.');
    }

    public function testALimitedAdministratorDoesNotManageTheAdministrators(): void
    {
        $this->saleUnits();
        $grants = [AdminResources::ADMINISTRATOR => [AccessManager::VIEW, AccessManager::CREATE, AccessManager::UPDATE, AccessManager::DELETE]];
        $limited = $this->limitedAdmin([$this->north], $grants);
        $colleague = $this->limitedAdmin([$this->south]);
        $this->actAs($limited);

        foreach (['/admin/configuration/administrators', '/admin/configuration/administrators/view'] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $url);
        }

        $crawler = $this->client->request('GET', '/admin/configuration');
        self::assertCount(0, $crawler->filter('a[href="/admin/configuration/administrators"]'), 'The entry is left out of the configuration menu.');
        self::assertCount(0, $crawler->filter('[data-testid="bo-top-nav-profile"]'));

        $post = fn (string $url, array $data) => $this->client->request('POST', $url, $data);
        $form = 'thelia_administrator_update_'.$limited->getId();
        $data = [$form => ['id' => (string) $limited->getId(), 'login' => 'x', 'firstname' => 'x', 'lastname' => 'x', 'email' => 'x@example.test', 'profile' => (string) $limited->getProfileId(), 'locale' => 'en_US', 'sale_units' => []]];

        // Its own restriction, lifted by an empty choice, and a colleague's, are out of its hands.
        $post('/admin/configuration/administrators/save', $data);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame([(int) $this->north->getId()], $this->assignedTo($limited), 'An empty choice never reads as every unit.');

        $post('/admin/configuration/administrators/save', [$form => ['id' => (string) $colleague->getId()] + $data[$form]]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame([(int) $this->south->getId()], $this->assignedTo($colleague));

        $post('/admin/configuration/administrators/add', ['thelia_administrator_create' => ['login' => 'new', 'sale_units' => []]]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull(\Thelia\Model\AdminQuery::create()->findOneByLogin('new'));

        $post('/admin/configuration/administrators/delete', ['administrator_id' => $colleague->getId()]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNotNull(\Thelia\Model\AdminQuery::create()->findPk($colleague->getId()));

        $post('/admin/configuration/administrators/two-factor-reset', ['administrator_id' => $colleague->getId()]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->actAs($this->limitedAdmin([], $grants, false));
        $this->client->request('GET', '/admin/configuration/administrators');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'An administrator attached to no unit still manages them.');
    }

    public function testALimitedAdministratorDoesNotWriteTheNationalPricesAndStocks(): void
    {
        $this->saleUnits();
        $currency = \Thelia\Model\CurrencyQuery::create()->findOneByByDefault(1) ?? throw new \RuntimeException('No default currency.');
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $currency, ['basePrice' => 25.0, 'baseQuantity' => 7]);
        $pse = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($pse);
        $this->actAs($this->limitedAdmin([$this->north], [AdminResources::PRODUCT => [AccessManager::VIEW, AccessManager::UPDATE, AccessManager::CREATE]]));
        $token = $this->sessionToken();

        $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'It reads them.');

        $this->client->request('POST', '/admin/product/combinations/update', ['_token' => $token, 'product_id' => $product->getId(), 'price' => [$pse->getId() => '1'], 'quantity' => [$pse->getId() => '99']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/admin/product/default-price/update', ['_token' => $token, 'product_id' => $product->getId(), 'product_sale_element_id' => $pse->getId(), 'price' => '1', 'quantity' => '99']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        foreach (['/admin/product/combination/add', '/admin/product/combination/build', '/admin/product/combination/delete', '/admin/products/create'] as $url) {
            $this->client->request('POST', $url, ['_token' => $token, 'product_id' => $product->getId(), 'product_sale_element_id' => $pse->getId()]);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $url);
        }

        $fresh = ProductSaleElementsQuery::create()->findPk($pse->getId());
        self::assertSame(7.0, (float) $fresh?->getQuantity(), 'The national stock did not move.');
        self::assertSame(25.0, (float) \Thelia\Model\ProductPriceQuery::create()->filterByProductSaleElementsId($pse->getId())->findOne()?->getPrice());
    }

    public function testTheCustomerListCountsTheOrdersOfTheUnitsOfAnAdministratorOnly(): void
    {
        $this->saleUnits();
        $customer = $this->factory->customer($this->factory->customerTitle());
        $northOrder = $this->factory->order($customer, ['statusCode' => 'paid']);
        $northOrder->setSaleUnitId($this->north->getId())->setCreatedAt('2026-01-01 10:00:00')->save($this->getPropelConnection());
        $southOrder = $this->factory->order($customer, ['statusCode' => 'paid']);
        $southOrder->setSaleUnitId($this->south->getId())->setCreatedAt('2026-02-01 10:00:00')->save($this->getPropelConnection());
        $customerId = (int) $customer->getId();
        $row = function () use ($customerId) {
            $crawler = $this->client->request('GET', '/admin/customers?sort=created_at&direction=desc');
            $link = $crawler->filter('a.bo-customer-orders[href*="customer_id='.$customerId.'"]');
            self::assertGreaterThan(0, $link->count());

            return $link->first()->ancestors()->filter('tr')->first();
        };

        $this->actAs($this->limitedAdmin([$this->north], [AdminResources::CUSTOMER => [AccessManager::VIEW]]));
        $rows = $row();
        self::assertSame('1', trim($rows->filter('.bo-customer-orders')->text()));
        self::assertSame('01/01/2026', trim($rows->filter('time div')->first()->text()), 'The last order is the last one of its units.');

        $this->actAs($this->limitedAdmin([], [AdminResources::CUSTOMER => [AccessManager::VIEW]]));
        $rows = $row();
        self::assertSame('2', trim($rows->filter('.bo-customer-orders')->text()));
        self::assertSame('01/02/2026', trim($rows->filter('time div')->first()->text()));
    }

    public function testALimitedAdministratorSeesOnlyItsSaleUnitsAndCannotCreateOrDeleteOne(): void
    {
        $this->saleUnits();
        $limited = $this->limitedAdmin([$this->north], [AdminResources::SALE_UNIT => [AccessManager::VIEW, AccessManager::UPDATE, AccessManager::CREATE, AccessManager::DELETE]]);
        $this->actAs($limited);

        $crawler = $this->client->request('GET', '/admin/configuration/sale-units');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $codes = $crawler->filter('[data-testid="sale-units-page"] table tbody tr')->each(static fn ($row): string => $row->text());
        self::assertCount(1, $codes);
        self::assertStringContainsString('north', $codes[0]);
        self::assertCount(0, $crawler->filter('[data-testid="sale-unit-create-button"]'));

        $this->client->request('GET', '/admin/configuration/sale-units/update/'.$this->south->getId());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/admin/configuration/sale-units/update/'.$this->north->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $token = $this->sessionToken();
        $this->client->request('POST', '/admin/configuration/sale-units/create', ['thelia_sale_unit_creation' => ['code' => 'west', 'title' => 'West', 'locale' => 'en_US']]);
        self::assertNull(SaleUnitQuery::create()->findOneByCode('west'));

        $this->client->request('POST', '/admin/configuration/sale-units/delete', ['sale_unit_id' => $this->north->getId(), '_token' => $token]);
        self::assertNotNull(SaleUnitQuery::create()->findPk($this->north->getId()), 'It cannot delete even its own unit.');

        $this->client->request('POST', '/admin/configuration/sale-units/toggle-active?sale_unit_id='.$this->south->getId(), ['_token' => $token]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertTrue((bool) SaleUnitQuery::create()->findPk($this->south->getId())?->getActive());

        $this->client->request('POST', '/admin/configuration/sale-units/update-position?sale_unit_id='.$this->north->getId().'&position=2', ['_token' => $token]);
        self::assertSame(1, SaleUnitQuery::create()->findPk($this->north->getId())?->getPosition(), 'The order is shared: a restricted administrator does not change it.');
    }

    public function testTheCombinationsTabHoldsTheUnitsOfALimitedAdministratorAndRefusesTheOthers(): void
    {
        $this->saleUnits();
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), \Thelia\Model\CurrencyQuery::create()->findOneByByDefault(1) ?? throw new \RuntimeException('No default currency.'), ['basePrice' => 25.0, 'baseQuantity' => 7]);
        $pse = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne($this->getPropelConnection());
        self::assertNotNull($pse);
        $this->factory->attributeCombination($pse, $this->factory->attributeAv($this->factory->attribute(['title' => 'Size']), ['title' => 'Large']));

        $grants = [AdminResources::PRODUCT => [AccessManager::VIEW, AccessManager::UPDATE], AdminResources::SALE_UNIT => [AccessManager::VIEW, AccessManager::UPDATE]];
        $this->actAs($this->limitedAdmin([$this->north], $grants));

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertCount(1, $crawler->filter('[data-testid="sale-unit-values-section"]'));
        self::assertSame([(string) $this->north->getId()], $crawler->filter('[data-testid="sale-unit-values-section"] input[name="sale_unit_id"]')->each(static fn ($node): string => (string) ($node->attr('value') ?? '')), 'Only its unit is offered.');

        $write = fn (SaleUnit $saleUnit) => $this->client->request('POST', '/admin/product/sale-unit-values/save', [
            '_token' => $this->sessionToken(),
            'product_id' => $product->getId(),
            'sale_unit_id' => $saleUnit->getId(),
            'currency_id' => \Thelia\Model\CurrencyQuery::create()->findOneByByDefault(1)?->getId(),
            'price' => [$pse->getId() => '19'],
        ]);

        $write($this->south);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, SaleUnitProductPriceQuery::create()->filterBySaleUnitId($this->south->getId())->count(), 'Nothing is written on the unit it does not reach.');

        $write($this->north);
        self::assertTrue($this->client->getResponse()->isRedirection());
        self::assertSame(1, SaleUnitProductPriceQuery::create()->filterBySaleUnitId($this->north->getId())->count());
    }

    public function testASaleUnitSomeAdministratorIsRestrictedToCannotBeDeleted(): void
    {
        $this->saleUnits();
        $this->limitedAdmin([$this->south]);
        $this->actAs($this->limitedAdmin([], [AdminResources::SALE_UNIT => [AccessManager::VIEW, AccessManager::DELETE]], false));

        $this->client->request('POST', '/admin/configuration/sale-units/delete', ['sale_unit_id' => $this->south->getId(), '_token' => $this->sessionToken()]);
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('some administrators are restricted to it', $crawler->filter('.alert-danger')->text(''));
        self::assertNotNull(SaleUnitQuery::create()->findPk($this->south->getId()));

        $this->client->request('POST', '/admin/configuration/sale-units/delete', ['sale_unit_id' => $this->north->getId(), '_token' => $this->sessionToken()]);
        self::assertNull(SaleUnitQuery::create()->findPk($this->north->getId()), 'A unit nobody is restricted to goes.');
    }

    private function actAs(Admin $admin): void
    {
        $this->injector->setAdmin($admin);
    }

    /**
     * @param list<int> $saleUnitIds
     */
    private function saveAdministrator(Admin $target, array $saleUnitIds, bool $expectSaved = true): void
    {
        $name = 'thelia_administrator_update_'.$target->getId();
        $crawler = $this->client->request('GET', '/admin/configuration/administrators');
        $token = $crawler->filter('input[name="'.$name.'[_token]"]');
        self::assertGreaterThan(0, $token->count(), 'The page renders the form of the administrator.');

        $this->client->request('POST', '/admin/configuration/administrators/save', [$name => [
            'id' => (string) $target->getId(),
            'login' => (string) $target->getLogin(),
            'firstname' => (string) $target->getFirstname(),
            'lastname' => (string) $target->getLastname(),
            'email' => (string) $target->getEmail(),
            'password' => '',
            'profile' => (string) $target->getProfileId(),
            'locale' => 'en_US',
            'sale_units' => array_map('strval', $saleUnitIds),
            '_token' => (string) $token->attr('value'),
        ]]);

        if (!$expectSaved) {
            self::assertFalse($this->client->getResponse()->isRedirect(), 'The change is refused.');

            return;
        }

        $alerts = $this->client->getResponse()->isRedirect() ? [] : $this->client->getCrawler()->filter('.alert')->each(static fn ($alert): string => trim($alert->text()));
        self::assertTrue($this->client->getResponse()->isRedirect('/admin/configuration/administrators'), 'The administrator is saved: '.($alerts[0] ?? ''));
    }

    /**
     * @return list<int>
     */
    private function assignedTo(Admin $admin): array
    {
        return array_map('intval', AdminSaleUnitQuery::create()->filterByAdminId($admin->getId())->orderBySaleUnitId()->select(['SaleUnitId'])->find($this->getPropelConnection())->getData());
    }

    /**
     * An administrator of a profile, attached to the given units. It can read and write orders, and, unless
     * said otherwise, the resources the screens under test ask.
     *
     * @param list<SaleUnit>                   $saleUnits
     * @param array<string, list<string>>|null $grants
     */
    private function limitedAdmin(array $saleUnits, ?array $grants = null, bool $attach = true): Admin
    {
        $grants = ($grants ?? []) + [
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
            AdminResources::ORDER_STATUS_FORCE => [AccessManager::UPDATE],
            AdminResources::ADMINISTRATOR => [AccessManager::VIEW],
        ];
        $admin = $this->factory->restrictedAdmin($grants);
        $admin->eraseCredentials();

        if ($attach && [] !== $saleUnits) {
            $this->getService(EventDispatcherInterface::class)->dispatch(
                new AdministratorSaleUnitsEvent((int) $admin->getId(), array_map(static fn (SaleUnit $unit): int => (int) $unit->getId(), $saleUnits)),
                TheliaEvents::ADMINISTRATOR_UPDATE_SALE_UNITS,
            );
        }

        return $admin;
    }

    private function saleUnits(): void
    {
        $this->north = $this->saleUnit('north');
        $this->south = $this->saleUnit('south');
    }

    /**
     * @return array{Order, Order, Order} the orders of the north unit, of the south unit, and a national one
     */
    private function ordersOfTwoUnitsAndANationalOne(): array
    {
        $this->saleUnits();
        $northOrder = $this->factory->order();
        $northOrder->setSaleUnitId($this->north->getId())->save($this->getPropelConnection());
        $southOrder = $this->factory->order();
        $southOrder->setSaleUnitId($this->south->getId())->save($this->getPropelConnection());
        $nationalOrder = $this->factory->order();

        return [$northOrder, $southOrder, $nationalOrder];
    }

    private function orderOf(Customer $customer, SaleUnit $saleUnit): Order
    {
        $order = $this->factory->order($customer);
        $order->setSaleUnitId($saleUnit->getId())->save($this->getPropelConnection());

        return $order;
    }

    private function returnOf(Order $order): OrderReturn
    {
        $return = (new OrderReturn())
            ->setOrder($order)
            ->setCustomerId((int) $order->getCustomerId())
            ->setOrderReturnStatus(OrderReturnStatusQuery::create()->findOneByCode(OrderReturnStatus::CODE_REQUESTED))
            ->setExpectedResolution(OrderReturn::RESOLUTION_REFUND);
        $return->save($this->getPropelConnection());

        return $return;
    }

    private function orderCountOfTheSidebar(): int
    {
        return (int) $this->client->getCrawler()->filter('[data-testid="bo-nav-order-count-total"]')->text('0');
    }

    private function saleUnit(string $code): SaleUnit
    {
        $saleUnit = (new SaleUnit())
            ->setCode($code)
            ->setActive(true)
            ->setPosition(SaleUnitQuery::create()->count() + 1)
            ->setLocale('en_US')
            ->setTitle($code.' title');
        $saleUnit->save($this->getPropelConnection());

        return $saleUnit;
    }

    private function sessionToken(): string
    {
        $html = (string) $this->client->request('GET', '/admin/orders')->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches), 'The page renders the back-office token.');

        return $matches[1];
    }
}
