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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminFailureMessage;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusActionQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The stock rules on the actions tab of an order status: the simulation of a status change,
 * which reads an order by its reference and so needs the orders permission on top of the
 * order status one, and the module lists of a stock action.
 */
final class OrderStatusStockRulesTest extends WebIntegrationTestCase
{
    private const SIMULATOR = 'Thelia\\Domain\\Order\\StatusAction\\StockOperationSimulator';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        if (!class_exists(self::SIMULATOR)) {
            self::markTestSkipped('The core does not simulate the stock operation of a status change.');
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

        parent::tearDown();
    }

    public function testTheActionsTabOffersTheSimulationToAnAdministratorWithBothPermissions(): void
    {
        $this->actAs([AdminResources::ORDER_STATUS => [AccessManager::VIEW], AdminResources::ORDER => [AccessManager::VIEW]]);

        $html = $this->actionsTab($this->orderStatus(OrderStatus::CODE_PAID));

        self::assertStringContainsString('data-testid="order-status-stock-simulation"', $html);
        self::assertStringContainsString('data-testid="order-status-stock-simulation-ref"', $html);
    }

    public function testAnUnknownReferenceGivesTheFormErrorAndNotAServerError(): void
    {
        $this->actAs([AdminResources::ORDER_STATUS => [AccessManager::VIEW], AdminResources::ORDER => [AccessManager::VIEW]]);

        $html = $this->actionsTab($this->orderStatus(OrderStatus::CODE_PAID), 'NO-SUCH-ORDER');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('data-testid="order-status-stock-simulation-error"', $html);
        self::assertStringContainsString('No order has this reference.', $html);
        self::assertStringNotContainsString('data-testid="order-status-stock-simulation-result"', $html);
        self::assertStringNotContainsString(AdminFailureMessage::SERVER_ERROR, $html);
    }

    public function testAKnownOrderGivesTheOperationOfTheStatusChange(): void
    {
        $this->actAs([AdminResources::ORDER_STATUS => [AccessManager::VIEW], AdminResources::ORDER => [AccessManager::VIEW]]);
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $canceled = $this->orderStatus(OrderStatus::CODE_CANCELED);

        $html = $this->actionsTab($canceled, (string) $order->getRef(), (int) $canceled->getId());

        self::assertResponseStatusCodeSame(200);
        self::assertMatchesRegularExpression(
            '/order-status-stock-simulation-result.*?'.preg_quote((string) $order->getRef(), '/').'.*?<strong>(Stock put back|Stock taken out|Stock left as it is)<\/strong>/s',
            $html,
        );
        self::assertStringNotContainsString('data-testid="order-status-stock-simulation-error"', $html);
    }

    public function testWithoutTheOrdersPermissionTheCardIsNotBuiltAndAForgedReferenceDoesNothing(): void
    {
        $this->actAs([AdminResources::ORDER_STATUS => [AccessManager::VIEW]]);
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $canceled = $this->orderStatus(OrderStatus::CODE_CANCELED);

        $html = $this->actionsTab($canceled, (string) $order->getRef(), (int) $canceled->getId());

        self::assertResponseStatusCodeSame(200);
        self::assertStringNotContainsString('order-status-stock-simulation', $html);
        // The reference only comes back inside the links of the page, which repeat the query string the administrator sent.
        self::assertStringNotContainsString('order-status-stock-simulation-result', $html);
        self::assertStringNotContainsString('Stock put back', $html);
        self::assertStringNotContainsString('Stock taken out', $html);
        self::assertStringNotContainsString('Stock left as it is', $html);
    }

    public function testTheModulesOfAStockActionAreTickedInAListAndNotPickedWithCtrlClick(): void
    {
        $this->actAs([AdminResources::ORDER_STATUS => [AccessManager::VIEW, AccessManager::UPDATE]]);

        $crawler = $this->client->request('GET', $this->actionsUrl($this->orderStatus(OrderStatus::CODE_PAID)));
        $modal = $crawler->filter('[data-testid="order-status-action-create-modal"]');

        self::assertGreaterThan(0, $modal->filter('input[type="checkbox"][name="payload[adjust_stock][payment_modules][]"]')->count());
        self::assertGreaterThan(0, $modal->filter('input[type="checkbox"][name="payload[adjust_stock][delivery_modules][]"]')->count());
        self::assertSame(0, $modal->filter('select[multiple]')->count());
    }

