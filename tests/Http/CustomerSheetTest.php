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

use BackOfficeDefaultTwigBundle\Controller\Customer\CustomerReturnsController;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Domain\Customer\Service\CustomerAnonymizer;
use Thelia\Model\Admin;
use Thelia\Model\AdminLogQuery;
use Thelia\Model\Config;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductAttributeCombination;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnLine;
use Thelia\Model\OrderReturnStatus;
use Thelia\Model\OrderReturnStatusQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The customer sheet as a salesperson reads it: what the customer spent, bought, left in a
 * cart, and what the administrator is allowed to see of it.
 */
final class CustomerSheetTest extends WebIntegrationTestCase
{
    /**
     * Measured on the sheet with 200 orders: 40 queries on a cold request, 21 once the
     * request-scoped memos are warm (37 and 19 before the figures and the sections). The
     * cold count moves by one between runs, hence the margin. The sidebar total of orders
     * is read again on every request (22 warm), as the memo of the sidebar is reset
     * between two requests: it holds the figures of the administrator of the request.
     */
    private const COLD_QUERY_BUDGET = 41;
    private const WARM_QUERY_BUDGET = 22;

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    /** @var list<array{0: string, 1: callable}> */
    private array $listeners = [];

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
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->injector->clear();
        }

        foreach ($this->listeners as [$eventName, $listener]) {
            $this->getService(EventDispatcherInterface::class)->removeListener($eventName, $listener);
        }

        ConfigQuery::resetCache();
        parent::tearDown();
    }

    public function testTheFiguresLeaveTheCancelledOrderOut(): void
    {
        $customer = $this->customer();
        foreach ([OrderStatus::CODE_PAID, OrderStatus::CODE_PROCESSING, OrderStatus::CODE_SENT, OrderStatus::CODE_PAID] as $index => $code) {
            $this->factory->order($customer, ['statusCode' => $code, 'postage' => (string) (10 * ($index + 1))]);
        }
        $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_CANCELED, 'postage' => '1000']);

        $crawler = $this->sheet($customer, $this->factory->admin());

        self::assertStringStartsWith('€100.00', $this->text($crawler, 'customer-kpi-total-spent-value'));
        self::assertSame('4', $this->text($crawler, 'customer-kpi-order-count-value'));
        self::assertStringContainsString('1', $this->text($crawler, 'customer-kpi-order-count-note'), 'The cancelled order is said to be left out.');
        self::assertStringStartsWith('€25.00', $this->text($crawler, 'customer-kpi-average-basket-value'));
        self::assertCount(1, $crawler->filter('[data-testid="customer-kpi-total-spent-hint"]'), 'The definition is shown next to the figure.');
    }

    public function testACustomerWithoutOrderShowsZeroFiguresAndAnEmptyOrderList(): void
    {
        $crawler = $this->sheet($this->customer(), $this->factory->admin());

        self::assertStringStartsWith('€0.00', $this->text($crawler, 'customer-kpi-total-spent-value'));
        self::assertSame('0', $this->text($crawler, 'customer-kpi-order-count-value'));
        self::assertSame('-', $this->text($crawler, 'customer-kpi-average-basket-value'));
        self::assertCount(1, $crawler->filter('[data-testid="customer-orders-section"]'), 'An empty section is shown, not hidden.');
    }

    public function testAnAdministratorWhoMayNotSeeTheOrdersSeesNeitherTheOrdersNorTheirFigures(): void
    {
        $customer = $this->customer();
        $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID, 'postage' => '10']);
        $admin = $this->factory->restrictedAdmin([AdminResources::CUSTOMER => [AccessManager::VIEW]]);

        $crawler = $this->sheet($customer, $admin);

        self::assertCount(0, $crawler->filter('[data-testid="customer-kpi-total-spent"]'));
        self::assertCount(0, $crawler->filter('[data-testid="customer-kpi-order-count"]'));
        self::assertCount(0, $crawler->filter('[data-testid="customer-orders-section"]'));
        self::assertCount(1, $crawler->filter('[data-testid="customer-kpi-customer-since"]'), 'The account age does not come from the orders.');
    }

    public function testTheCartInProgressShowsItsContentItsAmountAndALinkToTheProduct(): void
    {
        $customer = $this->customer();
        $product = $this->product('Linen jacket');
        $cart = $this->factory->cart($customer);
        $this->factory->cartItem($cart, $product, null, ['quantity' => 2.0, 'price' => '45.000000']);

        $crawler = $this->carts($customer, $this->factory->admin());

        self::assertStringStartsWith('€90.00', $this->text($crawler, 'customer-current-cart-amount'));
        $link = $crawler->filter('[data-testid="customer-current-cart-product-link"]');
        self::assertCount(1, $link);
        self::assertSame('Linen jacket', trim($link->text()));
        self::assertStringContainsString('product_id='.$product->getId(), (string) $link->attr('href'));
    }

    public function testACartAbandonedThreeWeeksAgoIsListedAndDated(): void
    {
        $customer = $this->customer();
        $cart = $this->factory->cart($customer);
        $this->factory->cartItem($cart, $this->product('Wool scarf'));
        $abandonedOn = new \DateTimeImmutable('-21 days');
        $date = $abandonedOn->format('Y-m-d H:i:s');
        $this->getPropelConnection()->prepare('UPDATE cart SET created_at = ?, updated_at = ? WHERE id = ?')->execute([$date, $date, $cart->getId()]);
        $this->getPropelConnection()->prepare('UPDATE cart_item SET created_at = ?, updated_at = ? WHERE cart_id = ?')->execute([$date, $date, $cart->getId()]);

        $crawler = $this->carts($customer, $this->factory->admin());

        self::assertCount(1, $crawler->filter('[data-testid="customer-current-cart-empty"]'));
        $rows = $crawler->filter('[data-testid="datatable-customer-abandoned-carts-row"]');
        self::assertCount(1, $rows);
        self::assertStringContainsString($abandonedOn->format('Y'), $rows->text(), 'The abandoned cart carries its date.');
    }

    public function testACustomerWhoNeverLeftACartIsToldSo(): void
    {
        $crawler = $this->carts($this->customer(), $this->factory->admin());

        self::assertCount(1, $crawler->filter('[data-testid="customer-current-cart-empty"]'));
        self::assertStringContainsString('60', $this->text($crawler, 'datatable-customer-abandoned-carts-empty'), 'The empty list says over which period.');
        self::assertCount(1, $crawler->filter('[data-testid="customer-carts-horizon"]'));
    }

    public function testTheCartsSectionIsRefusedWithoutTheCustomerPermission(): void
    {
        $customer = $this->customer();
        $admin = $this->factory->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW]]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $this->client->request('GET', '/admin/customer/carts?customer_id='.$customer->getId());

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTheCartsOfAnUnknownCustomerAreNotFound(): void
    {
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $this->client->request('GET', '/admin/customer/carts?customer_id=987654321');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testEverySectionIsThereAndTheCartsWaitToBeOpened(): void
    {
        $crawler = $this->sheet($this->customer(), $this->factory->admin());

        foreach (['customer-section-overview', 'customer-addresses-section', 'customer-orders-section', 'customer-carts-section', 'customer-personal-data-section'] as $testid) {
            self::assertCount(1, $crawler->filter('[data-testid="'.$testid.'"]'), $testid.' is on the sheet.');
            self::assertCount(1, $crawler->filter('[data-testid="'.$testid.'-toggle"][data-bs-toggle="collapse"]'), $testid.' folds.');
        }

        $carts = $crawler->filter('[data-testid="customer-carts-section"]');
        self::assertStringContainsString('/admin/customer/carts?customer_id=', (string) $carts->attr('data-bo-lazy-collapse-url-value'));
        self::assertSame('false', $carts->filter('[data-testid="customer-carts-section-toggle"]')->attr('aria-expanded'), 'The carts are only read once the section is opened.');
        self::assertCount(0, $crawler->filter('[data-testid="customer-carts"]'), 'Their content is not in the page.');
        self::assertSame('true', $crawler->filter('[data-testid="customer-orders-section-toggle"]')->attr('aria-expanded'));
    }

    public function testTheReturnsWaitToBeOpenedAndTheSheetCountsThoseOfTheCustomer(): void
    {
        ConfigQuery::write('order_return_enabled', '1');
        $customer = $this->customer();
        $order = $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_SENT]);
        $this->returnOf($order);
        $this->returnOf($order);
        $this->returnOf($this->factory->order($this->customer(), ['statusCode' => OrderStatus::CODE_SENT]));

        $crawler = $this->sheet($customer, $this->factory->admin());

        $section = $crawler->filter('[data-testid="customer-returns-section"]');
        self::assertCount(1, $section);
        self::assertStringContainsString('/admin/customer/returns?customer_id='.$customer->getId(), (string) $section->attr('data-bo-lazy-collapse-url-value'));
        self::assertSame('2', trim($section->filter('[data-testid="customer-returns-section-count"]')->text()), 'The returns of this customer only.');
        self::assertSame('false', $section->filter('[data-testid="customer-returns-section-toggle"]')->attr('aria-expanded'), 'The returns are only read once the section is opened.');
        self::assertCount(0, $crawler->filter('[data-testid="customer-returns"]'), 'Their rows are not in the page.');
    }

    public function testNoReturnsSectionWhileReturnsAreOffOrForAnAdministratorWhoMayNotSeeThem(): void
    {
        $customer = $this->customer();

        ConfigQuery::write('order_return_enabled', '0');
        self::assertCount(0, $this->sheet($customer, $this->factory->admin())->filter('[data-testid="customer-returns-section"]'), 'A shop with returns off has no returns section.');

        ConfigQuery::write('order_return_enabled', '1');
        $admin = $this->factory->restrictedAdmin([AdminResources::CUSTOMER => [AccessManager::VIEW]]);
        self::assertCount(0, $this->sheet($customer, $admin)->filter('[data-testid="customer-returns-section"]'), 'Nor an administrator without the returns permission.');
    }

    public function testTheReturnsOfTheCustomerAreLaidOutAsInTheReturnQueue(): void
    {
        ConfigQuery::write('order_return_enabled', '1');
        $customer = $this->customer();
        $return = $this->returnOf($this->factory->order($customer, ['statusCode' => OrderStatus::CODE_SENT]));
        $this->returnOf($this->factory->order($this->customer(), ['statusCode' => OrderStatus::CODE_SENT]));

        $crawler = $this->returns($customer, $this->factory->admin());

        $rows = $crawler->filter('[data-testid="datatable-customer-returns-row"]');
        self::assertCount(1, $rows, 'The return of another customer is not listed.');
        $orderCell = $rows->filter('[data-testid="datatable-customer-returns-cell-order_html"]');
        self::assertStringContainsString((string) $return->getOrder()->getRef(), $orderCell->text(), 'The order names the row.');
        self::assertSame((string) $return->getRef(), trim($orderCell->filter('.text-muted.small [data-testid="bo-order-return-ref-link"]')->text()), 'The return reference sits under the order.');
        self::assertCount(1, $orderCell->filter('.text-muted.small time'), 'So does the date it was asked for.');
        $products = $rows->filter('[data-testid="datatable-customer-returns-cell-products_html"]');
        self::assertSame('PSE-RETURNED', $products->filter('code')->text(), 'Each line is named by the reference of the ordered combination.');
        self::assertStringContainsString('Size: XL', $products->text());
        self::assertSame('Too small', trim($rows->filter('[data-testid="datatable-customer-returns-cell-reason"]')->text()));
        self::assertSame('Exchange', trim($rows->filter('[data-testid="datatable-customer-returns-cell-resolution_html"]')->text()));
        self::assertCount(0, $crawler->filter('[data-testid="datatable-customer-returns-cell-customer_html"]'), 'No customer column on the sheet of the customer.');
        self::assertCount(0, $crawler->filter('[data-testid="customer-returns-more"]'));
    }

    public function testBeyondOnePageTheReturnsSectionLinksToTheQueueOfTheCustomer(): void
    {
        ConfigQuery::write('order_return_enabled', '1');
        $customer = $this->customer();
        $order = $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_SENT]);
        for ($i = 0; $i <= CustomerReturnsController::PAGE_SIZE; ++$i) {
            $this->returnOf($order);
        }

        $crawler = $this->returns($customer, $this->factory->admin());

        self::assertCount(CustomerReturnsController::PAGE_SIZE, $crawler->filter('[data-testid="datatable-customer-returns-row"]'));
        $link = $crawler->filter('[data-testid="customer-returns-all-link"]');
        self::assertSame('/admin/returns?customer_id='.$customer->getId(), $link->attr('href'));
        self::assertStringContainsString((string) (CustomerReturnsController::PAGE_SIZE + 1), $link->text());
    }

    public function testTheReturnsSectionIsRefusedWithoutTheReturnsPermission(): void
    {
        ConfigQuery::write('order_return_enabled', '1');
        $customer = $this->customer();
        $admin = $this->factory->restrictedAdmin([AdminResources::CUSTOMER => [AccessManager::VIEW]]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $this->client->request('GET', '/admin/customer/returns?customer_id='.$customer->getId());

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTheReturnsSectionDoesNotExistWhileReturnsAreOffNorForAnUnknownCustomer(): void
    {
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        ConfigQuery::write('order_return_enabled', '0');
        $this->client->request('GET', '/admin/customer/returns?customer_id='.$this->customer()->getId());
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'Returns off.');

        ConfigQuery::write('order_return_enabled', '1');
        $this->client->request('GET', '/admin/customer/returns?customer_id=987654321');
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'Unknown customer.');
    }

    public function testWithoutModuleTheSheetShowsNoModuleSection(): void
    {
        $crawler = $this->sheet($this->customer(), $this->factory->admin());

        self::assertCount(0, $crawler->filter('[data-testid^="customer-module-section-"]'));
        self::assertCount(0, $crawler->filter('[data-testid="customer-modules-section"]'));
    }

    public function testASectionBroughtByAModuleIsShownAndAFailingOneIsLeftOut(): void
    {
        $customer = $this->customer();
        $this->listen('hook.'.TemplateDefinition::BACK_OFFICE.'.customer.tab', static function (): void {
            throw new \RuntimeException('A module section that breaks.');
        });
        $this->listen('hook.'.TemplateDefinition::BACK_OFFICE.'.customer.tab', static function (HookRenderBlockEvent $event): void {
            $event->add(['id' => 'notes', 'title' => 'Internal notes <b>', 'content' => '<p data-testid="module-notes-body">Called on Monday for customer '.$event->getArgument('customer_id').'</p>']);
        });
        $this->listen('hook.'.TemplateDefinition::BACK_OFFICE.'.customer.tab', static function (HookRenderBlockEvent $event): void {
            $event->add(['id' => 'tickets', 'title' => 'Support tickets', 'href' => '/admin/module/tickets?customer_id='.$event->getArgument('customer_id')]);
        });

        $crawler = $this->sheet($customer, $this->factory->admin());

        $notes = $crawler->filter('[data-testid="customer-module-section-notes"]');
        self::assertCount(1, $notes, 'The section of the module that works is shown.');
        self::assertSame('Called on Monday for customer '.$customer->getId(), trim($notes->filter('[data-testid="module-notes-body"]')->text()));
        self::assertStringContainsString('Internal notes <b>', $notes->filter('[data-testid="customer-module-section-notes-toggle"]')->text(), 'The title a module gives is escaped.');

        $tickets = $crawler->filter('[data-testid="customer-module-section-tickets"]');
        self::assertSame('/admin/module/tickets?customer_id='.$customer->getId(), $tickets->attr('data-bo-lazy-collapse-url-value'), 'A module section with an href loads when it opens.');
        self::assertCount(1, $crawler->filter('[data-testid="customer-orders-section"]'), 'The failing module did not take the sheet down.');
    }

    public function testAModuleAnsweringTheContentHookGetsItsCardInTheModulesSection(): void
    {
        $this->listen('hook.'.TemplateDefinition::BACK_OFFICE.'.customer.tab-content', static function (HookRenderEvent $event): void {
            $event->add('<p data-testid="module-card-body">Loyalty points: 120</p>');
        });

        $crawler = $this->sheet($this->customer(), $this->factory->admin());

        self::assertCount(1, $crawler->filter('[data-testid="customer-modules-section"] [data-testid="module-card-body"]'));
    }

    public function testAModuleCanPutAButtonNextToTheSheetNavigation(): void
    {
        $customer = $this->customer();

        $crawler = $this->sheet($customer, $this->factory->admin());
        self::assertCount(0, $crawler->filter('[data-testid="customer-edit-actions"] [data-testid="module-create-order"]'), 'No module, no button.');

        $this->listen('hook.'.TemplateDefinition::BACK_OFFICE.'.customer-edit.actions', static function (HookRenderEvent $event): void {
            $event->add('<a class="btn btn-sm btn-primary" data-testid="module-create-order" href="/admin/module/orders/new?customer_id='.(int) $event->getArgument('customer_id').'">Create an order</a>');
        });

        $crawler = $this->sheet($customer, $this->factory->admin());
        $button = $crawler->filter('[data-testid="customer-edit-actions"] [data-testid="module-create-order"]');
        self::assertCount(1, $button, 'The button a module adds sits in the actions of the sheet.');
        self::assertSame('/admin/module/orders/new?customer_id='.$customer->getId(), $button->attr('href'), 'The hook hands the module the customer id.');
    }

    public function testAPasswordResetLinkIsSentAndLeavesATraceInTheAdminLog(): void
    {
        $customer = $this->customer();
        $crawler = $this->sheet($customer, $this->factory->admin());
        $this->givenAStoreEmail();

        $this->client->request('POST', '/admin/customer/password-reset-link', [
            'customer_id' => $customer->getId(),
            '_token' => $this->resetToken($crawler),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        $messages = self::getMailerMessages();
        self::assertCount(1, $messages, 'One mail left.');
        self::assertStringContainsString((string) $customer->getEmail(), implode(',', array_map(static fn ($address): string => $address->getAddress(), $messages[0]->getTo())));
        self::assertStringContainsString('/password/reset/'.$customer->getId().'.', (string) $messages[0]->getHtmlBody(), 'The mail carries the core reset link.');

        $trace = AdminLogQuery::create()->filterByResource(AdminResources::CUSTOMER)->filterByResourceId($customer->getId())->filterByMessage('Password reset link sent to customer ID '.$customer->getId())->count();
        self::assertSame(1, $trace, 'The admin log keeps a trace of the link sent.');

        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('[data-testid="bo-flash-success"]'));
    }

    public function testAGuestCustomerHasNoResetButtonAndGetsNoLink(): void
    {
        $guest = $this->factory->guestCustomer($this->factory->customerTitle());
        $crawler = $this->sheet($guest, $this->factory->admin());

        self::assertCount(0, $crawler->filter('[data-testid="customer-password-reset-button"]'));

        // The session token, as the scripts of the page read it.
        $token = (string) $crawler->filter('meta[name="bo-token"]')->attr('content');
        self::assertNotSame('', $token);
        $this->givenAStoreEmail();
        $this->client->request('POST', '/admin/customer/password-reset-link', ['customer_id' => $guest->getId(), '_token' => $token]);

        self::assertCount(0, self::getMailerMessages());
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]'));
    }

    public function testAnAnonymizedCustomerHasNoResetButtonAndGetsNoLink(): void
    {
        $customer = $this->customer();
        $customer->setEmail(\sprintf('anonymous-%d@%s', $customer->getId(), CustomerAnonymizer::ANONYMIZED_EMAIL_DOMAIN))->save($this->getPropelConnection());
        $crawler = $this->sheet($customer, $this->factory->admin());

        self::assertCount(0, $crawler->filter('[data-testid="customer-password-reset-button"]'), 'Nobody reads the mailbox of an anonymized customer.');

        $token = (string) $crawler->filter('meta[name="bo-token"]')->attr('content');
        $this->givenAStoreEmail();
        $this->client->request('POST', '/admin/customer/password-reset-link', ['customer_id' => $customer->getId(), '_token' => $token]);

        self::assertCount(0, self::getMailerMessages());
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]'));
    }

    public function testAFourthLinkInTheSameHourIsRefused(): void
    {
        $customer = $this->customer();
        $token = $this->resetToken($this->sheet($customer, $this->factory->admin()));
        $this->givenAStoreEmail();

        $sent = 0;
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->client->request('POST', '/admin/customer/password-reset-link', ['customer_id' => $customer->getId(), '_token' => $token]);
            $sent += \count(self::getMailerMessages());
        }

        self::assertSame(3, $sent, 'Three links an hour, then the sheet refuses.');
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="bo-flash-danger"]'));
    }

    public function testAResetRequestWithoutTheSessionTokenIsRefused(): void
    {
        $customer = $this->customer();
        $this->sheet($customer, $this->factory->admin());

        $this->client->request('POST', '/admin/customer/password-reset-link', ['customer_id' => $customer->getId(), '_token' => 'forged']);

        self::assertCount(0, self::getMailerMessages());
        self::assertSame(0, AdminLogQuery::create()->filterByResourceId($customer->getId())->filterByMessage('Password reset link sent%', \Propel\Runtime\ActiveQuery\Criteria::LIKE)->count());
    }

    public function testAnAdministratorWhoMayNotEditTheCustomerCannotSendALink(): void
    {
        $customer = $this->customer();
        $admin = $this->factory->restrictedAdmin([AdminResources::CUSTOMER => [AccessManager::VIEW]]);
        $crawler = $this->sheet($customer, $admin);

        self::assertCount(0, $crawler->filter('[data-testid="customer-password-reset-button"]'));

        $this->client->request('POST', '/admin/customer/password-reset-link', ['customer_id' => $customer->getId(), '_token' => 'irrelevant']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, self::getMailerMessages());
    }

    /**
     * The test shop has no sender address, and the mail refuses to leave without one.
     * Written after the first request: a config write before it loses the session.
     */
    private function givenAStoreEmail(): void
    {
        $config = ConfigQuery::create()->findOneByName('store_email') ?? (new Config())->setName('store_email');
        $config->setValue('shop@example.com')->save($this->getPropelConnection());
        ConfigQuery::resetCache();
    }

    private function resetToken(Crawler $crawler): string
    {
        $token = $crawler->filter('[data-testid="customer-password-reset-form"] input[name="_token"]');
        self::assertCount(1, $token, 'The sheet offers to send a reset link.');

        return (string) $token->attr('value');
    }

    /**
     * Two hundred orders in every status: the sheet costs the same number of queries as
     * with a handful, and the carts are not read until their section opens. The budget
     * is the count measured on this sheet, so a query per order or per row breaks it.
     */
    public function testACustomerWithTwoHundredOrdersOpensWithinAFixedQueryBudget(): void
    {
        $customer = $this->customer();
        $codes = [OrderStatus::CODE_PAID, OrderStatus::CODE_SENT, OrderStatus::CODE_CANCELED, OrderStatus::CODE_NOT_PAID, OrderStatus::CODE_PROCESSING, OrderStatus::CODE_REFUNDED];
        for ($i = 0; $i < 200; ++$i) {
            $this->factory->order($customer, ['statusCode' => $codes[$i % \count($codes)], 'postage' => '10']);
        }
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
        $url = '/admin/customer/update?customer_id='.$customer->getId();

        $cold = QueryCounter::count(function () use ($url): void {
            $this->client->request('GET', $url);
        });
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $warm = QueryCounter::count(function () use ($url): void {
            $this->client->request('GET', $url);
        });
        self::assertLessThanOrEqual(self::COLD_QUERY_BUDGET, $cold);
        self::assertLessThanOrEqual(self::WARM_QUERY_BUDGET, $warm);
    }

    private function listen(string $eventName, callable $listener): void
    {
        $this->getService(EventDispatcherInterface::class)->addListener($eventName, $listener);
        $this->listeners[] = [$eventName, $listener];
    }

    private function customer(): Customer
    {
        $customer = $this->factory->customer($this->factory->customerTitle());

        // The quota of reset links lives in the cache pool, which outlives the test database:
        // a rebuilt database hands out the same ids again, and this one may find its quota spent.
        $limiter = $this->getService('limiter.admin_customer_password_reset');
        \assert($limiter instanceof RateLimiterFactoryInterface);
        $limiter->create('customer-'.$customer->getId())->reset();

        return $customer;
    }

    private function product(string $title): Product
    {
        return $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->factory->currency(),
            ['title' => $title],
        );
    }

    private function carts(Customer $customer, Admin $admin): Crawler
    {
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $crawler = $this->client->request('GET', '/admin/customer/carts?customer_id='.$customer->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler;
    }

    private function returns(Customer $customer, Admin $admin): Crawler
    {
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $crawler = $this->client->request('GET', '/admin/customer/returns?customer_id='.$customer->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler;
    }

    /**
     * A requested exchange of one line of the order, for a size too small.
     */
    private function returnOf(Order $order): OrderReturn
    {
        $connection = $this->getPropelConnection();

        $orderProduct = (new OrderProduct())
            ->setOrderId((int) $order->getId())
            ->setProductRef('REF-'.uniqid())
            ->setProductSaleElementsRef('PSE-RETURNED')
            ->setTitle('A returned product')
            ->setQuantity(1.0)
            ->setPrice('10.000000')
            ->setPromoPrice('10.000000')
            ->setWasNew(1)
            ->setWasInPromo(0)
            ->setVirtual(0);
        $orderProduct->save($connection);

        (new OrderProductAttributeCombination())
            ->setOrderProductId((int) $orderProduct->getId())
            ->setAttributeTitle('Size')
            ->setAttributeAvTitle('XL')
            ->save($connection);

        $return = (new OrderReturn())
            ->setOrder($order)
            ->setCustomerId((int) $order->getCustomerId())
            ->setOrderReturnStatus(OrderReturnStatusQuery::create()->findOneByCode(OrderReturnStatus::CODE_REQUESTED))
            ->setReasonTitle('Too small')
            ->setExpectedResolution(OrderReturn::RESOLUTION_EXCHANGE);
        $return->save($connection);

        (new OrderReturnLine())
            ->setOrderReturn($return)
            ->setOrderProduct($orderProduct)
            ->setQuantity(1.0)
            ->save($connection);

        return $return;
    }

    private function sheet(Customer $customer, Admin $admin): Crawler
    {
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $crawler = $this->client->request('GET', '/admin/customer/update?customer_id='.$customer->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler;
    }

    private function text(Crawler $crawler, string $testid): string
    {
        $node = $crawler->filter('[data-testid="'.$testid.'"]');
        self::assertCount(1, $node, $testid.' is on the page.');

        return trim(preg_replace('/\s+/u', ' ', $node->text()) ?? '');
    }
}
