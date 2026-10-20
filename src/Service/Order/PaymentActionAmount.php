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

use Thelia\Domain\Payment\Exception\PaymentException;
use Thelia\Domain\Payment\Service\PaymentAmount;
use Thelia\Model\Order;

/**
 * The amount an administrator types in a payment dialog (capture, refund), read the way
 * a merchant writes it, and written back in the administration log.
 */
final class PaymentActionAmount
{
    /** What the admin API accepts, and below what the payment journal holds. */
    public const MAX = 9999999999.99;

    /**
     * A plain positive decimal, a comma or a point before the decimals, blanks ignored;
     * null for anything else (an exponent, a sign, a word).
     */
    public static function fromTyped(string $typed): ?float
    {
        $text = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim($typed));

        if (1 !== preg_match('/^\d+(?:\.\d+)?$/', $text)) {
            return null;
        }

        $amount = (float) $text;

        return $amount > 0 ? $amount : null;
    }

    public static function exceedsWhatTheJournalHolds(float $amount): bool
    {
        return $amount > self::MAX;
    }

    /**
     * The amount as the administration log writes it, in the currency of the order. A line
     * of the log is never what makes an action fail: an amount it cannot word is said so.
     */
    public static function forLog(?float $amount, Order $order): string
    {
        if (null === $amount) {
            return 'the whole amount';
        }

        try {
            return PaymentAmount::forMessage($amount).' '.$order->getCurrency()->getCode();
        } catch (PaymentException) {
            return 'an amount the payment journal cannot hold';
        }
    }
}
