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

use BackOfficeDefaultTwigBundle\Repository\AdminLogRepository;
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
 * change may pick, plus the forced changes the order has already received. Also
 * words the refusal of a change the graph does not allow.
 */
final readonly class OrderStatusChangeContextBuilder
{
    public function __construct(
        private OrderStatusTransitionGuard $transitionGuard,
        private AdminAccessChecker $access,
        private OrderStatusRepository $statusRepository,
        private AdminLogRepository $adminLogRepository,
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

        // The one memoised read of the statuses: the forced changes below and the
        // refusal message share it.
        foreach ($this->statusRepository->findLocalized($locale) as $statusId => $status) {
            if ($statusId === $currentId) {
                continue;
            }

            if (\in_array($statusId, $allowedIds, true)) {
                $allowed[] = $status;
            } else {
                $forcedOnly[] = $status;
            }
        }

        $cancelStatusId = (int) (OrderStatusQuery::getCancelledStatus()?->getId() ?? 0);
        $canForce = null === $this->access->check(AdminResources::ORDER_STATUS_FORCE, [], AccessManager::UPDATE);
        $forced = $this->forcedStatusChanges($order, $locale);

        return [
            'allowed_statuses' => $allowed,
            'forced_only_statuses' => $canForce ? $forcedOnly : [],
            'status_is_free' => $this->transitionGuard->isFree($currentId),
            'can_force_status' => $canForce,
            'can_cancel' => $cancelStatusId > 0 && $this->transitionGuard->isAllowed($currentId, $cancelStatusId),
            'forced_status_changes' => $forced['changes'],
            'forced_status_changes_hidden' => $forced['hidden'],
        ];
    }

    /**
     * The overrides this order went through, worded with the status titles of the
     * interface rather than the codes the administration log stores.
     *
     * That an order was forced belongs to whoever reads the order; who forced it
     * belongs to the administration log, and is only named to an administrator
     * entitled to read that log.
     *
     * @return array{changes: list<array{created_at: ?\DateTimeInterface, admin: string, from: string, to: string}>, hidden: int}
     */
    private function forcedStatusChanges(Order $order, string $locale): array
    {
        $titlesByCode = [];
        foreach ($this->statusRepository->findLocalized($locale) as $status) {
            $titlesByCode[(string) $status->getCode()] = (string) $status->getTitle();
        }

        $canReadAdminLog = $this->access->canView(AdminResources::ADMIN_LOG);
        $forced = $this->adminLogRepository->findForcedOrderStatusChanges((int) $order->getId());

        $changes = [];
        foreach ($forced['changes'] as $change) {
            $changes[] = [
                'created_at' => $change['created_at'],
                'admin' => $canReadAdminLog ? $change['admin'] : '',
                // A status deleted since keeps its code on screen: it is all that is left of it.
                'from' => $titlesByCode[$change['from_code']] ?? $change['from_code'],
                'to' => $titlesByCode[$change['to_code']] ?? $change['to_code'],
            ];
        }

        return ['changes' => $changes, 'hidden' => $forced['hidden']];
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
