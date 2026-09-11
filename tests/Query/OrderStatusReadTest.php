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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\Repository\OrderStatusRepository;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Thelia\Model\OrderStatus;
use Thelia\Test\IntegrationTestCase;

/**
 * The localized status list behind the status screens.
 *
 * A single status sheet asks for it three times: the transitions tab, the
 * unreachable-status warning of that same tab, and the actions tab. The budget
 * is one read per locale and per request; going back to a query per call breaks
 * these tests.
 */
final class OrderStatusReadTest extends IntegrationTestCase
{
    private OrderStatusRepository $statuses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statuses = new OrderStatusRepository();
    }

    public function testTheStatusesAreReadOnceWhateverTheNumberOfCallers(): void
    {
        $first = [];
        $queries = QueryCounter::count(function () use (&$first): void {
            $first = $this->statuses->findLocalized('en_US');
        });

        self::assertGreaterThan(1, \count($first), 'The installation seeds several statuses.');
        self::assertSame(1, $queries, 'Titles included, the list costs one query.');

        $queries = QueryCounter::count(function (): void {
            $this->statuses->findLocalized('en_US');
            $this->statuses->findLocalized('en_US');
            $this->statuses->findLocalized('en_US');
        });

        self::assertSame(0, $queries, 'The three callers of a status sheet share the first read.');
    }

    public function testAnotherLocaleIsItsOwnRead(): void
    {
        $this->statuses->findLocalized('en_US');

        $french = [];
        $queries = QueryCounter::count(function () use (&$french): void {
            $french = $this->statuses->findLocalized('fr_FR');
        });

        self::assertSame(1, $queries, 'A locale never serves another one its titles.');
        self::assertNotSame([], $french);
    }

    public function testTheListIsIndexedByIdAndOrderedByPosition(): void
    {
        $statuses = $this->statuses->findLocalized('en_US');

        $positions = [];
        foreach ($statuses as $id => $status) {
            self::assertSame($id, (int) $status->getId(), 'The list is indexed by status id.');
            $positions[] = (int) $status->getPosition();
        }

        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);

        $notPaid = array_filter($statuses, static fn (OrderStatus $status): bool => $status->getCode() === OrderStatus::CODE_NOT_PAID);
        self::assertCount(1, $notPaid, 'The seeded statuses are there.');
    }
}
