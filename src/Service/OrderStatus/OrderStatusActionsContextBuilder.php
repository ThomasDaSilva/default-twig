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

use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderStatusActionRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderStatusRepository;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\OrderStatus;

/**
 * What the Actions tab of a status shows: its actions as table rows, the
 * choices of the "add an action" dialog, and the latest failures.
 */
final readonly class OrderStatusActionsContextBuilder
{
    public function __construct(
        private OrderStatusActionRepository $actionRepository,
        private OrderStatusRepository $statusRepository,
        private OrderRepository $orderRepository,
        private StockRules $stockRules,
        private OrderStatusActionPresenter $presenter,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(OrderStatus $status, string $locale, bool $canReadOrders = false, ?string $simulatedReference = null, ?int $simulatedStatusId = null): array
    {
        $statusId = (int) $status->getId();
        $statuses = $this->statusRepository->findLocalized($locale);
        $titles = array_map(static fn (OrderStatus $candidate): string => (string) $candidate->getTitle(), $statuses);

        $actions = $this->actionRepository->findForStatus($statusId);
        $failures = $this->actionRepository->countFailuresByAction(array_map(static fn ($action): int => (int) $action->getId(), $actions));
        $overridden = $this->stockRules->overriddenActions();
        $rows = [];

        foreach ($actions as $action) {
            $row = $this->presenter->row($action, $titles, $failures[(int) $action->getId()] ?? 0, $overridden);
            $row['toggle_url'] = $this->urls->generate('admin.order-status.actions.toggle', ['action_id' => $row['id']]);
            $row['_actions'] = [
                new RowAction(
                    kind: 'delete',
                    label: $this->translator->trans('Delete'),
                    modalTarget: '#order-status-action-delete-modal',
                    grantedAttribute: AccessManager::UPDATE,
                    grantedSubject: AdminResources::ORDER_STATUS,
                    dataAttributes: ['action-id' => $row['id'], 'action-label' => $row['type_label']],
                ),
            ];
            $rows[] = $row;
        }

        $fromChoices = [];
        foreach ($statuses as $candidate) {
            if ((int) $candidate->getId() !== $statusId) {
                $fromChoices[] = ['id' => (int) $candidate->getId(), 'title' => (string) $candidate->getTitle()];
            }
        }

        return [
            'action_rows' => $rows,
            'action_type_choices' => $this->presenter->typeChoices(),
            'action_fields_by_type' => $this->presenter->payloadFieldsByType(),
            'action_from_choices' => $fromChoices,
            'action_recent_failures' => $this->actionRepository->findRecentFailures($statusId),
            'actions_create_url' => $this->urls->generate('admin.order-status.actions.create', ['order_status_id' => $statusId]),
            'actions_move_url' => $this->urls->generate('admin.order-status.actions.move'),
            'stock_simulation' => $canReadOrders ? $this->simulation($statuses, $statusId, $simulatedReference, $simulatedStatusId) : null,
        ];
    }

    /**
     * The "what would the stock do" form: the order reference, the status to move it to
     * (this one by default) and, once asked, the answer or the reason there is none.
     * Nothing is written. Only built for an administrator who may read orders; null on a core that cannot simulate.
     *
     * @param array<int, OrderStatus> $statuses
     *
     * @return array<string, mixed>|null
     */
    private function simulation(array $statuses, int $statusId, ?string $reference, ?int $targetStatusId): ?array
    {
        if (!$this->stockRules->canSimulate()) {
            return null;
        }

        $targetStatusId ??= $statusId;
        $choices = [];

        foreach ($statuses as $candidate) {
            $choices[] = ['id' => (int) $candidate->getId(), 'title' => (string) $candidate->getTitle()];
        }

        $simulation = [
            'reference' => (string) $reference,
            'status_id' => $targetStatusId,
            'status_choices' => $choices,
            'asked' => null !== $reference,
            'error' => null,
            'result' => null,
        ];

        if (null === $reference) {
            return $simulation;
        }

        $order = '' === trim($reference) ? null : $this->orderRepository->findByReference(trim($reference));
        $target = $statuses[$targetStatusId] ?? null;

        if (null === $order) {
            $simulation['error'] = $this->translator->trans('No order has this reference.');

            return $simulation;
        }

        if (null === $target) {
            $simulation['error'] = $this->translator->trans('This status does not exist.');

            return $simulation;
        }

        $decision = $this->stockRules->simulate($order, $target);

        if (null === $decision) {
            return $simulation;
        }

        $simulation['result'] = [
            'order_ref' => (string) $order->getRef(),
            'status_title' => (string) $target->getTitle(),
            'operation' => match ($decision['operation']) {
                'increase' => $this->translator->trans('Stock put back'),
                'decrease' => $this->translator->trans('Stock taken out'),
                default => $this->translator->trans('Stock left as it is'),
            },
            'decided_by' => null === $decision['action_id']
                ? $this->translator->trans('Decided by the fixed rule of the core.')
                : $this->translator->trans('Decided by action #%action%.', ['%action%' => $decision['action_id']]),
        ];

        return $simulation;
    }
}
