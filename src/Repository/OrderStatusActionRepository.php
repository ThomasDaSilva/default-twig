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
use Thelia\Model\Map\OrderStatusActionFailureTableMap;
use Thelia\Model\OrderStatusAction;
use Thelia\Model\OrderStatusActionFailureQuery;
use Thelia\Model\OrderStatusActionQuery;

/**
 * Reads the automatic actions of a status and what went wrong when they ran.
 */
final readonly class OrderStatusActionRepository
{
    /**
     * @return list<OrderStatusAction>
     */
    public function findForStatus(int $statusId): array
    {
        return OrderStatusActionQuery::create()
            ->filterByToStatusId($statusId)
            ->orderByPosition()
            ->orderById()
            ->find()
            ->getData();
    }

    public function find(int $actionId): ?OrderStatusAction
    {
        return OrderStatusActionQuery::create()->findPk($actionId);
    }

    /**
     * @param list<int> $actionIds
     *
     * @return array<int, int> action id => number of recorded failures
     */
    public function countFailuresByAction(array $actionIds): array
    {
        if ([] === $actionIds) {
            return [];
        }

        $rows = OrderStatusActionFailureQuery::create()
            ->filterByActionId($actionIds, Criteria::IN)
            ->withColumn('COUNT(*)', 'failures')
            ->select(['ActionId', 'failures'])
            ->groupBy(OrderStatusActionFailureTableMap::COL_ACTION_ID)
            ->find();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['ActionId']] = (int) $row['failures'];
        }

        return $counts;
    }

    /**
     * The most recent failures of the actions of a status, newest first.
     *
     * @return list<array{action_id: int, action_type: string, order_id: int, order_ref: string, message: string, created_at: ?\DateTimeInterface}>
     */
    public function findRecentFailures(int $statusId, int $limit = 10): array
    {
        $failures = OrderStatusActionFailureQuery::create()
            ->useActionQuery()
                ->filterByToStatusId($statusId)
            ->endUse()
            ->joinAction()
            ->with('Action')
            ->joinOrder()
            ->with('Order')
            ->orderByCreatedAt(Criteria::DESC)
            ->orderById(Criteria::DESC)
            ->limit($limit)
            ->find();

        $rows = [];

        foreach ($failures as $failure) {
            $rows[] = [
                'action_id' => (int) $failure->getActionId(),
                'action_type' => (string) $failure->getAction()->getActionType(),
                'order_id' => (int) $failure->getOrderId(),
                'order_ref' => (string) $failure->getOrder()->getRef(),
                'message' => (string) $failure->getMessage(),
                'created_at' => $failure->getCreatedAt(),
            ];
        }

        return $rows;
    }
}
