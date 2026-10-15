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

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Order\Service\OrderTrackingUrlResolver;
use Thelia\Domain\Shipping\Service\ShippedQuantityCalculator;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderShipmentQuery;
use Thelia\Module\BaseModule;

/**
 * The shipments tab of the order sheet: the parcels the order left in, what each
 * line still has to ship, and what the merchant needs to compose the next parcel.
 *
 * Two queries for the whole order, the parcels with their lines and what is left per
 * line, however many parcels there are: nothing is read per line.
 */
final readonly class OrderShipmentContextBuilder
{
    public function __construct(
        private ShippedQuantityCalculator $quantities,
        private OrderTrackingUrlResolver $trackingUrlResolver,
        private SecurityContext $securityContext,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Order $order, string $locale): array
    {
        $orderId = (int) $order->getId();
        $titles = $this->lineTitles($orderId);
        $remaining = $this->quantities->remainingByOrderProduct($order);
        $shipped = [];
        $shipments = [];

        foreach (OrderShipmentQuery::create()->filterByOrderId($orderId)->orderById()->find() as $shipment) {
            $lines = [];

            foreach ($shipment->getOrderShipmentItems() as $item) {
                $lineId = (int) $item->getOrderProductId();
                $quantity = (float) $item->getQuantity();
                $lines[] = [
                    'order_product_id' => $lineId,
                    'title' => $titles[$lineId]['title'] ?? '',
                    'ref' => $titles[$lineId]['ref'] ?? '',
                    'quantity' => $quantity,
                ];

                if ($shipment->isShipped()) {
                    $shipped[$lineId] = ($shipped[$lineId] ?? 0.0) + $quantity;
                }
            }

            $shipmentId = (int) $shipment->getId();
            $shippedAt = $shipment->getShippedAt();
            $shipments[] = [
                'id' => $shipmentId,
                'ref' => (string) $shipment->getRef(),
                'status' => (string) $shipment->getStatus(),
                'is_draft' => $shipment->isDraft(),
                'carrier' => (string) $shipment->getDeliveryModuleTitle(),
                'delivery_ref' => (string) $shipment->getDeliveryRef(),
                'tracking_url' => $this->trackingUrlResolver->resolveShipment($shipment),
                'shipped_at' => $shippedAt instanceof \DateTimeInterface ? $shippedAt : null,
                'created_at' => $shipment->getCreatedAt(),
                'lines' => $lines,
                'pdf_url' => $this->urls->generate('admin.order.pdf.shipment', ['shipment_id' => $shipmentId, 'browser' => 1]),
                'pdf_download_url' => $this->urls->generate('admin.order.pdf.shipment', ['shipment_id' => $shipmentId, 'browser' => 0]),
            ];
        }

        $canShip = $this->securityContext->isGranted(['ADMIN'], [AdminResources::ORDER], [], [AccessManager::UPDATE]);
        $shippable = [];

        foreach ($remaining as $lineId => $left) {
            if ($left > 0.0) {
                $shippable[] = [
                    'order_product_id' => $lineId,
                    'title' => $titles[$lineId]['title'] ?? '',
                    'ref' => $titles[$lineId]['ref'] ?? '',
                    'remaining' => $left,
                ];
            }
        }

        return [
            'shipments' => $shipments,
            'shippable_lines' => $canShip ? $shippable : [],
            'shipped_by_line' => $shipped,
            'left_by_line' => $remaining,
            'can_ship' => $canShip,
            'carriers' => $canShip ? $this->carriers($locale) : [],
            'order_delivery_module_id' => (int) $order->getDeliveryModuleId(),
        ];
    }

    /**
     * @return array<int, array{title: string, ref: string}>
     */
    private function lineTitles(int $orderId): array
    {
        $titles = [];

        foreach (OrderProductQuery::create()->filterByOrderId($orderId)->find() as $line) {
            $titles[(int) $line->getId()] = [
                'title' => (string) $line->getTitle(),
                'ref' => (string) $line->getProductRef(),
            ];
        }

        return $titles;
    }

    /**
     * The carriers the merchant may hand a parcel to: the active delivery modules,
     * named in the administrator's language.
     *
     * @return list<array{id: int, title: string}>
     */
    private function carriers(string $locale): array
    {
        $carriers = [];

        $modules = ModuleQuery::create()
            ->filterByType(BaseModule::DELIVERY_MODULE_TYPE)
            ->filterByActivate(BaseModule::IS_ACTIVATED, Criteria::EQUAL)
            ->orderByPosition()
            ->find();

        /** @var Module $module */
        foreach ($modules as $module) {
            $module->setLocale($locale);
            $title = trim((string) $module->getTitle());
            $carriers[] = [
                'id' => (int) $module->getId(),
                'title' => '' === $title ? (string) $module->getCode() : $title,
            ];
        }

        return $carriers;
    }
}