    public function testAStockActionWhoseLimitNamesAGoneModuleIsSaidToNeverApply(): void
    {
        $this->actAs([AdminResources::ORDER_STATUS => [AccessManager::VIEW, AccessManager::UPDATE]]);
        $status = $this->orderStatus(OrderStatus::CODE_PAID);

        $this->client->request('POST', '/admin/configuration/order-status/actions/'.$status->getId().'/create', [
            '_token' => $this->tokenOf($this->actionsUrl($status)),
            'trigger' => 'enter',
            'action_type' => 'adjust_stock',
            'payload' => ['adjust_stock' => ['operation' => 'none', 'payment_modules' => ['Cheque', 'NoSuchModule']]],
        ]);

        self::assertStringContainsString('Never applies while its limits name a module that is gone or switched off: NoSuchModule', $this->actionsTab($status));
    }

    public function testAStockActionKeepsTheModulesPickedAndLeavesTheFieldOutWhenNothingIsPicked(): void
    {
        $this->actAs([AdminResources::ORDER_STATUS => [AccessManager::VIEW, AccessManager::UPDATE]]);
        $status = $this->orderStatus(OrderStatus::CODE_PAID);
        $token = $this->tokenOf($this->actionsUrl($status));
        $before = OrderStatusActionQuery::create()->count();

        $this->client->request('POST', '/admin/configuration/order-status/actions/'.$status->getId().'/create', [
            '_token' => $token,
            'trigger' => 'enter',
            'action_type' => 'adjust_stock',
            'payload' => ['adjust_stock' => ['operation' => 'increase', 'payment_modules' => ['AnyPaymentModule', '']]],
        ]);
        $picked = OrderStatusActionQuery::create()->orderById('desc')->findOne();

        $this->client->request('POST', '/admin/configuration/order-status/actions/'.$status->getId().'/create', [
            '_token' => $token,
            'trigger' => 'enter',
            'action_type' => 'adjust_stock',
            'payload' => ['adjust_stock' => ['operation' => 'decrease', 'payment_modules' => ['']]],
        ]);
        $empty = OrderStatusActionQuery::create()->orderById('desc')->findOne();

        self::assertSame($before + 2, OrderStatusActionQuery::create()->count());
        self::assertSame(['operation' => 'increase', 'payment_modules' => ['AnyPaymentModule']], $picked?->getDecodedPayload());
        self::assertSame(['operation' => 'decrease'], $empty?->getDecodedPayload());
    }

    /**
     * @param array<string, list<string>> $grants
     */
    private function actAs(array $grants): void
    {
        $admin = $this->factory->restrictedAdmin($grants);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    private function orderStatus(string $code): OrderStatus
    {
        $status = OrderStatusQuery::create()->findOneByCode($code);
        self::assertNotNull($status);

        return $status;
    }

    private function actionsUrl(OrderStatus $status, ?string $reference = null, ?int $targetStatusId = null): string
    {
        $url = '/admin/configuration/order-status/update/'.$status->getId().'?tab=actions';

        if (null !== $reference) {
            $url .= '&simulate_ref='.rawurlencode($reference);
        }

        if (null !== $targetStatusId) {
            $url .= '&simulate_status='.$targetStatusId;
        }

        return $url;
    }

    private function actionsTab(OrderStatus $status, ?string $reference = null, ?int $targetStatusId = null): string
    {
        $this->assertPageRenders($this->actionsUrl($status, $reference, $targetStatusId));

        return (string) $this->client->getResponse()->getContent();
    }

    private function tokenOf(string $url): string
    {
        $crawler = $this->client->request('GET', $url);
        $token = $crawler->filter('input[name="_token"]')->first();

        self::assertGreaterThan(0, $token->count(), \sprintf('"%s" renders no CSRF token.', $url));

        return (string) $token->attr('value');
    }
}
