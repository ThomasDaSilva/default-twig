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

use BackOfficeDefaultTwigBundle\Repository\OrderStatusActionRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderStatusRepository;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\OrderStatus;
use Thelia\Tools\TokenProvider;

/**
 * What the Actions tab of a status shows: its actions as table rows, the
 * choices of the "add an action" dialog, and the latest failures.
 */
final readonly class OrderStatusActionsContextBuilder
{
    public function __construct(
        private OrderStatusActionRepository $actionRepository,
        private OrderStatusRepository $statusRepository,
        private OrderStatusActionPresenter $presenter,
        private UrlGeneratorInterface $urls,
        private TokenProvider $tokens,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(OrderStatus $status, string $locale): array
    {
        $statusId = (int) $status->getId();
        $statuses = $this->statusRepository->findLocalized($locale);
        $titles = array_map(static fn (OrderStatus $candidate): string => (string) $candidate->getTitle(), $statuses);

        $actions = $this->actionRepository->findForStatus($statusId);
        $failures = $this->actionRepository->countFailuresByAction(array_map(static fn ($action): int => (int) $action->getId(), $actions));
        $rows = [];

        foreach ($actions as $action) {
            $row = $this->presenter->row($action, $titles, $failures[(int) $action->getId()] ?? 0);
            $row['toggle_url'] = $this->urls->generate('admin.order-status.actions.toggle', ['action_id' => $row['id'], '_token' => $this->tokens->assignToken()]);
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
        ];
    }
}
