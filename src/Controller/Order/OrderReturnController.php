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

namespace BackOfficeDefaultTwigBundle\Controller\Order;

use BackOfficeDefaultTwigBundle\Repository\OrderReturnRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnDetailPresenter;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnFilterPresenter;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnFilters;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnListRowPresenter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Event\OrderReturn\OrderReturnEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\OrderReturn\OrderReturnStateMachine;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\Order;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnLine;
use Thelia\Model\OrderReturnStatus;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The merchant side of the product returns: the queue of requests, the sheet of
 * one request with the actions of its cycle, and the reception of the parcel.
 *
 * Every route of this controller answers 404 while the shop has not turned the
 * feature on, exactly as the API of the core does: a shop that never enabled
 * returns has no return screen at all.
 */
final class OrderReturnController
{
    private const RESOURCE = AdminResources::ORDER_RETURN;
    private const LIST_ROUTE = 'admin.order-return.list';
    private const DETAIL_ROUTE = 'admin.order-return.detail';
    private const ORDER_DETAIL_ROUTE = 'admin.order.update.view';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/order-return/list.html.twig';
    private const DETAIL_TEMPLATE = '@BackOfficeDefaultTwig/order-return/detail.html.twig';
    private const RECEPTION_TEMPLATE = '@BackOfficeDefaultTwig/order-return/reception.html.twig';
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly ReturnEligibilityChecker $eligibility,
        private readonly OrderReturnStateMachine $stateMachine,
        private readonly OrderReturnRepository $returns,
        private readonly OrderReturnListRowPresenter $rowPresenter,
        private readonly OrderReturnFilterPresenter $filterPresenter,
        private readonly OrderReturnDetailPresenter $detailPresenter,
        private readonly OrderAccess $orderAccess,
    ) {
    }

    #[Route('/admin/returns', name: 'admin.order-return.list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $this->assertFeatureEnabled();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $locale = $request->getLocale();
        $filters = OrderReturnFilters::fromRequest($request);

        $paginated = $this->returns->findPaginated($filters, $page, self::PAGE_SIZE);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $this->rowPresenter->presentAll($paginated['rows'], $locale),
            'total' => $paginated['total'],
            'pages' => $paginated['lastPage'],
            'current_page' => min($page, $paginated['lastPage']),
            'filters' => $this->filterPresenter->present($filters, $locale),
            'query_params' => $filters->toQueryParams(),
            'status_counts' => $this->returns->findStatusesWithCounts($locale),
            'returns_export_id' => $this->returns->findReturnsExportId(),
        ]));
    }

    #[Route('/admin/return/{order_return_id}', name: 'admin.order-return.detail', methods: ['GET'], requirements: ['order_return_id' => '\d+'])]
    public function detail(Request $request, int $order_return_id): Response
    {
        $this->assertFeatureEnabled();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $orderReturn = $this->reachableReturn($order_return_id);

        if ($orderReturn === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $navigation = $this->returns->findPreviousNext($orderReturn);

        return new Response($this->twig->render(self::DETAIL_TEMPLATE, array_merge(
            $this->detailPresenter->present($orderReturn, $request->getLocale()),
            [
                'token' => $this->tokens->assignToken(),
                'reception_url' => $this->urls->generate('admin.order-return.reception.view', ['order_return_id' => $order_return_id]),
                'order_url' => $orderReturn->getOrderId() !== null
                    ? $this->urls->generate(self::ORDER_DETAIL_ROUTE, ['order_id' => (int) $orderReturn->getOrderId()])
                    : null,
                'prev_url' => $navigation['previous'] !== null
                    ? $this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => $navigation['previous']])
                    : null,
                'next_url' => $navigation['next'] !== null
                    ? $this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => $navigation['next']])
                    : null,
            ],
        )));
    }

    /**
     * One cycle action: the button carries the status it moves to, the core
     * refuses any move its state machine does not authorize.
     */
    #[Route('/admin/return/{order_return_id}/transition', name: 'admin.order-return.transition', methods: ['POST'], requirements: ['order_return_id' => '\d+'])]
    public function transition(Request $request, int $order_return_id): Response
    {
        $this->assertFeatureEnabled();

        $orderReturn = $this->reachableReturn($order_return_id);

        if ($orderReturn === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $event = new OrderReturnEvent($orderReturn);
        $event->setTargetStatusId((int) $request->request->get('status_id', 0));

        $refusalReason = trim((string) $request->request->get('refusal_reason', ''));
        if ($refusalReason !== '') {
            $event->setRefusalReason($refusalReason);
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::ORDER_RETURN_UPDATE_STATUS,
            actionLabel: 'Return status update',
            successRoute: self::DETAIL_ROUTE,
            successParameters: ['order_return_id' => $order_return_id],
            describeForLog: static fn (OrderReturnEvent $dispatched): array => [
                \sprintf(
                    'Return %s moved to status "%s"',
                    (string) $dispatched->getOrderReturn()->getRef(),
                    (string) $dispatched->getOrderReturn()->getStatusCode(),
                ),
                (int) $dispatched->getOrderReturn()->getId(),
            ],
        );
    }

    #[Route('/admin/return/{order_return_id}/reception', name: 'admin.order-return.reception.view', methods: ['GET'], requirements: ['order_return_id' => '\d+'])]
    public function receptionView(Request $request, int $order_return_id): Response
    {
        $this->assertFeatureEnabled();

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $orderReturn = $this->reachableReturn($order_return_id);

        if ($orderReturn === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        if (!$this->canReceive($orderReturn)) {
            return new RedirectResponse($this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => $order_return_id]));
        }

        return new Response($this->twig->render(self::RECEPTION_TEMPLATE, [
            'return' => $orderReturn,
            'lines' => $this->detailPresenter->presentLines($orderReturn),
            'restock' => $this->detailPresenter->restockDefault(),
            'conditions' => OrderReturnLine::CONDITIONS,
            'token' => $this->tokens->assignToken(),
            'detail_url' => $this->urls->generate(self::DETAIL_ROUTE, ['order_return_id' => $order_return_id]),
        ]));
    }

    #[Route('/admin/return/{order_return_id}/reception', name: 'admin.order-return.reception.save', methods: ['POST'], requirements: ['order_return_id' => '\d+'])]
    public function receptionSave(Request $request, int $order_return_id): Response
    {
        $this->assertFeatureEnabled();

        $orderReturn = $this->reachableReturn($order_return_id);

        if ($orderReturn === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        // Pointing the parcel and moving the return to "received" are one gesture:
        // the core writes the quantities, restocks and transitions in a single
        // transaction. Dispatching a status change on top would restock twice.
        $event = new OrderReturnEvent($orderReturn);
        $event->setReceivedLines($this->readReceptionInput($request));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::ORDER_RETURN_RECEIVE,
            actionLabel: 'Return reception',
            successRoute: self::DETAIL_ROUTE,
            successParameters: ['order_return_id' => $order_return_id],
            describeForLog: static fn (OrderReturnEvent $dispatched): array => [
                \sprintf('Return %s received', (string) $dispatched->getOrderReturn()->getRef()),
                (int) $dispatched->getOrderReturn()->getId(),
            ],
            renderError: fn (): RedirectResponse => new RedirectResponse(
                $this->urls->generate('admin.order-return.reception.view', ['order_return_id' => $order_return_id]),
            ),
        );
    }

    /**
     * A return the merchant opens himself, for the parcel announced by phone.
     */
    #[Route('/admin/order/{order_id}/return/open', name: 'admin.order-return.open', methods: ['POST'], requirements: ['order_id' => '\d+'])]
    public function openFromOrder(Request $request, int $order_id): Response
    {
        $this->assertFeatureEnabled();

        $order = $this->returns->findOrder($order_id);

        if ($order !== null) {
            $this->orderAccess->assertReaches($order);
        }

        if ($order === null) {
            return new RedirectResponse($this->urls->generate('admin.order.list'));
        }

        $event = new OrderReturnEvent($this->describeOpening($request, $order));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::ORDER_RETURN_CREATE,
            actionLabel: 'Return opening',
            successRoute: self::DETAIL_ROUTE,
            successParametersResolver: static fn (OrderReturnEvent $dispatched): array => [
                'order_return_id' => (int) $dispatched->getOrderReturn()->getId(),
            ],
            describeForLog: static fn (OrderReturnEvent $dispatched): array => [
                \sprintf(
                    'Return %s opened on order %s',
                    (string) $dispatched->getOrderReturn()->getRef(),
                    (string) $dispatched->getOrderReturn()->getOrder()->getRef(),
                ),
                (int) $dispatched->getOrderReturn()->getId(),
            ],
            renderError: fn (): RedirectResponse => new RedirectResponse(
                $this->urls->generate(self::ORDER_DETAIL_ROUTE, ['order_id' => $order_id]),
            ),
        );
    }

    /**
     * What the merchant filled in, as the unsaved return the creation event
     * expects: the core owns the customer, the status, the reference and every
     * amount, and refuses the whole thing rather than writing half of it.
     */
    private function describeOpening(Request $request, Order $order): OrderReturn
    {
        $reasonId = (int) $request->request->get('reason_id', 0);

        $orderReturn = (new OrderReturn())
            ->setOrder($order)
            ->setCustomer($order->getCustomer())
            ->setCreatedByAdmin(true)
            ->setCustomerComment(trim((string) $request->request->get('comment', '')))
            ->setIncludePostage((bool) $request->request->get('include_postage', false));

        if ($reasonId > 0) {
            $orderReturn->setOrderReturnReason($this->returns->findReason($reasonId));
        }

        foreach ($this->readOpeningQuantities($request) as $orderProductId => $quantity) {
            $orderProduct = $this->returns->findOrderProduct($orderProductId);

            if ($orderProduct === null) {
                continue;
            }

            $orderReturn->addOrderReturnLine(
                (new OrderReturnLine())
                    ->setOrderProduct($orderProduct)
                    ->setQuantity($quantity),
            );
        }

        return $orderReturn;
    }

    /**
     * @return array<int, array{quantity: float, condition: string, resellable: bool}>
     */
    private function readReceptionInput(Request $request): array
    {
        $quantities = $request->request->all('quantity_received');
        $resellables = $request->request->all('resellable');
        $conditions = $request->request->all('received_condition');

        $input = [];
        foreach ($quantities as $lineId => $quantity) {
            $id = (int) $lineId;

            if ($id <= 0) {
                continue;
            }

            $input[$id] = [
                'quantity' => (float) str_replace(',', '.', (string) $quantity),
                'condition' => \in_array($conditions[$lineId] ?? null, OrderReturnLine::CONDITIONS, true)
                    ? (string) $conditions[$lineId]
                    : OrderReturnLine::CONDITION_GOOD,
                'resellable' => (bool) ($resellables[$lineId] ?? false),
            ];
        }

        return $input;
    }

    /**
     * @return array<int, float>
     */
    private function readOpeningQuantities(Request $request): array
    {
        $quantities = [];
        foreach ($request->request->all('quantity') as $orderProductId => $quantity) {
            $id = (int) $orderProductId;

            if ($id <= 0) {
                continue;
            }

            $quantities[$id] = (float) str_replace(',', '.', (string) $quantity);
        }

        return $quantities;
    }

    /**
     * Whether the return may still move to "received" from where it stands.
     */
    private function canReceive(OrderReturn $orderReturn): bool
    {
        return $this->stateMachine->canTransition(
            $orderReturn->getOrderReturnStatus()->getEffectiveCode(),
            OrderReturnStatus::CODE_RECEIVED,
        );
    }

    /**
     * The screens follow the shop setting the API of the core follows: while the
     * feature is off, nothing of the returns exists for anybody.
     */
    /**
     * The return, null when it does not exist; one of an order of another sale unit is a 404.
     */
    private function reachableReturn(int $returnId): ?OrderReturn
    {
        $orderReturn = $this->returns->findById($returnId);

        if (null !== $orderReturn) {
            $this->orderAccess->assertReachesReturn($orderReturn);
        }

        return $orderReturn;
    }

    private function assertFeatureEnabled(): void
    {
        if (!$this->eligibility->isFeatureEnabled()) {
            throw new NotFoundHttpException();
        }
    }
}
