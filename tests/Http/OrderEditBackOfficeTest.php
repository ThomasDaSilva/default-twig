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

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Order\Enum\OrderHistoryEventType;
use Thelia\Domain\Order\Service\OrderEditor;
use Thelia\Model\Admin;
use Thelia\Model\AdminLog;
use Thelia\Model\AdminLogQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Map\OrderProductTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderHistoryQuery;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

final class OrderEditBackOfficeTest extends WebIntegrationTestCase
{
    private const LOCALE = 'en_US';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        if (!class_exists(OrderEditor::class)) {
            self::markTestSkipped('The core installed does not carry the order editor yet.');
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

    public function testTheSheetOffersTheEditorToAnAdministratorEntitledToIt(): void
    {
        $this->loginFullAdmin();
        [$order, $first] = $this->editableOrder();

        $this->assertPageRenders($this->sheetUrl($order));
        $html = $this->html();

        self::assertStringContainsString('order-edit-btn', $html);
        self::assertStringContainsString('order-edit-form', $html);
        self::assertStringContainsString('order-edit-line-'.$first->getId(), $html);
        self::assertStringContainsString('name="expected_state"', $html);
    }

    public function testAnEditChangesTheOrderAndIsHistorisedAndAudited(): void
    {
        $admin = $this->loginFullAdmin();
        [$order, $first, $second] = $this->editableOrder();

        $this->client->request('POST', $this->editUrl($order), [
            ...$this->fieldsOf($this->sheetUrl($order)),
            'quantity' => [(string) $first->getId() => '2'],
            'price' => [(string) $first->getId() => '12,5'],
            'remove' => [(string) $second->getId() => '1'],
            'discount' => '3',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $lines = $this->linesOf($order);
        self::assertSame([(int) $first->getId()], array_keys($lines), 'The removed line is gone.');
        self::assertEqualsWithDelta(2.0, (float) $lines[(int) $first->getId()]->getQuantity(), 0.0001);
        self::assertEqualsWithDelta(12.5, (float) $lines[(int) $first->getId()]->getPrice(), 0.0001, 'A French decimal comma is read.');
        self::assertEqualsWithDelta(3.0, (float) $this->reloaded($order)->getDiscount(), 0.0001);

        self::assertSame(
            1,
            OrderHistoryQuery::create()->filterByOrderId($order->getId())->filterByEventType(OrderHistoryEventType::ORDER_EDITED->value)->count(),
        );

        $log = $this->latestAdminLog();
        self::assertNotNull($log);
        self::assertSame(AdminResources::ORDER_EDIT, $log->getResource());
        self::assertSame(AccessManager::UPDATE, $log->getAction());
        self::assertSame((int) $order->getId(), $log->getResourceId());
        self::assertSame((string) $admin->getLogin(), (string) $log->getAdminLogin());

        $this->client->followRedirect();
        self::assertStringContainsString('The order has been edited.', $this->html());
    }

    public function testNothingIsSentToTheCoreForUntouchedFields(): void
    {
        $this->loginFullAdmin();
        [$order, $first] = $this->editableOrder();

        $this->client->request('POST', $this->editUrl($order), [
            ...$this->fieldsOf($this->sheetUrl($order)),
            'quantity' => [(string) $first->getId() => '3'],
            'price' => [(string) $first->getId() => '10'],
            'discount' => '0',
        ]);

        $this->client->followRedirect();
        self::assertStringContainsString('Nothing was changed.', $this->html());
        self::assertSame(0, OrderHistoryQuery::create()->filterByOrderId($order->getId())->filterByEventType(OrderHistoryEventType::ORDER_EDITED->value)->count());
    }

    public function testBundleAndServiceLinesAreShownButNotOfferedForEditing(): void
    {
        $this->loginFullAdmin();
        [$order] = $this->editableOrder();
        $bundle = $this->factory->orderProduct($order, ['lineType' => OrderProduct::LINE_TYPE_BUNDLE]);
        $component = $this->factory->orderProduct($order, ['lineType' => OrderProduct::LINE_TYPE_BUNDLE_COMPONENT]);
        $service = $this->factory->orderProduct($order, ['lineType' => OrderProduct::LINE_TYPE_SERVICE]);

        $this->assertPageRenders($this->sheetUrl($order));
        $html = $this->html();

        foreach ([$bundle, $component, $service] as $line) {
            self::assertStringContainsString('order-edit-line-'.$line->getId(), $html);
            self::assertStringContainsString('order-edit-locked-'.$line->getId(), $html);
            self::assertStringNotContainsString('order-edit-quantity-'.$line->getId(), $html);
            self::assertStringNotContainsString('order-edit-remove-'.$line->getId(), $html);
        }
    }

    public function testAThousandsSeparatorIsRefusedInsteadOfBeingReadAsADecimal(): void
    {
        $this->loginFullAdmin();
        [$order, $first] = $this->editableOrder();

        $this->client->request('POST', $this->editUrl($order), [
            ...$this->fieldsOf($this->sheetUrl($order)),
            'price' => [(string) $first->getId() => '1,000'],
        ]);
        $this->client->followRedirect();

        self::assertStringContainsString('A price must be a positive amount', $this->html());
        self::assertEqualsWithDelta(10.0, (float) $this->linesOf($order)[(int) $first->getId()]->getPrice(), 0.0001);
    }

    public function testOnlyTheCheckedBoxRemovesALine(): void
    {
        $this->loginFullAdmin();
        [$order, $first, $second] = $this->editableOrder();

        $this->client->request('POST', $this->editUrl($order), [
            ...$this->fieldsOf($this->sheetUrl($order)),
            'remove' => [(string) $second->getId() => '0'],
        ]);
        $this->client->followRedirect();

        self::assertStringContainsString('Nothing was changed.', $this->html());
        self::assertCount(2, $this->linesOf($order));
    }

    public function testAPaidOrderCannotLoseALineEvenByAForgedPost(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $first = $this->catalogLine($order, 1.0, '10.000000');
        $second = $this->catalogLine($order, 1.0, '20.000000');

        $this->assertPageRenders($this->sheetUrl($order));
        self::assertStringContainsString('order-edit-paid-note', $this->html());
        self::assertStringNotContainsString('order-edit-remove-'.$first->getId(), $this->html());

        $this->client->request('POST', $this->editUrl($order), [
            ...$this->fieldsOf($this->sheetUrl($order)),
            'remove' => [(string) $second->getId() => '1'],
        ]);
        $this->client->followRedirect();

        self::assertStringContainsString('A line cannot be removed from a paid order.', $this->html());
        self::assertCount(2, $this->linesOf($order));
    }

    public function testThePostageIsSentWhenItChanges(): void
    {
        $this->loginFullAdmin();
        [$order] = $this->editableOrder();

        $this->client->request('POST', $this->editUrl($order), [
            ...$this->fieldsOf($this->sheetUrl($order)),
            'postage' => '5,5',
        ]);
        $this->client->followRedirect();

        self::assertStringContainsString('The order has been edited.', $this->html());
        self::assertEqualsWithDelta(5.5, (float) $this->reloaded($order)->getUntaxedPostage(), 0.011);
    }

    public function testAnAdministratorWithoutTheEditPermissionIsRefusedAndNotOffered(): void
    {
        $admin = $this->factory->restrictedAdmin([
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
        ]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
        [$order, $first] = $this->editableOrder();

        $this->assertPageRenders($this->sheetUrl($order));
        self::assertStringNotContainsString('order-edit-btn', $this->html());

        $this->client->request('POST', $this->editUrl($order), [
            '_token' => $this->tokenOf($this->sheetUrl($order)),
            'price' => [(string) $first->getId() => '99'],
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertEqualsWithDelta(10.0, (float) $this->linesOf($order)[(int) $first->getId()]->getPrice(), 0.0001);
    }

    public function testTheTokenIsReadFromTheBodyOnly(): void
    {
        $this->loginFullAdmin();
        [$order, $first] = $this->editableOrder();
        $token = $this->tokenOf($this->sheetUrl($order));

        $this->client->request('POST', $this->editUrl($order).'?_token='.rawurlencode($token), [
            'price' => [(string) $first->getId() => '99'],
        ]);
        $this->client->followRedirect();

        self::assertStringContainsString('Invalid security token', $this->html());
        self::assertEqualsWithDelta(10.0, (float) $this->linesOf($order)[(int) $first->getId()]->getPrice(), 0.0001);

        $this->client->request('POST', $this->editUrl($order), [
            'price' => [(string) $first->getId() => '99'],
        ]);
        $this->client->followRedirect();

        self::assertStringContainsString('Invalid security token', $this->html());
        self::assertEqualsWithDelta(10.0, (float) $this->linesOf($order)[(int) $first->getId()]->getPrice(), 0.0001);
    }

    public function testAnOrderChangedSinceTheEditorWasOpenedRefusesTheEdit(): void
    {
        $this->loginFullAdmin();
        [$order, $first] = $this->editableOrder();
        $fields = $this->fieldsOf($this->sheetUrl($order));

        $first->setPrice('11.000000')->setPromoPrice('11.000000')->save($this->getPropelConnection());

        $this->client->request('POST', $this->editUrl($order), [
            ...$fields,
            'price' => [(string) $first->getId() => '99'],
        ]);
        $this->client->followRedirect();

        self::assertStringContainsString('The order changed since you opened it.', $this->html());
        OrderProductTableMap::clearInstancePool();
        self::assertEqualsWithDelta(11.0, (float) $this->linesOf($order)[(int) $first->getId()]->getPrice(), 0.0001);
    }

    public function testAnOrderWhoseStatusForbidsEditingIsNotOfferedAndRefused(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_SENT]);
        $line = $this->factory->orderProduct($order, ['quantity' => 1, 'price' => '10.000000', 'promoPrice' => '10.000000']);
        $this->factory->orderProduct($order);

        $this->assertPageRenders($this->sheetUrl($order));
        self::assertStringNotContainsString('order-edit-btn', $this->html());

        $this->client->request('POST', $this->editUrl($order), [
            '_token' => $this->tokenOf($this->sheetUrl($order)),
            'price' => [(string) $line->getId() => '99'],
        ]);
        $this->client->followRedirect();

        self::assertStringContainsString('The status of this order does not allow editing it.', $this->html());
        self::assertEqualsWithDelta(10.0, (float) $this->linesOf($order)[(int) $line->getId()]->getPrice(), 0.0001);
    }

    private function loginFullAdmin(): Admin
    {
        $admin = $this->factory->admin(['locale' => self::LOCALE]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $limiter = $this->getService('limiter.order_edit');
        \assert($limiter instanceof RateLimiterFactoryInterface);
        $limiter->create($admin->getUsername())->reset();

        return $admin;
    }

    /**
     * @return array{0: Order, 1: OrderProduct, 2: OrderProduct}
     */
    private function editableOrder(): array
    {
        $order = $this->factory->order();
        $first = $this->catalogLine($order, 3.0, '10.000000');
        $second = $this->catalogLine($order, 1.0, '20.000000');

        return [$order, $first, $second];
    }

    private function catalogLine(Order $order, float $quantity, string $price): OrderProduct
    {
        $product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(['isDefault' => false]),
            $this->factory->currency(),
            ['title' => 'Widget', 'baseQuantity' => 10],
        );
        $pse = $product->getDefaultSaleElements();

        return $this->factory->orderProduct($order, [
            'productRef' => $product->getRef(),
            'productSaleElementsRef' => $pse->getRef(),
            'productSaleElementsId' => $pse->getId(),
            'quantity' => $quantity,
            'price' => $price,
            'promoPrice' => $price,
        ]);
    }

    /**
     * @return array<int, OrderProduct>
     */
    private function linesOf(Order $order): array
    {
        $lines = [];
        foreach (OrderProductQuery::create()->filterByOrderId($order->getId())->orderById()->find($this->getPropelConnection()) as $line) {
            $lines[(int) $line->getId()] = $line;
        }

        return $lines;
    }

    private function reloaded(Order $order): Order
    {
        return OrderQuery::create()->findPk($order->getId(), $this->getPropelConnection());
    }

    private function latestAdminLog(): ?AdminLog
    {
        return AdminLogQuery::create()->orderById(Criteria::DESC)->findOne($this->getPropelConnection());
    }

    private function sheetUrl(Order $order): string
    {
        return '/admin/order/update/'.$order->getId();
    }

    private function editUrl(Order $order): string
    {
        return '/admin/order/'.$order->getId().'/edit';
    }

    private function tokenOf(string $url): string
    {
        $crawler = $this->client->request('GET', $url);
        $token = $crawler->filter('input[name="_token"]')->first();

        self::assertGreaterThan(0, $token->count(), \sprintf('"%s" renders no CSRF token.', $url));

        return (string) $token->attr('value');
    }

    /**
     * @return array{_token: string, expected_state: string}
     */
    private function fieldsOf(string $url): array
    {
        $crawler = $this->client->request('GET', $url);
        $token = $crawler->filter('form[data-testid="order-edit-form"] input[name="_token"]')->first();
        $state = $crawler->filter('input[name="expected_state"]')->first();

        self::assertGreaterThan(0, $token->count(), \sprintf('"%s" renders no edit form.', $url));

        return ['_token' => (string) $token->attr('value'), 'expected_state' => (string) $state->attr('value')];
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
