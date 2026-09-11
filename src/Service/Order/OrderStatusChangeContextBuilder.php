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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Order\Service\OrderStatusTransitionGuard;
use Thelia\Model\Order;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;

/**
 * The status controls of the order sheet: the statuses the graph lets the order
 * reach and, for an administrator entitled to it, the other statuses a forced
 * change may pick. Also words the refusal of a change the graph does not allow.
 */
final readonly class OrderStatusChangeContextBuilder
{
    public function __construct(
        private OrderStatusTransitionGuard $transitionGuard,
        private AdminAccessChecker $access,
        private OrderRepository $orderRepository,
        private OrderStatusRepository $statusRepository,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Order $order, string $locale): array
    {
        $currentId = (int) $order->getStatusId();
        $allowed = [];
        $forcedOnly = [];
        $allowedIds = array_map(static fn (OrderStatus $status): int => (int) $status->getId(), $this->transitionGuard->allowedTargets($currentId));

        foreach ($this->orderRepository->findStatusesLocalized($locale) as $status) {
            if ($status['id'] === $currentId) {
                continue;
            }

            if (\in_array($status['id'], $allowedIds, true)) {
                $allowed[] = $status;
            } else {
                $forcedOnly[] = $status;
            }
        }

        $cancelStatusId = (int) (OrderStatusQuery::getCancelledStatus()?->getId() ?? 0);
        $canForce = null === $this->access->check(AdminResources::ORDER_STATUS_FORCE, [], AccessManager::UPDATE);

        return [
            'allowed_statuses' => $allowed,
            'forced_only_statuses' => $canForce ? $forcedOnly : [],
            'status_is_free' => $this->transitionGuard->isFree($currentId),
            'can_force_status' => $canForce,
            'can_cancel' => $cancelStatusId > 0 && $this->transitionGuard->isAllowed($currentId, $cancelStatusId),
        ];
    }

    public function refusalMessage(Order $order, int $toStatusId, string $locale): string
    {
        $statuses = $this->statusRepository->findLocalized($locale);

        return $this->translator->trans('Order %ref% cannot go from "%from%" to "%to%": this transition is not allowed. Change the transitions of the status, or force the change if you are entitled to.', [
            '%ref%' => (string) $order->getRef(),
            '%from%' => isset($statuses[(int) $order->getStatusId()]) ? (string) $statuses[(int) $order->getStatusId()]->getTitle() : (string) $order->getStatusId(),
            '%to%' => isset($statuses[$toStatusId]) ? (string) $statuses[$toStatusId]->getTitle() : (string) $toStatusId,
        ]);
    }
}
