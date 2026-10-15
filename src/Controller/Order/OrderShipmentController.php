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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Pdf\OrderPdfRenderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Shipping\DTO\ShipmentLine;
use Thelia\Domain\Shipping\Exception\ShipmentNotAllowedException;
use Thelia\Domain\Shipping\Service\OrderShipmentManager;
use Thelia\Log\Tlog;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderShipment;
use Thelia\Model\OrderShipmentQuery;
use Thelia\Tools\TokenProvider;

/**
 * The parcels of an order, composed from its sheet: open one from the lines still
 * to ship, hand it to the carrier, give it up, print its delivery note. Every write
 * goes through the core's shipment manager, which is what bounds the quantities
 * and keeps the order in step with its parcels.
 */
final class OrderShipmentController
{
    private const RESOURCE = AdminResources::ORDER;
    private const DETAIL_ROUTE = 'admin.order.update.view';
    private const LIST_ROUTE = 'admin.order.list';
    private const TAB = 'shipments';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly TokenProvider $tokens,
        private readonly OrderShipmentManager $shipments,
        private readonly OrderPdfRenderer $pdfRenderer,
        private readonly AdminLogger $adminLogger,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[Route('/admin/order/update/{order_id}/shipment', name: 'admin.order.shipment.create', methods: ['POST'], requirements: ['order_id' => '\d+'])]
    public function create(Request $request, int $order_id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $order = OrderQuery::create()->findPk($order_id);

        if ($order === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));

            $lines = [];
            foreach ((array) $request->request->all('line') as $orderProductId => $quantity) {
                $quantity = (float) str_replace(',', '.', trim((string) $quantity));

                if ($quantity > 0.0) {
                    $lines[] = new ShipmentLine((int) $orderProductId, $quantity);
                }
            }

            $carrierId = (int) $request->request->get('delivery_module_id', 0);
            $shipment = $this->shipments->open(
                $order,
                $lines,
                $carrierId > 0 ? $carrierId : null,
                (string) $request->request->get('delivery_ref', ''),
            );

            if ($request->request->getBoolean('ship')) {
                $shipment = $this->shipments->ship($shipment);
            }

            $this->adminLogger->log(
                self::RESOURCE,
                AccessManager::UPDATE,
                \sprintf('Shipment %s %s on order %s', (string) $shipment->getRef(), $shipment->isShipped() ? 'shipped' : 'opened', (string) $order->getRef()),
                $order_id,
            );
            $this->flash('success', $this->translator->trans(
                $shipment->isShipped() ? 'Shipment %ref% has left.' : 'Shipment %ref% opened as a draft.',
                ['%ref%' => (string) $shipment->getRef()],
            ));
        } catch (ShipmentNotAllowedException|TokenAuthenticationException $exception) {
            $this->flash('danger', $exception->getMessage());
        } catch (\Throwable $exception) {
            Tlog::getInstance()->error(\sprintf('Shipment creation failed on order %d: %s', $order_id, $exception->getMessage()));
            $this->flash('danger', $this->translator->trans('The shipment could not be saved. See the system log for the details.'));
        }

        return $this->backToOrder($order_id);
    }

    #[Route('/admin/order/shipment/{shipment_id}/ship', name: 'admin.order.shipment.ship', methods: ['POST'], requirements: ['shipment_id' => '\d+'])]
    public function ship(Request $request, int $shipment_id): Response
    {
        return $this->transition($request, $shipment_id, function (OrderShipment $shipment) use ($request): OrderShipment {
            $deliveryRef = $request->request->get('delivery_ref');

            return $this->shipments->ship($shipment, null === $deliveryRef ? null : (string) $deliveryRef);
        }, 'Shipment %ref% has left.', 'shipped');
    }

    #[Route('/admin/order/shipment/{shipment_id}/cancel', name: 'admin.order.shipment.cancel', methods: ['POST'], requirements: ['shipment_id' => '\d+'])]
    public function cancel(Request $request, int $shipment_id): Response
    {
        return $this->transition($request, $shipment_id, fn (OrderShipment $shipment): OrderShipment => $this->shipments->cancel($shipment), 'Shipment %ref% cancelled.', 'cancelled');
    }

    #[Route('/admin/order/pdf/shipment/{shipment_id}/{browser}', name: 'admin.order.pdf.shipment', methods: ['GET'], requirements: ['shipment_id' => '\d+', 'browser' => '\d+'])]
    public function pdf(int $shipment_id, int $browser): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $shipment = OrderShipmentQuery::create()->findPk($shipment_id);

        if ($shipment === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->pdfRenderer->render((int) $shipment->getOrderId(), OrderPdfRenderer::KIND_DELIVERY, 1 === $browser, $shipment_id);
    }

    /**
     * @param callable(OrderShipment): OrderShipment $gesture
     */
    private function transition(Request $request, int $shipmentId, callable $gesture, string $successMessage, string $logVerb): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $shipment = OrderShipmentQuery::create()->findPk($shipmentId);

        if ($shipment === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $orderId = (int) $shipment->getOrderId();

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
            $shipment = $gesture($shipment);

            $this->adminLogger->log(
                self::RESOURCE,
                AccessManager::UPDATE,
                \sprintf('Shipment %s %s on order %s', (string) $shipment->getRef(), $logVerb, (string) $shipment->getOrder()->getRef()),
                $orderId,
            );
            $this->flash('success', $this->translator->trans($successMessage, ['%ref%' => (string) $shipment->getRef()]));
        } catch (ShipmentNotAllowedException|TokenAuthenticationException $exception) {
            $this->flash('danger', $exception->getMessage());
        } catch (\Throwable $exception) {
            Tlog::getInstance()->error(\sprintf('Shipment %d could not be %s: %s', $shipmentId, $logVerb, $exception->getMessage()));
            $this->flash('danger', $this->translator->trans('The shipment could not be saved. See the system log for the details.'));
        }

        return $this->backToOrder($orderId);
    }

    private function backToOrder(int $orderId): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(self::DETAIL_ROUTE, ['order_id' => $orderId, 'tab' => self::TAB]));
    }

    private function flash(string $type, string $message): void
    {
        $session = $this->requestStack->getSession();

        if (method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
