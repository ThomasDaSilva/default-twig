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
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Order\Enum\OrderHistoryActorType;
use Thelia\Domain\Order\Enum\OrderHistoryEventType;
use Thelia\Model\Admin;
use Thelia\Model\AdminLog;
use Thelia\Model\AdminLogQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Map\OrderHistoryTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderHistory;
use Thelia\Model\OrderHistoryQuery;
use Thelia\Model\OrderReturnStatus;
use Thelia\Model\OrderReturnStatusQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The history block of the order sheet, over HTTP: the timeline it renders, the note
 * an administrator writes on it, and the correction only its own author may make.
 *
 * The Twig back-office only registers its routes when it is the active admin template
 * of the shop the kernel boots on, so the suite states that requirement instead of
 * reading a 404 as a routing bug.
 */
final class OrderHistoryBackOfficeTest extends WebIntegrationTestCase
{
    private const LOCALE = 'en_US';

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

        // Built without createFixtureFactory(): that helper pushes a synthetic request
        // when the stack is empty, and it would then be the "main" request the
        // SecurityContext reads its session from.
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        // setUp() can stop on markTestSkipped before either property is set, and
        // tearDown() still runs.
        if (isset($this->injector)) {
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheSheetOfAnOrderWithAHistoryShowsTheTimeline(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_PAID]);

        $statusChange = $this->entry($order, OrderHistoryEventType::STATUS_CHANGED->value, [
            'payload' => ['from' => OrderStatus::CODE_NOT_PAID, 'to' => OrderStatus::CODE_PAID],
            'actorLabel' => 'alice',
        ]);
        $note = $this->entry($order, OrderHistoryEventType::NOTE->value, [
            'comment' => 'The buyer called about the parcel.',
            'actorLabel' => 'alice',
        ]);

        $this->assertPageRenders($this->sheetUrl($order));
        $html = $this->html();

        self::assertStringContainsString('order-history-card', $html, 'The sheet must carry the history block.');
        self::assertStringContainsString('order-history-timeline', $html);
        self::assertStringContainsString('order-history-entry-'.$statusChange->getId(), $html);
        self::assertStringContainsString('order-history-entry-'.$note->getId(), $html);
        self::assertStringContainsString('The buyer called about the parcel.', $html);
        self::assertStringContainsString('alice', $html, 'The author of an entry is shown in clear.');

