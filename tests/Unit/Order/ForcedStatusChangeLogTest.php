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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Order;

use BackOfficeDefaultTwigBundle\Service\Order\ForcedStatusChangeLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The administration log has no column for a status, so a forced change writes
 * its two codes inside the sentence and the order sheet reads them back from
 * there. A status code is a free 45-character field and an order reference is
 * whatever the shop names its orders: neither can be trusted to stay out of the
 * way of the words of that sentence.
 */
final class ForcedStatusChangeLogTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideChanges(): iterable
    {
        yield 'plain codes' => ['ORD-1', 'sent', 'not_paid'];
        yield 'a code with a space' => ['ORD-2', 'awaiting pickup', 'handed over'];
        yield 'an empty code' => ['ORD-3', '', 'paid'];
        yield 'two empty codes' => ['ORD-4', '', ''];
        yield 'a reference reading like the sentence' => ['ORD from status sent to status paid', 'sent', 'paid'];
        yield 'a reference holding the whole wording' => ['Forced order X from status "a" to status "b", outside the allowed transitions', 'sent', 'paid'];
    }

    #[DataProvider('provideChanges')]
    public function testAChangeIsReadBackExactlyAsItWasWritten(string $ref, string $from, string $to): void
    {
        $message = ForcedStatusChangeLog::message($ref, $from, $to);

        self::assertStringStartsWith(ForcedStatusChangeLog::MESSAGE_PREFIX, $message, 'The repository finds the entry on that prefix alone.');
        self::assertSame(['ref' => $ref, 'from' => $from, 'to' => $to], ForcedStatusChangeLog::parse($message));
    }

    public function testTheWordingUsedBeforeTheCodesWereQuotedIsStillRead(): void
    {
        self::assertSame(
            ['ref' => 'ORD-9', 'from' => 'sent', 'to' => 'not_paid'],
            ForcedStatusChangeLog::parse('Forced order ORD-9 from status sent to status not_paid, outside the allowed transitions'),
            'Entries written by the previous wording are on file for good.',
        );
    }

    public function testAnythingElseIsNotAForcedChange(): void
    {
        self::assertNull(ForcedStatusChangeLog::parse('Order status updated'));
        self::assertNull(ForcedStatusChangeLog::parse('Forced order ORD-1 from status "sent" to status "paid"'));
        self::assertNull(ForcedStatusChangeLog::parse(''));
    }
}
