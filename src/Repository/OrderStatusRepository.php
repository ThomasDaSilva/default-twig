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

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;

/**
 * Reads the order statuses for display, titles included, in one query. The
 * screens work on these copies and never touch the objects the core keeps in
 * its per-request catalog.
 *
 * A status screen asks for the same list three times (the transitions tab, the
 * unreachable-status warning, the actions tab), so the read is memoised per
 * locale for the request, as the order filter catalog does.
 */
final class OrderStatusRepository
{
    /** @var array<string, array<int, OrderStatus>> */
    private array $statusesByLocale = [];

    /**
     * @return array<int, OrderStatus> indexed by id, in position order, localized
     */
    public function findLocalized(string $locale): array
    {
        return $this->statusesByLocale[$locale] ??= $this->readLocalized($locale);
    }

    /**
     * @return array<int, OrderStatus>
     */
    private function readLocalized(string $locale): array
    {
        $statuses = [];

        foreach (OrderStatusQuery::create()->joinWithI18n($locale, Criteria::LEFT_JOIN)->orderByPosition()->find() as $status) {
            $status->setLocale($locale);
            $statuses[(int) $status->getId()] = $status;
        }

        return $statuses;
    }
}
