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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Domain\Order\Service\OrderTrackingUrlResolver;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderConsent;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderCoupon;
use Thelia\Model\OrderStatusQuery;

final readonly class OrderDetailContextBuilder
{
    private const FALLBACK_STATUS_COLOR = '#6c757d';

    /**
     * The tracking resolver is optional so the order page keeps working on a core that
     * predates the tracking link.
     */
    public function __construct(
        private UrlGeneratorInterface $urls,
        private ?OrderTrackingUrlResolver $trackingUrlResolver = null,
    ) {
    }

    public function build(Order $order, string $locale = 'en_US'): array
    {
        $currency = $order->getCurrency();

        // Tax breakdown per rule and total weight are promo-aware: when a line was
        // sold in promo, the promo tax amount applies (mirrors OrderController's
        // per-line rendering and the legacy back-office order-product loop).
        $weight = 0.0;
        $itemsTaxes = [];
        foreach ($order->getOrderProducts() as $orderProduct) {
            $quantity = (float) $orderProduct->getQuantity();
            $wasInPromo = (bool) $orderProduct->getWasInPromo();
            $weight += (float) $orderProduct->getWeight() * $quantity;

            $lineTax = 0.0;
            foreach ($orderProduct->getOrderProductTaxes() as $orderProductTax) {
                $lineTax += (float) ($wasInPromo ? $orderProductTax->getPromoAmount() : $orderProductTax->getAmount());
            }

            $ruleTitle = (string) ($orderProduct->getTaxRuleTitle() ?? '');
            if ($lineTax > 0 && $ruleTitle !== '') {
                // Totalled with the rule the order was invoiced with, so the per-rule
                // breakdown adds up to the tax total read from Order::getTotalAmount().
                $unitPriceUntaxed = (float) ($wasInPromo ? $orderProduct->getPromoPrice() : $orderProduct->getPrice());
                $lineTotals = OrderRoundingRule::forOrder((int) $orderProduct->getOrderId())
                    ->lineTotals($unitPriceUntaxed, $lineTax, $quantity);

                $itemsTaxes[$ruleTitle] = ($itemsTaxes[$ruleTitle] ?? 0.0) + $lineTotals['tax'];
            }
        }

        // The amounts themselves come from the canonical Order::getTotalAmount(),
        // the very same source used by the order list, the invoice PDF and the
        // legacy back-office. This guarantees promo-aware, identically rounded
        // figures across every screen (no manual re-computation that could drift).
        $tax = 0.0;
        $itemsTax = 0.0;
        $grandTotal = $order->getTotalAmount($tax);
        $itemsTtc = $order->getTotalAmount($itemsTax, false, false);
        $subtotalHt = $itemsTtc - $itemsTax;
        $subtotalTaxes = $itemsTax;

        $postageHt = (float) $order->getPostage();
        $postageTax = (float) $order->getPostageTax();
        $postageTaxRuleTitle = (string) ($order->getPostageTaxRuleTitle() ?? '');
        $discount = (float) $order->getDiscount();
        $discountTax = $this->estimateDiscountTax($discount, $subtotalHt, $subtotalTaxes);
        $totalTax = $tax;

        $coupons = [];
        foreach ($order->getOrderCoupons() as $orderCoupon) {
            \assert($orderCoupon instanceof OrderCoupon);
            $coupons[] = [
                'code' => (string) $orderCoupon->getCode(),
                'title' => (string) $orderCoupon->getTitle(),
                'amount' => (float) $orderCoupon->getAmount(),
            ];
        }

        $cancelledStatus = OrderStatusQuery::getCancelledStatus();
        $cancelStatusId = $cancelledStatus !== null ? (int) $cancelledStatus->getId() : 0;

        $customer = null;
        $customerModel = $order->getCustomer();
        if ($customerModel !== null) {
            $customer = [
                'id' => (int) $customerModel->getId(),
                'ref' => (string) $customerModel->getRef(),
                'firstname' => (string) $customerModel->getFirstname(),
                'lastname' => (string) $customerModel->getLastname(),
                'email' => (string) $customerModel->getEmail(),
                'edit_url' => $this->urls->generate('admin.customer.update.view', ['customer_id' => (int) $customerModel->getId()]),
            ];
        }

        $statusModel = OrderStatusQuery::create()->findPk((int) $order->getStatusId());
        $statusModel?->setLocale($locale);

        $invoiceDate = $order->getInvoiceDate();
        $invoiceDateString = $invoiceDate instanceof \DateTimeInterface ? $invoiceDate->format(\DATE_ATOM) : null;

        $consents = [];
        foreach ($order->getOrderConsents() as $orderConsent) {
            \assert($orderConsent instanceof OrderConsent);
            // The moment the box was answered, which precedes the order: created_at
            // here is the instant the proof was written down, inside the order
            // transaction, and saying "accepted on" about it would be a small lie.
            $answeredAt = $orderConsent->getAnsweredAt() ?? $orderConsent->getCreatedAt();
            $consents[] = [
                'title' => (string) $orderConsent->getTitle(),
                'description' => (string) $orderConsent->getDescription(),
                'accepted' => $orderConsent->isAccepted(),
                'answered_at' => $answeredAt instanceof \DateTimeInterface ? $answeredAt->format(\DATE_ATOM) : null,
            ];
        }

        // The service lines of the order, and the note the buyer left for whoever receives
        // the parcel. Read off the order rather than off the wrapping the shop offers today:
        // the merchant may have renamed, repriced or deleted it since, and the fiche has to
        // say what was sold.
        $serviceLines = [];
        foreach ($order->getOrderProducts() as $orderProduct) {
            \assert($orderProduct instanceof OrderProduct);

            if (!$orderProduct->isServiceLine()) {
                continue;
            }

            $serviceLines[] = [
                'title' => (string) $orderProduct->getTitle(),
                'description' => (string) $orderProduct->getDescription(),
                'tax_rule_title' => (string) $orderProduct->getTaxRuleTitle(),
                'price' => (float) $orderProduct->getPrice(),
            ];
        }

        return [
            'gift' => [
                'services' => $serviceLines,
                'message' => (string) $order->getGiftMessage(),
            ],
            'totals' => [
                'subtotal_ht' => $subtotalHt,
                'subtotal_taxes' => $subtotalTaxes,
                'subtotal_ttc' => $subtotalHt + $subtotalTaxes,
                'postage_ht' => $postageHt - $postageTax,
                'postage_tax' => $postageTax,
                'postage_ttc' => $postageHt,
                'postage_tax_rule_title' => $postageTaxRuleTitle,
                'discount' => $discount,
                'discount_tax' => $discountTax,
                'total_tax' => $totalTax,
                'grand_total' => $grandTotal,
                'items_taxes' => $itemsTaxes,
            ],
            'weight' => $weight,
            'customer' => $customer,
            // A deleted module leaves module_id at 0 and the title on the order, frozen when
            // the module went away.
            'payment' => [
                'module_id' => (int) $order->getPaymentModuleId(),
                'module_title' => $this->moduleTitle((int) $order->getPaymentModuleId(), $locale)
                    ?: (string) $order->getPaymentModuleTitle(),
                'transaction_ref' => (string) $order->getTransactionRef(),
                'invoice_ref' => (string) $order->getInvoiceRef(),
                'invoice_date' => $invoiceDateString,
                'is_paid' => $order->isPaid(false),
            ],
            'delivery' => [
                'module_id' => (int) $order->getDeliveryModuleId(),
                'module_title' => $this->moduleTitle((int) $order->getDeliveryModuleId(), $locale)
                    ?: (string) $order->getDeliveryModuleTitle(),
                'module_description' => $this->moduleDescription((int) $order->getDeliveryModuleId(), $locale),
                'delivery_ref' => (string) $order->getDeliveryRef(),
                'tracking_url' => $this->trackingUrlResolver?->resolve($order),
                // The day and the hours the buyer asked for, frozen on the order: a slot
                // edited or deleted afterwards does not change them.
                'delivery_date' => $order->getDeliveryDay(),
                'delivery_slot_start' => $order->getDeliverySlotStartsAt(),
                'delivery_slot_end' => $order->getDeliverySlotEndsAt(),
            ],
            'coupons' => $coupons,
            'consents' => $consents,
            'cancel_status_id' => $cancelStatusId,
            'is_canceled' => $cancelStatusId > 0 && (int) $order->getStatusId() === $cancelStatusId,
            'currency' => [
                'id' => $currency !== null ? (int) $currency->getId() : 0,
                'symbol' => $currency !== null ? (string) $currency->getSymbol() : '',
                'code' => $currency !== null ? (string) $currency->getCode() : '',
            ],
            'current_status' => $statusModel === null ? null : [
                'id' => (int) $statusModel->getId(),
                'code' => (string) $statusModel->getCode(),
                'title' => (string) $statusModel->getTitle(),
                'color' => (string) ($statusModel->getColor() ?: self::FALLBACK_STATUS_COLOR),
            ],
        ];
    }

    private function estimateDiscountTax(float $discount, float $subtotalHt, float $subtotalTaxes): float
    {
        if ($discount <= 0.0 || $subtotalHt + $subtotalTaxes <= 0.0) {
            return 0.0;
        }

        $taxedTotal = $subtotalHt + $subtotalTaxes;

        return round($discount * ($subtotalTaxes / $taxedTotal), 6);
    }

    private function moduleTitle(int $moduleId, string $locale): string
    {
        if ($moduleId === 0) {
            return '';
        }
        $module = ModuleQuery::create()->findPk($moduleId);
        if ($module === null) {
            return '';
        }
        $module->setLocale($locale);

        return (string) $module->getTitle();
    }

    private function moduleDescription(int $moduleId, string $locale): string
    {
        if ($moduleId === 0) {
            return '';
        }
        $module = ModuleQuery::create()->findPk($moduleId);
        if ($module === null) {
            return '';
        }
        $module->setLocale($locale);

        return (string) $module->getDescription();
    }
}
