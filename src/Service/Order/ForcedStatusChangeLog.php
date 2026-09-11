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
 *
 * A status code is a free 45-character field: nothing forbids a space in it, or
 * an empty one. The codes are therefore written between double quotes, which is
 * what keeps them apart from the words of the sentence and from an order
 * reference that happens to read like them. A code holding a double quote of its
 * own is the one thing this wording cannot carry back.
 */
final class ForcedStatusChangeLog
{
    /**
     * What the repository filters the order's log entries on.
     */
    public const MESSAGE_PREFIX = 'Forced order ';

    private const MESSAGE_SUFFIX = ', outside the allowed transitions';

    private const MESSAGE_FORMAT = self::MESSAGE_PREFIX.'%s from status "%s" to status "%s"'.self::MESSAGE_SUFFIX;

    /**
     * The wording used before the codes were quoted. Entries written by it are
     * still on file, and the order sheet still has to read them.
     */
    private const LEGACY_MESSAGE_PATTERN = '/^Forced order (?<ref>.+) from status (?<from>\S+) to status (?<to>\S+), outside the allowed transitions$/D';

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
        foreach ([self::messagePattern(), self::LEGACY_MESSAGE_PATTERN] as $pattern) {
            if (1 === preg_match($pattern, $message, $matches)) {
                return ['ref' => $matches['ref'], 'from' => $matches['from'], 'to' => $matches['to']];
            }
        }

        return null;
    }

    private static function messagePattern(): string
    {
        return '/^'
            .preg_quote(self::MESSAGE_PREFIX, '/')
            .'(?<ref>.*) from status "(?<from>[^"]*)" to status "(?<to>[^"]*)"'
            .preg_quote(self::MESSAGE_SUFFIX, '/')
            .'$/D';
    }
}
