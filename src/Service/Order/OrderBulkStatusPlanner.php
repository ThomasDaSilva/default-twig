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

use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderStatusRepository;
use Thelia\Domain\Order\Service\OrderStatusTransitionGuard;

/**
 * The graph, read for the bulk status change of the order list: which of the
 * selected orders may reach the chosen status, and which status each of the
 * others may be reached from.
 *
 * The decision asks the core guard, order by order, on three read columns. The
 * guard also offers partition(), but it takes hydrated Order objects, which
 * would mean reading every selected order whole only to skip most of them, or
 * fabricating Order instances from a partial read that a later save() would
 * truncate. isAllowed() is what partition() itself loops on, so the graph stays
 * the core's and no rule is restated here.
 */
final readonly class OrderBulkStatusPlanner
{
    public function __construct(
        private OrderStatusTransitionGuard $transitionGuard,
        private OrderRepository $orderRepository,
        private OrderStatusRepository $statusRepository,
    ) {
    }

    /**
     * @param list<int> $orderIds
     *
     * @return array{allowed_ids: list<int>, refused_refs: list<string>}
     */
    public function plan(array $orderIds, int $toStatusId): array
    {
        $allowedIds = [];
        $refusedRefs = [];

        foreach ($this->orderRepository->findStatusDecisionRows($orderIds) as $decision) {
            if ($this->transitionGuard->isAllowed($decision['status_id'], $toStatusId)) {
                $allowedIds[] = $decision['id'];
            } else {
                $refusedRefs[] = $decision['ref'];
            }
        }

        return ['allowed_ids' => $allowedIds, 'refused_refs' => $refusedRefs];
    }

    /**
     * Every status the bulk selector offers, each carrying the statuses an order
     * may be in for that target to be within reach. The list narrows itself in
     * the browser as rows are ticked; the server decides again on submit.
     *
     * @return list<array{id: int, title: string, from_status_ids: list<int>}>
     */
    public function targets(string $locale): array
    {
        $statuses = $this->statusRepository->findLocalized($locale);
        $targets = [];

        foreach ($statuses as $target) {
            $targetId = (int) $target->getId();
            $sourceIds = [];

            foreach ($statuses as $source) {
                $sourceId = (int) $source->getId();

                // An order already in the target status has nothing to move to.
                if ($sourceId !== $targetId && $this->transitionGuard->isAllowed($sourceId, $targetId)) {
                    $sourceIds[] = $sourceId;
                }
            }

            $targets[] = [
                'id' => $targetId,
                'title' => (string) $target->getTitle(),
                'from_status_ids' => $sourceIds,
            ];
        }

        return $targets;
    }
}
