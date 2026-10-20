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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Event;

use BackOfficeDefaultTwigBundle\Event\OrderReturnListEvent;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\Column;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ColumnKind;
use PHPUnit\Framework\TestCase;

/** Where a module's column lands in the return queue, whatever the listeners before it did. */
final class OrderReturnListEventTest extends TestCase
{
    public function testAColumnGoesRightAfterItsAnchor(): void
    {
        $event = $this->event();

        $event->insertColumnAfter('reason', new Column('note', 'Note'));

        self::assertSame(['order', 'reason', 'note', 'status', '_actions'], $this->keys($event));
    }

    public function testAColumnWhoseAnchorIsGoneStaysBeforeTheActions(): void
    {
        $event = $this->event();
        $event->removeColumn('reason');

        $event->insertColumnAfter('reason', new Column('note', 'Note'));

        self::assertSame(
            ['order', 'status', 'note', '_actions'],
            $this->keys($event),
            'The actions column is pinned to the right edge: a column after it would scroll under it.',
        );
    }

    public function testWithoutAnActionsColumnAColumnWhoseAnchorIsGoneComesLast(): void
    {
        $event = new OrderReturnListEvent([new Column('order', 'Order'), new Column('status', 'Status')], [], 'en_US');

        $event->insertColumnAfter('reason', new Column('note', 'Note'));

        self::assertSame(['order', 'status', 'note'], $this->keys($event));
    }

    public function testAColumnPlacedAfterAColumnBehindTheActionsFollowsIt(): void
    {
        $event = new OrderReturnListEvent(
            [new Column('order', 'Order'), new Column('_actions', 'Actions', ColumnKind::ACTIONS, 'end'), new Column('extra', 'Extra')],
            [],
            'en_US',
        );

        $event->insertColumnAfter('extra', new Column('note', 'Note'));

        self::assertSame(['order', '_actions', 'extra', 'note'], $this->keys($event), 'An anchor that is there wins over the actions column.');
    }

    public function testTwoColumnsWhoseAnchorIsGoneKeepTheOrderTheyWereAddedIn(): void
    {
        $event = $this->event();

        $event->insertColumnAfter('gone', new Column('first', 'First'));
        $event->insertColumnAfter('gone', new Column('second', 'Second'));

        self::assertSame(['order', 'reason', 'status', 'first', 'second', '_actions'], $this->keys($event));
    }

    public function testAListenerTellsWhetherAColumnIsStillThere(): void
    {
        $event = $this->event();
        $event->removeColumn('reason');

        self::assertTrue($event->hasColumn('order'));
        self::assertFalse($event->hasColumn('reason'));
    }

    private function event(): OrderReturnListEvent
    {
        return new OrderReturnListEvent(
            [
                new Column('order', 'Order', ColumnKind::HTML),
                new Column('reason', 'Reason'),
                new Column('status', 'Status', ColumnKind::BADGE),
                new Column('_actions', 'Actions', ColumnKind::ACTIONS, 'end'),
            ],
            [['id' => 1]],
            'en_US',
        );
    }

    /**
     * @return list<string>
     */
    private function keys(OrderReturnListEvent $event): array
    {
        return array_map(static fn (Column $column): string => $column->key, $event->getColumns());
    }
}
