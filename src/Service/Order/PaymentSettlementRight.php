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

use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Payment\Enum\PaymentTransactionType;

/**
 * The right that records by hand the outcome of a pending line of the payment journal: the
 * one that made the movement. A refund settled as succeeded gives money back, an
 * authorization, a capture or a release settled moves what the order takes.
 */
final class PaymentSettlementRight
{
    /** @var list<string> */
    public const RESOURCES = [AdminResources::ORDER_PAYMENT_CAPTURE, AdminResources::ORDER_PAYMENT_REFUND];

    public static function of(string $type): string
    {
        return PaymentTransactionType::REFUND->value === $type
            ? AdminResources::ORDER_PAYMENT_REFUND
            : AdminResources::ORDER_PAYMENT_CAPTURE;
    }
}
