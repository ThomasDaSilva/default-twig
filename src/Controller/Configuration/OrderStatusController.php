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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration;

use BackOfficeDefaultTwigBundle\Form\Order\OrderStatusType;
use BackOfficeDefaultTwigBundle\Repository\OrderStatusActionRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\Service\OrderStatus\OrderStatusActionPresenter;
use BackOfficeDefaultTwigBundle\Service\OrderStatus\OrderStatusActionWriter;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\OrderStatus\OrderStatusCreateEvent;
use Thelia\Core\Event\OrderStatus\OrderStatusDeleteEvent;
use Thelia\Core\Event\OrderStatus\OrderStatusUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Order\Enum\OrderStatusActionTrigger;
use Thelia\Domain\Order\Exception\InvalidOrderStatusActionPayloadException;
use Thelia\Domain\Order\Service\OrderStatusCatalog;
use Thelia\Domain\Order\Service\OrderStatusTransitionGuard;
use Thelia\Domain\Order\Service\OrderStatusTransitionWriter;
use Thelia\Model\LangQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin/configuration/order-status', name: 'admin.order-status.')]
final class OrderStatusController
{
    private const RESOURCE = AdminResources::ORDER_STATUS;
    private const LIST_ROUTE = 'admin.order-status.default';
    private const EDIT_ROUTE = 'admin.order-status.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/order-status/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/order-status/edit.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly EditLocaleResolver $editLocale,
        private readonly RequestStack $requestStack,
        private readonly AdminLogger $adminLogger,
        private readonly OrderStatusCatalog $statusCatalog,
        private readonly OrderStatusTransitionGuard $transitionGuard,
        private readonly OrderStatusTransitionWriter $transitionWriter,
        private readonly OrderStatusActionRepository $actionRepository,
        private readonly OrderStatusActionWriter $actionWriter,
        private readonly OrderStatusActionPresenter $actionPresenter,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, $this->buildListContext($request)));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_order_status_creation', OrderStatusType::class, [
            'locale' => $request->getLocale(),
        ], [
            'include_equivalent_code' => true,
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::ORDER_STATUS_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Order status creation',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
        );
    }

    #[Route('/update/{order_status_id}', name: 'update', methods: ['GET'], requirements: ['order_status_id' => '\d+'])]
    public function updateView(int $order_status_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $status = OrderStatusQuery::create()->findPk($order_status_id);
        if ($status === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $status->setLocale($locale);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'status' => $status,
            'form' => $this->buildUpdateForm($status, $locale)->createView(),
            'edit_language_id' => (int) $editLang->getId(),
            'current_tab' => \in_array($request->query->get('tab'), ['general', 'transitions', 'actions', 'modules'], true) ? $request->query->get('tab') : 'general',
            'token' => $this->tokens->assignToken(),
            ...$this->buildTransitionsContext($status, $locale),
            ...$this->buildActionsContext($status, $locale),
        ]));
    }

    #[Route('/transitions/{order_status_id}', name: 'transitions.save', methods: ['POST'], requirements: ['order_status_id' => '\d+'])]
    public function saveTransitions(int $order_status_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $status = OrderStatusQuery::create()->findPk($order_status_id);
        if ($status === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $redirect = new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['order_status_id' => $order_status_id, 'tab' => 'transitions']));

        if (!$this->tokens->checkToken((string) $request->request->get('_token', ''))) {
            $this->flash('danger', $this->translator->trans('Invalid security token, please try again.'));

            return $redirect;
        }

        $targetIds = array_values(array_filter(array_map('intval', (array) $request->request->all('to_status_ids')), static fn (int $id): bool => $id > 0));
        $this->transitionWriter->replaceTargets($order_status_id, $targetIds);

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Transitions of order status %s set to [%s]', $status->getCode(), implode(', ', $targetIds)), $order_status_id);
        $this->flash('success', [] === $targetIds
            ? $this->translator->trans('This status is free again: an order in this status may move to any other status.')
            : $this->translator->trans('The allowed transitions have been saved.'));

        return $redirect;
    }

    #[Route('/actions/{order_status_id}/create', name: 'actions.create', methods: ['POST'], requirements: ['order_status_id' => '\d+'])]
    public function createAction(int $order_status_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $status = OrderStatusQuery::create()->findPk($order_status_id);
        if ($status === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $redirect = new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['order_status_id' => $order_status_id, 'tab' => 'actions']));

        if (!$this->tokens->checkToken((string) $request->request->get('_token', ''))) {
            $this->flash('danger', $this->translator->trans('Invalid security token, please try again.'));

            return $redirect;
        }

        $type = (string) $request->request->get('action_type', '');
        $payloads = $request->request->all('payload');
        $payload = \is_array($payloads[$type] ?? null) ? $payloads[$type] : [];
        $fromStatusId = (int) $request->request->get('from_status_id', 0);

        try {
            $trigger = OrderStatusActionTrigger::from((string) $request->request->get('trigger', OrderStatusActionTrigger::ENTER->value));
            $action = $this->actionWriter->create($order_status_id, $trigger, $fromStatusId > 0 ? $fromStatusId : null, $type, $payload);
        } catch (InvalidOrderStatusActionPayloadException|\InvalidArgumentException|\ValueError $exception) {
            $this->flash('danger', $exception->getMessage());

            return $redirect;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Action %s #%d added to order status %s', $type, $action->getId(), $status->getCode()), $order_status_id);
        $this->flash('success', $this->translator->trans('The action has been added.'));

        return $redirect;
    }

    #[Route('/actions/{action_id}/toggle', name: 'actions.toggle', methods: ['GET', 'POST'], requirements: ['action_id' => '\d+'])]
    public function toggleAction(int $action_id, Request $request): Response
    {
        return $this->actionWrite($action_id, $request, function (\Thelia\Model\OrderStatusAction $action): void {
            $this->actionWriter->toggleActive($action);
        }, 'Action %1$s #%2$d switched %4$s on order status %3$s');
    }

    #[Route('/actions/delete', name: 'actions.delete', methods: ['POST', 'GET'])]
    public function deleteAction(Request $request): Response
    {
        $actionId = (int) ($request->request->get('action_id') ?? $request->query->get('action_id', 0));

        return $this->actionWrite($actionId, $request, function (\Thelia\Model\OrderStatusAction $action): void {
            $this->actionWriter->delete($action);
        }, 'Action %1$s #%2$d removed from order status %3$s');
    }

    #[Route('/actions/move', name: 'actions.move', methods: ['GET', 'POST'])]
    public function moveAction(Request $request): Response
    {
        $actionId = (int) ($request->query->get('action_id') ?? $request->request->get('action_id', 0));
        $position = (int) ($request->query->get('position') ?? $request->request->get('position', 0));

        return $this->actionWrite($actionId, $request, function (\Thelia\Model\OrderStatusAction $action) use ($position): void {
            $this->actionWriter->moveTo($action, $position);
        }, 'Action %1$s #%2$d moved to position '.$position.' on order status %3$s');
    }

    /**
     * The shared road of the token-protected writes on one action: right, token,
     * write, admin log, back to the actions tab of its status.
     *
     * @param callable(\Thelia\Model\OrderStatusAction): void $write
     */
    private function actionWrite(int $actionId, Request $request, callable $write, string $logFormat): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $action = $this->actionRepository->find($actionId);
        if ($action === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $statusId = (int) $action->getToStatusId();
        $redirect = new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['order_status_id' => $statusId, 'tab' => 'actions']));

        if (!$this->tokens->checkToken((string) ($request->query->get('_token') ?? $request->request->get('_token', '')))) {
            $this->flash('danger', $this->translator->trans('Invalid security token, please try again.'));

            return $redirect;
        }

        $write($action);

        $this->adminLogger->log(
            self::RESOURCE,
            AccessManager::UPDATE,
            \sprintf($logFormat, $action->getActionType(), $actionId, $action->getToStatus()->getCode(), $action->getActive() ? 'on' : 'off'),
            $statusId,
        );

        return $redirect;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTransitionsContext(OrderStatus $status, string $locale): array
    {
        $statusId = (int) $status->getId();
        $targets = [];
        $allowedIds = array_map(static fn (OrderStatus $target): int => (int) $target->getId(), $this->transitionGuard->allowedTargets($statusId));
        $isFree = $this->transitionGuard->isFree($statusId);

        foreach ($this->statusCatalog->all() as $candidate) {
            if ((int) $candidate->getId() === $statusId) {
                continue;
            }

            $candidate->setLocale($locale);
            $targets[] = [
                'id' => (int) $candidate->getId(),
                'title' => (string) $candidate->getTitle(),
                'code' => (string) $candidate->getCode(),
                'color' => (string) ($candidate->getColor() ?: '#6c757d'),
                // A free status shows nothing checked: it reaches everything by default.
                'checked' => !$isFree && \in_array((int) $candidate->getId(), $allowedIds, true),
            ];
        }

        $unreachable = [];
        foreach ($this->transitionGuard->unreachableStatuses() as $lost) {
            $lost->setLocale($locale);
            $unreachable[] = (string) $lost->getTitle();
        }

        return [
            'transition_targets' => $targets,
            'transitions_free' => $isFree,
            'unreachable_statuses' => $unreachable,
            'transitions_save_url' => $this->urls->generate('admin.order-status.transitions.save', ['order_status_id' => $statusId]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildActionsContext(OrderStatus $status, string $locale): array
    {
        $statusId = (int) $status->getId();
        $statuses = $this->statusCatalog->all();
        foreach ($statuses as $candidate) {
            $candidate->setLocale($locale);
        }

        $actions = $this->actionRepository->findForStatus($statusId);
        $failures = $this->actionRepository->countFailuresByAction(array_map(static fn ($action): int => (int) $action->getId(), $actions));
        $rows = [];

        foreach ($actions as $action) {
            $row = $this->actionPresenter->row($action, $statuses, $failures[(int) $action->getId()] ?? 0);
            $row['toggle_url'] = $this->urls->generate('admin.order-status.actions.toggle', ['action_id' => $row['id'], '_token' => $this->tokens->assignToken()]);
            $row['_actions'] = [
                new RowAction(
                    kind: 'delete',
                    label: $this->translator->trans('Delete'),
                    modalTarget: '#order-status-action-delete-modal',
                    grantedAttribute: AccessManager::UPDATE,
                    grantedSubject: self::RESOURCE,
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
            'action_type_choices' => $this->actionPresenter->typeChoices(),
            'action_fields_by_type' => $this->actionPresenter->payloadFieldsByType(),
            'action_from_choices' => $fromChoices,
            'action_recent_failures' => $this->actionRepository->findRecentFailures($statusId),
            'actions_create_url' => $this->urls->generate('admin.order-status.actions.create', ['order_status_id' => $statusId]),
            'actions_move_url' => $this->urls->generate('admin.order-status.actions.move'),
        ];
    }

    private function flash(string $type, string $message): void
    {
        $session = $this->requestStack->getSession();
        if (method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    #[Route('/save/{order_status_id}', name: 'save', methods: ['POST'], requirements: ['order_status_id' => '\d+'])]
    public function processUpdate(int $order_status_id): Response
    {
        $form = $this->formFactory->createNamed('thelia_order_status_modification', OrderStatusType::class, null, [
            'include_id' => true,
            'include_description' => true,
            'include_equivalent_code' => true,
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::ORDER_STATUS_UPDATE,
            eventFactory: $this->updateEvent(...),
            actionLabel: 'Order status update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['order_status_id' => $order_status_id],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['order_status_id' => $order_status_id])),
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST', 'GET'])]
    public function delete(Request $request): Response
    {
        $statusId = (int) ($request->query->get('order_status_id') ?? $request->request->get('order_status_id', 0));
        $event = new OrderStatusDeleteEvent($statusId);

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::ORDER_STATUS_DELETE,
            actionLabel: 'Order status deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/update-position', name: 'update-position', methods: ['GET', 'POST'])]
    public function updatePosition(Request $request): Response
    {
        $event = new UpdatePositionEvent(
            (int) ($request->query->get('order_status_id') ?? $request->request->get('order_status_id', 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::ORDER_STATUS_UPDATE_POSITION,
            actionLabel: 'Order status reorder',
            successRoute: self::LIST_ROUTE,
        );
    }

    private function createEvent(FormInterface $validated): OrderStatusCreateEvent
    {
        $data = $validated->getData() ?? [];

        $event = new OrderStatusCreateEvent();
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setCode((string) ($data['code'] ?? ''))
            ->setEquivalentCode($this->equivalentCode($data))
            ->setColor((string) ($data['color'] ?? '#000000'));

        return $event;
    }

    private function updateEvent(FormInterface $validated): OrderStatusUpdateEvent
    {
        $data = $validated->getData() ?? [];

        $event = new OrderStatusUpdateEvent((int) ($data['id'] ?? 0));
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setCode((string) ($data['code'] ?? ''))
            ->setEquivalentCode($this->equivalentCode($data))
            ->setColor((string) ($data['color'] ?? '#000000'))
            ->setChapo((string) ($data['chapo'] ?? ''))
            ->setDescription((string) ($data['description'] ?? ''))
            ->setPostscriptum((string) ($data['postscriptum'] ?? ''));

        return $event;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function equivalentCode(array $data): ?string
    {
        $equivalentCode = (string) ($data['equivalent_code'] ?? '');

        return $equivalentCode === '' ? null : $equivalentCode;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildListContext(Request $request): array
    {
        $locale = $request->getLocale();
        $sort = ListSort::fromRequest($request, ['id', 'code', 'title', 'position'], 'position');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = OrderStatusQuery::create();
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            'code' => $query->orderByCode($criteria),
            'title' => $query->useOrderStatusI18nQuery(null, Criteria::LEFT_JOIN)->filterByLocale($locale)->orderByTitle($criteria)->endUse(),
            default => $query->orderByPosition($criteria),
        };
        $statuses = $query->find();
        $rows = [];
        foreach ($statuses as $status) {
            \assert($status instanceof OrderStatus);
            $status->setLocale($locale);
            $rows[] = $this->statusToRow($status);
        }

        $createForm = $this->formFactory->createNamed('thelia_order_status_creation', OrderStatusType::class, [
            'locale' => $locale,
        ], [
            'include_equivalent_code' => true,
        ]);

        $unreachable = [];
        foreach ($this->transitionGuard->unreachableStatuses() as $lost) {
            $lost->setLocale($locale);
            $unreachable[] = (string) $lost->getTitle();
        }

        return [
            'rows' => $rows,
            'unreachable_statuses' => $unreachable,
            'create_form' => $createForm->createView(),
            'update_position_url' => $this->urls->generate('admin.order-status.update-position'),
            'update_position_token' => $this->tokens->assignToken(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statusToRow(OrderStatus $status): array
    {
        $id = (int) $status->getId();
        $protected = (bool) $status->getProtectedStatus();

        $actions = [
            new RowAction(
                kind: 'edit',
                label: $this->translator->trans('Edit'),
                href: $this->urls->generate(self::EDIT_ROUTE, ['order_status_id' => $id]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: self::RESOURCE,
            ),
        ];

        if (!$protected) {
            $actions[] = new RowAction(
                kind: 'delete',
                label: $this->translator->trans('Delete'),
                modalTarget: '#order-status-delete-modal',
                grantedAttribute: AccessManager::DELETE,
                grantedSubject: self::RESOURCE,
                dataAttributes: ['order-status-id' => $id, 'order-status-label' => (string) $status->getTitle()],
            );
        }

        $color = (string) $status->getColor();
        $ordersCount = OrderQuery::create()->filterByStatusId($id)->count();
        $ordersUrl = $this->urls->generate('admin.order.list', ['status_ids' => [$id]]);

        return [
            'id' => $id,
            'title' => (string) $status->getTitle(),
            'code' => (string) $status->getCode(),
            'color' => $color,
            'color_html' => $this->renderColorPill($color),
            'orders_html' => \sprintf(
                '<a href="%s" class="badge bg-light text-dark text-decoration-none border">%d</a>',
                htmlspecialchars($ordersUrl, \ENT_QUOTES | \ENT_HTML5),
                $ordersCount,
            ),
            'position' => (int) $status->getPosition(),
            '_actions' => $actions,
        ];
    }

    private function renderColorPill(string $color): string
    {
        if (preg_match('/^#[0-9a-fA-F]{3,6}$/', $color) !== 1) {
            return htmlspecialchars($color, \ENT_QUOTES | \ENT_HTML5);
        }

        return \sprintf(
            '<span class="d-inline-block rounded-circle border align-middle me-1" style="width:1rem;height:1rem;background-color:%1$s"></span><code>%1$s</code>',
            htmlspecialchars($color, \ENT_QUOTES | \ENT_HTML5),
        );
    }

    private function buildUpdateForm(OrderStatus $status, string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_order_status_modification', OrderStatusType::class, [
            'id' => $status->getId(),
            'locale' => $locale,
            'title' => $status->getTitle(),
            'code' => $status->getCode(),
            'equivalent_code' => $status->getEquivalentCode(),
            'color' => $status->getColor(),
            'chapo' => $status->getChapo(),
            'description' => $status->getDescription(),
            'postscriptum' => $status->getPostscriptum(),
        ], [
            'include_id' => true,
            'include_description' => true,
            // A protected status always stands for itself: no equivalence to pick.
            'include_equivalent_code' => !$status->getProtectedStatus(),
        ]);
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