        self::assertStringContainsString(
            \sprintf(
                'Status changed from %s to %s',
                $this->statusTitle(OrderStatus::CODE_NOT_PAID),
                $this->statusTitle(OrderStatus::CODE_PAID),
            ),
            $html,
            'A status change reads with the status titles of its payload, not with their codes.',
        );
    }

    /**
     * The stock movement a status change applied is on its line, with the action that chose it;
     * a line without it (written before, or by a change that moved nothing) stays as it was.
     */
    public function testTheStockMovementOfAStatusChangeReadsOnItsLine(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_PAID]);

        $putBack = $this->entry($order, OrderHistoryEventType::STATUS_CHANGED->value, [
            'payload' => ['from' => OrderStatus::CODE_PAID, 'to' => OrderStatus::CODE_CANCELED, 'stock' => 'increase', 'stock_action_id' => 42],
        ]);
        $left = $this->entry($order, OrderHistoryEventType::STATUS_CHANGED->value, [
            'payload' => ['from' => OrderStatus::CODE_NOT_PAID, 'to' => OrderStatus::CODE_PAID, 'stock' => 'none', 'stock_action_id' => null],
        ]);
        $plain = $this->entry($order, OrderHistoryEventType::STATUS_CHANGED->value, [
            'payload' => ['from' => OrderStatus::CODE_PAID, 'to' => OrderStatus::CODE_PROCESSING],
        ]);

        $this->assertPageRenders($this->sheetUrl($order));
        $html = $this->html();

        self::assertMatchesRegularExpression('/order-history-entry-'.$putBack->getId().'.*?Stock put back by action #42/s', $html);
        self::assertMatchesRegularExpression('/order-history-entry-'.$left->getId().'.*?Stock left as it is/s', $html);
        self::assertStringNotContainsString('Stock left as it is by action', $html);
        self::assertStringContainsString('order-history-entry-'.$plain->getId(), $html);
    }

    public function testAnEntryOfAnUnknownTypeStillReadsOnTheTimeline(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order();

        // What a third-party module writes: a type the core has no label for.
        $entry = $this->entry($order, 'carrier_pickup_scheduled', ['actorType' => OrderHistoryActorType::MODULE->value, 'actorLabel' => 'MyCarrier']);

        $this->assertPageRenders($this->sheetUrl($order));
        $html = $this->html();

        self::assertStringContainsString('order-history-entry-'.$entry->getId(), $html);
        self::assertStringContainsString('carrier_pickup_scheduled', $html, 'An unknown type shows its own code.');
        self::assertStringContainsString('Module MyCarrier', $html);
    }

    /**
     * A return is a second story told about an order, and the merchant reads one
     * timeline: the lines a return leaves must read as sentences about that return,
     * with its reference and the titles of its own statuses, not with raw codes.
     */
    public function testTheGesturesOfAReturnReadOnTheOrderTimeline(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_PAID]);

        $opened = $this->entry($order, OrderHistoryEventType::RETURN_OPENED->value, [
            'payload' => ['return_ref' => 'RET202609140001'],
        ]);
        $moved = $this->entry($order, OrderHistoryEventType::RETURN_STATUS_CHANGED->value, [
            'payload' => [
                'return_ref' => 'RET202609140001',
                'from' => OrderReturnStatus::CODE_ACCEPTED,
                'to' => OrderReturnStatus::CODE_RECEIVED,
            ],
        ]);
        $received = $this->entry($order, OrderHistoryEventType::RETURN_RECEIVED->value, [
            'payload' => ['return_ref' => 'RET202609140001'],
        ]);

        $this->assertPageRenders($this->sheetUrl($order));
        $html = $this->html();

        self::assertStringContainsString('order-history-entry-'.$opened->getId(), $html);
        self::assertStringContainsString('order-history-entry-'.$moved->getId(), $html);
        self::assertStringContainsString('order-history-entry-'.$received->getId(), $html);

        self::assertStringContainsString('Return RET202609140001 opened', $html);
        self::assertStringContainsString('Return RET202609140001 received', $html);
        self::assertStringContainsString(
            \sprintf(
                'Return RET202609140001 changed from %s to %s',
                $this->returnStatusTitle(OrderReturnStatus::CODE_ACCEPTED),
                $this->returnStatusTitle(OrderReturnStatus::CODE_RECEIVED),
            ),
            $html,
            'A return status change reads with the return status titles, not with their codes.',
        );

        self::assertStringContainsString('bi-arrow-return-left', $html);
        self::assertStringContainsString('bi-box-arrow-in-down', $html);
    }

    /**
     * A provider that confirms a payment after the card was given back leaves a line
     * the merchant must act on: it reads as a sentence naming the part, never as the code.
     */
    public function testAPaymentConfirmedAfterTheReleaseReadsAsARefundToDo(): void
    {
        // TODO remove once thelia/core 3.3 is required.
        if (!\defined(OrderHistoryEventType::class.'::PAYMENT_CONFIRMED_AFTER_RELEASE')) {
            self::markTestSkipped('Needs the stored payment methods of Thelia 3.3.');
        }

        $this->loginFullAdmin();
        $order = $this->factory->order(null, ['statusCode' => OrderStatus::CODE_CANCELED]);

        $entry = $this->entry($order, OrderHistoryEventType::PAYMENT_CONFIRMED_AFTER_RELEASE->value, [
            'actorType' => OrderHistoryActorType::MODULE->value,
            'actorLabel' => 'MyProvider',
            'payload' => ['payment_part_id' => 42],
        ]);

        $this->assertPageRenders($this->sheetUrl($order));
        $html = $this->html();

        self::assertStringContainsString('order-history-entry-'.$entry->getId(), $html);
        self::assertStringContainsString(
            'Payment confirmed after the card was given back: refund it through the provider (payment part 42)',
            $html,
        );
        self::assertStringNotContainsString('payment_confirmed_after_release', $html, 'The raw code must not reach the merchant.');
        self::assertStringContainsString('bi-cash-coin', $html);
    }

    public function testTheSheetOfAnOrderWithoutHistoryRendersWithoutATimeline(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order();

        $this->assertPageRenders($this->sheetUrl($order));
        $html = $this->html();

        self::assertStringContainsString('order-history-card', $html, 'The block is there, only empty.');
        self::assertStringContainsString('order-history-empty', $html);
        self::assertStringNotContainsString('order-history-timeline', $html);
    }

    public function testTheTimelineIsPaginatedNewestFirst(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order();

        // One more than a page holds, so the oldest one is pushed off page one.
        $entries = [];
        for ($rank = 1; $rank <= 11; ++$rank) {
            $entries[] = $this->entry($order, OrderHistoryEventType::NOTE->value, [
                'comment' => \sprintf('Memo number %d.', $rank),
            ]);
        }
        $oldest = $entries[0];
        $newest = $entries[10];

        $this->assertPageRenders($this->sheetUrl($order));
        $firstPage = $this->html();

        self::assertStringContainsString('order-history-entry-'.$newest->getId(), $firstPage);
        self::assertStringNotContainsString(
            'order-history-entry-'.$oldest->getId(),
            $firstPage,
            'The eleventh entry back does not fit on the first page.',
        );
        self::assertStringContainsString('data-testid="order-history"', $firstPage, 'The block paginates.');

        $this->assertPageRenders($this->sheetUrl($order).'?history_page=2');
        $secondPage = $this->html();

        self::assertStringContainsString('order-history-entry-'.$oldest->getId(), $secondPage);
        self::assertStringNotContainsString('order-history-entry-'.$newest->getId(), $secondPage);
    }

    public function testTheCommentOfANoteIsEscapedOnTheTimeline(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order();
        $this->entry($order, OrderHistoryEventType::NOTE->value, ['comment' => '<script>alert(1)</script>']);

        $this->assertPageRenders($this->sheetUrl($order));
        $html = $this->html();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAnAdministratorAddsANoteAndTheGestureIsAudited(): void
    {
        $admin = $this->loginFullAdmin();
        $order = $this->factory->order();

        $this->client->request('POST', $this->addNoteUrl($order), [
            '_token' => $this->tokenOf($this->sheetUrl($order)),
            'comment' => 'Called the buyer, parcel leaves tomorrow.',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $notes = $this->notesOf($order);
        self::assertCount(1, $notes);
        self::assertSame('Called the buyer, parcel leaves tomorrow.', $notes[0]->getComment());
        self::assertSame((int) $admin->getId(), $notes[0]->getAdminId(), 'The note is attributed to its author.');
        self::assertFalse($notes[0]->isVisibleToCustomer(), 'A note is private unless the box is ticked.');

        $log = $this->latestAdminLog();
        self::assertNotNull($log, 'Writing a note is appended to the admin audit log.');
        self::assertSame(AdminResources::ORDER, $log->getResource());
        self::assertSame(AccessManager::UPDATE, $log->getAction());
        self::assertSame((int) $order->getId(), $log->getResourceId());
        self::assertStringContainsString('Note added to the history of order', (string) $log->getMessage());

        $this->assertPageRenders($this->sheetUrl($order));
        self::assertStringContainsString('Called the buyer, parcel leaves tomorrow.', $this->html());
    }

    public function testANoteTickedVisibleIsStoredAsVisibleAndBadgedOnTheSheet(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order();

        $this->client->request('POST', $this->addNoteUrl($order), [
            '_token' => $this->tokenOf($this->sheetUrl($order)),
            'comment' => 'Your parcel left our warehouse.',
            'visible_to_customer' => '1',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $notes = $this->notesOf($order);
        self::assertCount(1, $notes);
        self::assertTrue($notes[0]->isVisibleToCustomer());

        $this->assertPageRenders($this->sheetUrl($order));
        self::assertStringContainsString('order-history-visible-'.$notes[0]->getId(), $this->html());
    }

    public function testAnEmptyNoteIsRefused(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order();

        $this->client->request('POST', $this->addNoteUrl($order), [
            '_token' => $this->tokenOf($this->sheetUrl($order)),
            'comment' => '   ',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->notesOf($order), 'A blank note must not reach the timeline.');
    }

    public function testTheAuthorRewritesHisOwnNoteWithoutAddingALine(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order();

        $this->client->request('POST', $this->addNoteUrl($order), [
            '_token' => $this->tokenOf($this->sheetUrl($order)),
            'comment' => 'First wording.',
        ]);
        $note = $this->notesOf($order)[0];
        $entriesBefore = $this->historyCount($order);

        $this->client->request('POST', $this->editNoteUrl($order, (int) $note->getId()), [
            '_token' => $this->tokenOf($this->sheetUrl($order)),
            'comment' => 'Corrected wording.',
            'visible_to_customer' => '1',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $notes = $this->notesOf($order);
        self::assertCount(1, $notes);
        self::assertSame('Corrected wording.', $notes[0]->getComment());
        self::assertTrue($notes[0]->isVisibleToCustomer());
        self::assertSame(
            $entriesBefore,
            $this->historyCount($order),
            'A correction replaces the entry, it does not append one.',
        );
    }

    public function testTheEditButtonIsOfferedToTheAuthorOnly(): void
    {
        $author = $this->loginFullAdmin();
        $order = $this->factory->order();

        $this->client->request('POST', $this->addNoteUrl($order), [
            '_token' => $this->tokenOf($this->sheetUrl($order)),
            'comment' => 'Only mine.',
        ]);
        $note = $this->notesOf($order)[0];

        $this->assertPageRenders($this->sheetUrl($order));
        self::assertStringContainsString('order-history-edit-'.$note->getId(), $this->html());

        $other = $this->fullAdmin();
        self::assertNotSame((int) $author->getId(), (int) $other->getId());
        $this->injector->setAdmin($other);

        $this->assertPageRenders($this->sheetUrl($order));
        self::assertStringNotContainsString(
            'order-history-edit-'.$note->getId(),
            $this->html(),
            'Another administrator is not offered the button on a note he did not write.',
        );
    }

    public function testAnotherAdministratorCannotRewriteTheNote(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order();

        $this->client->request('POST', $this->addNoteUrl($order), [
            '_token' => $this->tokenOf($this->sheetUrl($order)),
            'comment' => 'Written by its author.',
        ]);
        $note = $this->notesOf($order)[0];

        // The refusal is decided on the server, so the post is made exactly as the
        // author would make it, only signed in as somebody else.
        $this->injector->setAdmin($this->fullAdmin());

        $this->client->request('POST', $this->editNoteUrl($order, (int) $note->getId()), [
            '_token' => $this->tokenOf($this->sheetUrl($order)),
            'comment' => 'Rewritten by a stranger.',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            'Written by its author.',
            $this->notesOf($order)[0]->getComment(),
            'A note may only be rewritten by the administrator who wrote it.',
        );

        $this->client->followRedirect();
        self::assertStringContainsString(
            'Only the administrator who wrote a note may edit it.',
            $this->html(),
            'The refusal is told to the administrator.',
        );
    }

    public function testASettledOrderFreezesItsNotes(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order();

        $this->client->request('POST', $this->addNoteUrl($order), [
            '_token' => $this->tokenOf($this->sheetUrl($order)),
            'comment' => 'Written while the order was live.',
        ]);
        $note = $this->notesOf($order)[0];

        $order->setStatusId($this->statusId(OrderStatus::CODE_REFUNDED));
        $order->save($this->getPropelConnection());

        $this->assertPageRenders($this->sheetUrl($order));
        self::assertStringNotContainsString('order-history-edit-'.$note->getId(), $this->html());

        $this->client->request('POST', $this->editNoteUrl($order, (int) $note->getId()), [
            '_token' => $this->tokenOf($this->sheetUrl($order)),
            'comment' => 'Rewritten after the refund.',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('Written while the order was live.', $this->notesOf($order)[0]->getComment());
    }

    public function testTheBlockIsOutOfReachOfAnAdministratorWithoutTheOrdersPermission(): void
    {
        // The sheet itself requires the orders permission, so an administrator without
        // it never sees the block because he never sees the page - which is what the
        // context builder is asked to guarantee on its own as well.
        $admin = $this->factory->restrictedAdmin([
            AdminResources::ORDER_RETURN => [AccessManager::VIEW],
        ]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $order = $this->factory->order();
        $this->entry($order, OrderHistoryEventType::NOTE->value, ['comment' => 'Not for this administrator.']);

        $this->client->request('GET', $this->sheetUrl($order));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('order-history-card', $this->html());
        self::assertStringNotContainsString('Not for this administrator.', $this->html());
    }

    public function testWritingANoteIsRefusedWithoutTheUpdatePermission(): void
    {
        $admin = $this->factory->restrictedAdmin([
            AdminResources::ORDER => [AccessManager::VIEW],
        ]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $order = $this->factory->order();

        $this->assertPageRenders($this->sheetUrl($order));
        self::assertStringNotContainsString(
            'order-history-note-form',
            $this->html(),
            'A read-only administrator is not offered the note form.',
        );

        // The permission is checked before the CSRF token, so the refusal below is the
        // permission's - a read-only sheet renders no token to submit anyway.
        $this->client->request('POST', $this->addNoteUrl($order), [
            '_token' => 'no-token-on-a-read-only-sheet',
            'comment' => 'Should never be written.',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->notesOf($order));
    }

    private function loginFullAdmin(): Admin
    {
        $admin = $this->fullAdmin();
        $this->injector->setAdmin($admin);

        return $admin;
    }

    private function fullAdmin(): Admin
    {
        $admin = $this->factory->admin(['locale' => self::LOCALE]);
        $admin->eraseCredentials();

        return $admin;
    }

    /**
     * @param array{payload?: array<string, mixed>, comment?: string, actorType?: string, actorLabel?: string, adminId?: int, visible?: bool} $overrides
     */
    private function entry(Order $order, string $eventType, array $overrides = []): OrderHistory
    {
        $payload = $overrides['payload'] ?? [];

        $entry = (new OrderHistory())
            ->setOrderId((int) $order->getId())
            ->setEventType($eventType)
            ->setActorType($overrides['actorType'] ?? OrderHistoryActorType::ADMIN->value)
            ->setActorLabel($overrides['actorLabel'] ?? 'seeded-admin')
            ->setAdminId($overrides['adminId'] ?? null)
            ->setPayload([] === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR))
            ->setComment($overrides['comment'] ?? null)
            ->setVisibleToCustomer(($overrides['visible'] ?? false) ? 1 : 0);
        $entry->save($this->getPropelConnection());

        return $entry;
    }

    /**
     * @return list<OrderHistory>
     */
    private function notesOf(Order $order): array
    {
        OrderHistoryTableMap::clearInstancePool();

        /** @var list<OrderHistory> $notes */
        $notes = OrderHistoryQuery::create()
            ->filterByOrderId((int) $order->getId())
            ->filterByEventType(OrderHistoryEventType::NOTE->value)
            ->orderById()
            ->find($this->getPropelConnection())
            ->getData();

        return $notes;
    }

    private function historyCount(Order $order): int
    {
        return OrderHistoryQuery::create()
            ->filterByOrderId((int) $order->getId())
            ->count($this->getPropelConnection());
    }

    /**
     * The last line of the admin audit log. Read as the newest row rather than filtered
     * on its message: `message` is a text column, and Propel's generated filter does not
     * turn a `%` in a text column into a LIKE.
     */
    private function latestAdminLog(): ?AdminLog
    {
        return AdminLogQuery::create()
            ->orderById(Criteria::DESC)
            ->findOne($this->getPropelConnection());
    }

    private function sheetUrl(Order $order): string
    {
        return '/admin/order/update/'.$order->getId();
    }

    private function addNoteUrl(Order $order): string
    {
        return '/admin/order/'.$order->getId().'/history/note';
    }

    private function editNoteUrl(Order $order, int $historyId): string
    {
        return '/admin/order/'.$order->getId().'/history/note/'.$historyId;
    }

    private function statusId(string $code): int
    {
        return (int) OrderStatusQuery::create()->findOneByCode($code)?->getId();
    }

    private function statusTitle(string $code): string
    {
        $status = OrderStatusQuery::create()->findOneByCode($code);
        $status?->setLocale(self::LOCALE);

        return (string) $status?->getTitle();
    }

    private function returnStatusTitle(string $code): string
    {
        $status = OrderReturnStatusQuery::create()->findOneByCode($code);
        $status?->setLocale(self::LOCALE);

        return (string) $status?->getTitle();
    }

    /**
     * The CSRF token the given page renders, so a POST is submitted the way the browser
     * submits it.
     */
    private function tokenOf(string $url): string
    {
        $crawler = $this->client->request('GET', $url);
        $token = $crawler->filter('input[name="_token"]')->first();

        self::assertGreaterThan(0, $token->count(), \sprintf('"%s" renders no CSRF token.', $url));

        return (string) $token->attr('value');
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
