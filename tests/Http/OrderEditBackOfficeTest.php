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
        self::assertEqualsWithDelta(12.5, (float) $lines[(int) $first->getId()]->getPromoPrice(), 0.0001, 'A French decimal comma is read.');
        self::assertSame(1, (int) $lines[(int) $first->getId()]->getWasInPromo(), 'The corrected price is carried as a promotional price.');
        self::assertEqualsWithDelta(10.0, (float) $lines[(int) $first->getId()]->getPrice(), 0.0001, 'The catalogue price is kept.');
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
        self::assertEqualsWithDelta(10.0, (float) $this->linesOf($order)[(int) $first->getId()]->getPromoPrice(), 0.0001);
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
        self::assertEqualsWithDelta(10.0, (float) $this->linesOf($order)[(int) $first->getId()]->getPromoPrice(), 0.0001);
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
        self::assertEqualsWithDelta(10.0, (float) $this->linesOf($order)[(int) $first->getId()]->getPromoPrice(), 0.0001);

        $this->client->request('POST', $this->editUrl($order), [
            'price' => [(string) $first->getId() => '99'],
        ]);
        $this->client->followRedirect();

        self::assertStringContainsString('Invalid security token', $this->html());
        self::assertEqualsWithDelta(10.0, (float) $this->linesOf($order)[(int) $first->getId()]->getPromoPrice(), 0.0001);
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
        self::assertEqualsWithDelta(11.0, (float) $this->linesOf($order)[(int) $first->getId()]->getPromoPrice(), 0.0001);
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
        self::assertEqualsWithDelta(10.0, (float) $this->linesOf($order)[(int) $line->getId()]->getPromoPrice(), 0.0001);
    }

    public function testThePreviewAnnouncesTheTotalsOfTheEditAndWritesNothing(): void
    {
        $this->loginFullAdmin();
        [$order, $first] = $this->editableOrder();
        $fields = $this->fieldsOf($this->sheetUrl($order));
        $stateBefore = $this->stateOf($order);

        $this->client->request('POST', $this->previewUrl($order), [
            '_token' => $fields['_token'],
            'expected_state' => $fields['expected_state'],
            'price' => [(string) $first->getId() => '20'],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $keys = ['goods', 'discount', 'postage', 'tax', 'total'];
        self::assertSame($keys, array_keys($answer['before']));
        self::assertSame($keys, array_keys($answer['after']));
        self::assertEqualsWithDelta(50.0, (float) $answer['before']['goods'], 0.011, '3 x 10 + 20, before the edit.');
        self::assertEqualsWithDelta(80.0, (float) $answer['after']['goods'], 0.011, '3 x 20 + 20, after the edit.');
        self::assertEqualsWithDelta((float) $answer['before']['postage'], (float) $answer['after']['postage'], 0.0001, 'A price edit leaves the postage alone.');
        self::assertEqualsWithDelta((float) $answer['after']['goods'] - (float) $answer['after']['discount'] + (float) $answer['after']['postage'], (float) $answer['after']['total'], 0.011);

        OrderProductTableMap::clearInstancePool();
        self::assertEqualsWithDelta(10.0, (float) $this->linesOf($order)[(int) $first->getId()]->getPromoPrice(), 0.0001);
        self::assertSame($stateBefore, $this->stateOf($order), 'The preview wrote nothing.');
        self::assertSame(0, OrderHistoryQuery::create()->filterByOrderId($order->getId())->filterByEventType(OrderHistoryEventType::ORDER_EDITED->value)->count());
    }

    public function testThePreviewExplainsARefusalInJson(): void
    {
        $this->loginFullAdmin();
        [$order] = $this->editableOrder();
        $fields = $this->fieldsOf($this->sheetUrl($order));

        $this->client->request('POST', $this->previewUrl($order), [
            '_token' => $fields['_token'],
            'expected_state' => $fields['expected_state'],
            'discount' => '99999',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            'The discount must be a positive amount that the order covers.',
            json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null,
        );
    }

    public function testThePreviewNeedsTheTokenInTheBodyAndTheEditPermission(): void
    {
        $this->loginFullAdmin();
        [$order, $first] = $this->editableOrder();
        $token = $this->tokenOf($this->sheetUrl($order));

        $this->client->request('POST', $this->previewUrl($order).'?_token='.rawurlencode($token), ['price' => [(string) $first->getId() => '20']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'A token in the URL is not read.');

        $restricted = $this->factory->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE]]);
        $restricted->eraseCredentials();
        $this->injector->setAdmin($restricted);

        $this->client->request('POST', $this->previewUrl($order), ['_token' => $token, 'price' => [(string) $first->getId() => '20']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'The preview is no open tax calculator.');
    }

    public function testALineOfAnotherOrderRefusesTheWholeEditEvenWithALegitimateChange(): void
    {
        $this->loginFullAdmin();
        [$order] = $this->editableOrder();
        [, $foreign] = $this->editableOrder();

        $this->client->request('POST', $this->editUrl($order), [
            ...$this->fieldsOf($this->sheetUrl($order)),
            'price' => [(string) $foreign->getId() => '99'],
            'discount' => '3',
        ]);
        $this->client->followRedirect();

        OrderProductTableMap::clearInstancePool();
        self::assertStringContainsString('One of the lines does not belong to this order.', $this->html());
        self::assertStringNotContainsString('The order has been edited.', $this->html());
        self::assertEqualsWithDelta(10.0, (float) OrderProductQuery::create()->findPk($foreign->getId())->getPromoPrice(), 0.0001);
        self::assertEqualsWithDelta(0.0, (float) $this->reloaded($order)->getDiscount(), 0.0001, 'The legitimate change of the same request is not applied either.');
    }

    public function testTheEditStopsWhenTheAdministratorHasEditedTooOften(): void
    {
        $admin = $this->loginFullAdmin();
        [$order, $first] = $this->editableOrder();
        $limiter = $this->getService('limiter.order_edit');
        \assert($limiter instanceof RateLimiterFactoryInterface);
        $bucket = $limiter->create($admin->getUsername());
        $bucket->consume(30);

        $this->client->request('POST', $this->editUrl($order), [
            ...$this->fieldsOf($this->sheetUrl($order)),
            'price' => [(string) $first->getId() => '99'],
        ]);
        $this->client->followRedirect();

        self::assertStringContainsString('Too many order edits in a row.', $this->html());
        OrderProductTableMap::clearInstancePool();
        self::assertEqualsWithDelta(10.0, (float) $this->linesOf($order)[(int) $first->getId()]->getPromoPrice(), 0.0001);
    }

    public function testAnEditWithoutTheStateTokenIsRefused(): void
    {
        $this->loginFullAdmin();
        [$order, $first] = $this->editableOrder();
        $fields = $this->fieldsOf($this->sheetUrl($order));

        $this->client->request('POST', $this->editUrl($order), [
            '_token' => $fields['_token'],
            'price' => [(string) $first->getId() => '99'],
        ]);
        $this->client->followRedirect();

        self::assertStringContainsString('The order changed since you opened it.', $this->html());
        OrderProductTableMap::clearInstancePool();
        self::assertEqualsWithDelta(10.0, (float) $this->linesOf($order)[(int) $first->getId()]->getPromoPrice(), 0.0001);
    }

    public function testTheSearchOffersTheCombinationsOfVisibleProductsOnly(): void
    {
        $this->loginFullAdmin();
        [$order] = $this->editableOrder();
        $visible = $this->searchableProduct('Zorblax visible', 1);
        $this->searchableProduct('Zorblax hidden', 0);

        $this->client->request('GET', $this->searchUrl($order), ['q' => 'Zorblax']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $rows = json_decode($this->html(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $rows, 'The hidden product is not offered: the core would refuse it.');
        self::assertSame((int) $visible->getDefaultSaleElements()->getId(), $rows[0]['pse_id']);
        self::assertSame('Zorblax visible', $rows[0]['title']);
        self::assertSame('10', $rows[0]['stock']);

        $this->client->request('GET', $this->searchUrl($order));
        self::assertSame([], json_decode($this->html(), true, 512, \JSON_THROW_ON_ERROR), 'No term searches nothing.');
    }

    public function testTheSearchIsRefusedWithoutTheEditPermission(): void
    {
        $admin = $this->factory->restrictedAdmin([
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
        ]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
        [$order] = $this->editableOrder();
        $this->searchableProduct('Zorblax visible', 1);

        $this->client->request('GET', $this->searchUrl($order), ['q' => 'Zorblax']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Zorblax', $this->html());
    }

    public function testAnAddedLineIsPreviewedThenValidated(): void
    {
        $this->loginFullAdmin();
        [$order, $first] = $this->editableOrder();
        $product = $this->searchableProduct('Zorblax visible', 1);
        $fields = $this->fieldsOf($this->sheetUrl($order));
        $added = ['0' => ['pse' => (string) $product->getDefaultSaleElements()->getId(), 'quantity' => '2', 'price' => '5']];

        $this->client->request('POST', $this->previewUrl($order), [
            '_token' => $fields['_token'],
            'expected_state' => $fields['expected_state'],
            'add' => $added,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $answer = json_decode($this->html(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertEqualsWithDelta(60.0, (float) $answer['after']['goods'], 0.011, '3 x 10 + 20 + 2 x 5.');
        self::assertCount(2, $this->linesOf($order), 'The preview wrote nothing.');

        $this->client->request('POST', $this->editUrl($order), [...$fields, 'add' => $added]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $lines = $this->linesOf($order);
        self::assertCount(3, $lines);
        self::assertContains(2.0, array_map(static fn (OrderProduct $line): float => (float) $line->getQuantity(), $lines));
        self::assertEqualsWithDelta(60.0, (float) $this->stateTotals($order)['goods'], 0.011);
    }

    public function testACombinationThatDoesNotExistIsRefusedWithItsReason(): void
    {
        $this->loginFullAdmin();
        [$order, $first] = $this->editableOrder();
        $fields = $this->fieldsOf($this->sheetUrl($order));

        $this->client->request('POST', $this->editUrl($order), [
            ...$fields,
            'price' => [(string) $first->getId() => '99'],
            'add' => ['0' => ['pse' => '999999999', 'quantity' => '1', 'price' => '']],
        ]);
        $this->client->followRedirect();

        self::assertStringContainsString('The product of a line no longer exists in the catalogue.', $this->html());
        OrderProductTableMap::clearInstancePool();
        self::assertCount(2, $this->linesOf($order));
    }

    public function testTheSheetSaysWhyTheEditorIsAbsentToAnAdministratorEntitledToIt(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_SENT]);
        $this->factory->orderProduct($order);

        $this->assertPageRenders($this->sheetUrl($order));
        $html = $this->html();

        self::assertStringContainsString('order-edit-unavailable', $html);
        self::assertStringContainsString('its status does not allow it', $html);
        self::assertStringNotContainsString('order-edit-btn', $html);

        [$editable] = $this->editableOrder();
        $this->assertPageRenders($this->sheetUrl($editable));
        self::assertStringNotContainsString('order-edit-unavailable', $this->html());
    }

    public function testTheSheetSaysNothingToAnAdministratorWhoCannotEdit(): void
    {
        $admin = $this->factory->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE]]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_SENT]);
        $this->factory->orderProduct($order);

        $this->assertPageRenders($this->sheetUrl($order));

        self::assertStringNotContainsString('order-edit-unavailable', $this->html());
    }

    public function testThePreviewNamesTheRefusalOfAnEditThatChangesNothing(): void
    {
        $this->loginFullAdmin();
        [$order] = $this->editableOrder();
        $fields = $this->fieldsOf($this->sheetUrl($order));

        $this->client->request('POST', $this->previewUrl($order), ['_token' => $fields['_token'], 'expected_state' => $fields['expected_state']]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('nothing_to_edit', json_decode($this->html(), true, 512, \JSON_THROW_ON_ERROR)['refusal']);
    }

    public function testMoreThanFiftyAddedLinesAreRefusedBeforeTheCoreIsAsked(): void
    {
        $this->loginFullAdmin();
        [$order] = $this->editableOrder();
        $fields = $this->fieldsOf($this->sheetUrl($order));
        $added = array_fill(0, 51, ['pse' => '1', 'quantity' => '1', 'price' => '']);

        $this->client->request('POST', $this->previewUrl($order), ['_token' => $fields['_token'], 'expected_state' => $fields['expected_state'], 'add' => $added]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('At most 50 products', $this->html());

        $this->client->request('POST', $this->editUrl($order), [...$fields, 'add' => $added]);
        $this->client->followRedirect();
        self::assertStringContainsString('At most 50 products', $this->html());
        self::assertCount(2, $this->linesOf($order));
    }

    public function testTheSearchLeavesOutBundlesAndReadsTheTermLiterally(): void
    {
        $this->loginFullAdmin();
        [$order] = $this->editableOrder();
        $bundle = $this->searchableProduct('Quuxel bundle', 1);
        $bundle->setIsBundle(1)->save();
        $this->searchableProduct('Quuxel_a', 1);
        $this->searchableProduct('QuuxelXa', 1);

        $this->client->request('GET', $this->searchUrl($order), ['q' => 'Quuxel']);
        $titles = array_column(json_decode($this->html(), true, 512, \JSON_THROW_ON_ERROR), 'title');
        self::assertNotContains('Quuxel bundle', $titles, 'The core refuses a bundle as an added line.');

        $this->client->request('GET', $this->searchUrl($order), ['q' => 'Quuxel_a']);
        self::assertSame(['Quuxel_a'], array_column(json_decode($this->html(), true, 512, \JSON_THROW_ON_ERROR), 'title'), 'An underscore is not a wildcard.');

        $this->client->request('GET', $this->searchUrl($order), ['q' => 'Q']);
        self::assertSame([], json_decode($this->html(), true, 512, \JSON_THROW_ON_ERROR), 'One character searches nothing, whatever the client does.');
    }

    public function testABlankDiscountIsReadAsZeroInsteadOfRefusingTheEdit(): void
    {
        $this->loginFullAdmin();
        [$order, $first] = $this->editableOrder();

        $this->client->request('POST', $this->editUrl($order), [
            ...$this->fieldsOf($this->sheetUrl($order)),
            'discount' => '',
            'postage' => ' ',
            'price' => [(string) $first->getId() => '12'],
        ]);
        $this->client->followRedirect();

        self::assertStringContainsString('The order has been edited.', $this->html());
        self::assertEqualsWithDelta(0.0, (float) $this->reloaded($order)->getDiscount(), 0.0001);
    }

    private function searchUrl(Order $order): string
    {
        return '/admin/order/'.$order->getId().'/edit/search';
    }

    private function searchableProduct(string $title, int $visible): \Thelia\Model\Product
    {
        return $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(['isDefault' => false]),
            $this->factory->currency(),
            ['title' => $title, 'visible' => $visible, 'baseQuantity' => 10],
        );
    }

    /**
     * @return array<string, string>
     */
    private function stateTotals(Order $order): array
    {
        return get_object_vars($this->getService(OrderEditor::class)->simulate(
            OrderQuery::create()->findPk($order->getId()),
            new \Thelia\Domain\Order\DTO\OrderEdit(discount: (string) OrderQuery::create()->findPk($order->getId())->getDiscount()),
        )->before);
    }

    private function previewUrl(Order $order): string
    {
        return '/admin/order/'.$order->getId().'/edit/preview';
    }

    private function stateOf(Order $order): string
    {
        return $this->getService(OrderEditor::class)->stateOf(OrderQuery::create()->findPk($order->getId()));
    }

    private function loginFullAdmin(): Admin
    {
        $admin = $this->factory->admin(['locale' => self::LOCALE]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        foreach (['limiter.order_edit', 'limiter.order_edit_preview'] as $id) {
            $limiter = $this->getService($id);
            \assert($limiter instanceof RateLimiterFactoryInterface);
            $limiter->create($admin->getUsername())->reset();
        }

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
