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

namespace BackOfficeDefaultTwigBundle\Repository;

use Propel\Runtime\Collection\ObjectCollection;
use Thelia\Model\OrderPaymentTransaction;
use Thelia\Model\OrderPaymentTransactionQuery;

/**
 * The Propel reads behind the payment block of the order sheet.
 */
final readonly class OrderPaymentTransactionRepository
{
    /**
     * The whole journal of an order, newest first. A journal holds a handful of lines,
     * so the sheet shows it entire rather than paginated.
     *
     * @return ObjectCollection<int, OrderPaymentTransaction>
     */
    public function findJournalOfOrder(int $orderId): ObjectCollection
    {
        return OrderPaymentTransactionQuery::create()->findJournal($orderId);
    }
}
