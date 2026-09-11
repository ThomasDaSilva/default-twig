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
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;

/**
 * Reads the order statuses for display, titles included, in one query. The
 * screens work on these copies and never touch the objects the core keeps in
 * its per-request catalog.
 *
 * A status screen asks for the same list three times (the transitions tab, the
 * unreachable-status warning, the actions tab), so the read is memoised per
 * locale for the request, as the order filter catalog does. A write on the
 * statuses drops the memo, the way the core catalog does: the screen that
 * created, renamed, deleted or moved a status renders the list again in the
 * same request.
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

    #[AsEventListener(event: TheliaEvents::ORDER_STATUS_CREATE, priority: -128)]
    #[AsEventListener(event: TheliaEvents::ORDER_STATUS_UPDATE, priority: -128)]
    #[AsEventListener(event: TheliaEvents::ORDER_STATUS_DELETE, priority: -128)]
    #[AsEventListener(event: TheliaEvents::ORDER_STATUS_UPDATE_POSITION, priority: -128)]
    public function reset(): void
    {
        $this->statusesByLocale = [];
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
