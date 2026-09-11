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

namespace BackOfficeDefaultTwigBundle\Service\OrderStatus;

use Propel\Runtime\Propel;
use Thelia\Domain\Order\Enum\OrderStatusActionTrigger;
use Thelia\Domain\Order\Exception\InvalidOrderStatusActionPayloadException;
use Thelia\Domain\Order\StatusAction\OrderStatusActionRegistry;
use Thelia\Domain\Order\StatusAction\OrderStatusActionRunner;
use Thelia\Model\Map\OrderStatusActionTableMap;
use Thelia\Model\OrderStatusAction;
use Thelia\Model\OrderStatusActionQuery;

/**
 * Writes the automatic actions of a status. Every write forgets what the runner
 * read so far: the back office and a status change may share one process.
 */
final readonly class OrderStatusActionWriter
{
    public function __construct(
        private OrderStatusActionRegistry $registry,
        private OrderStatusActionRunner $runner,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws InvalidOrderStatusActionPayloadException when the payload does not fit the action type
     * @throws \InvalidArgumentException                when the type is unknown or the trigger is incomplete
     */
    public function create(int $toStatusId, OrderStatusActionTrigger $trigger, ?int $fromStatusId, string $type, array $payload): OrderStatusAction
    {
        $service = $this->registry->get($type)
            ?? throw new \InvalidArgumentException(\sprintf('Unknown action type "%s".', $type));

        if (OrderStatusActionTrigger::TRANSITION === $trigger && null === $fromStatusId) {
            throw new \InvalidArgumentException('A transition needs the status it starts from.');
        }

        $lastPosition = (int) (OrderStatusActionQuery::create()
            ->filterByToStatusId($toStatusId)
            ->orderByPosition('DESC')
            ->findOne()?->getPosition() ?? 0);

        $action = (new OrderStatusAction())
            ->setToStatusId($toStatusId)
            ->setTriggerType($trigger->value)
            ->setFromStatusId(OrderStatusActionTrigger::TRANSITION === $trigger ? $fromStatusId : null)
            ->setActionType($type)
            ->setDecodedPayload($service->normalizePayload($payload))
            ->setPosition($lastPosition + 1)
            ->setActive(true);
        $action->save();

        $this->runner->reset();

        return $action;
    }

    public function toggleActive(OrderStatusAction $action): void
    {
        $action->setActive(!$action->getActive())->save();
        $this->runner->reset();
    }

    public function delete(OrderStatusAction $action): void
    {
        $action->delete();
        $this->runner->reset();
    }

    /**
     * Moves an action to a 1-based position among the actions of its status and
     * renumbers the others, so positions stay dense.
     */
    public function moveTo(OrderStatusAction $action, int $position): void
    {
        $siblings = OrderStatusActionQuery::create()
            ->filterByToStatusId($action->getToStatusId())
            ->filterById($action->getId(), '!=')
            ->orderByPosition()
            ->orderById()
            ->find()
            ->getData();

        $position = max(1, min($position, \count($siblings) + 1));
        array_splice($siblings, $position - 1, 0, [$action]);

        // All or nothing: a renumbering that stops halfway would leave two actions
        // on the same position and an order of execution nobody chose.
        $connection = Propel::getConnection(OrderStatusActionTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            foreach (array_values($siblings) as $index => $sibling) {
                $sibling->setPosition($index + 1)->save($connection);
            }

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }

        $this->runner->reset();
    }
}
