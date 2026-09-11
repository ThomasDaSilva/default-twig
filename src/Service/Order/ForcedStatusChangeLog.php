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

namespace BackOfficeDefaultTwigBundle\Service\Order;

/**
 * The wording of the administration log entry a forced status change leaves, and
 * the way back from that wording to the two status codes.
 *
 * The administration log has no column for a status, so the codes travel in the
 * message. Writing and reading it are kept side by side here so the two can
 * never drift apart: the order sheet reads back exactly what the controller
 * wrote. The sentence is deliberately never translated, for the same reason as
 * every other line of that log.
 */
final class ForcedStatusChangeLog
{
    /**
     * What the repository filters the order's log entries on.
     */
    public const MESSAGE_PREFIX = 'Forced order ';

    private const MESSAGE_FORMAT = self::MESSAGE_PREFIX.'%s from status %s to status %s, outside the allowed transitions';

    private const MESSAGE_PATTERN = '/^Forced order (?<ref>.+) from status (?<from>\S+) to status (?<to>\S+), outside the allowed transitions$/';

    public static function message(string $orderRef, string $fromStatusCode, string $toStatusCode): string
    {
        return \sprintf(self::MESSAGE_FORMAT, $orderRef, $fromStatusCode, $toStatusCode);
    }

    /**
     * The two status codes of a forced change, or null for a message that is not one.
     *
     * @return array{ref: string, from: string, to: string}|null
     */
    public static function parse(string $message): ?array
    {
        if (1 !== preg_match(self::MESSAGE_PATTERN, $message, $matches)) {
            return null;
        }

        return ['ref' => $matches['ref'], 'from' => $matches['from'], 'to' => $matches['to']];
    }
}
