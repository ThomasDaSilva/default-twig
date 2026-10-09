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

use BackOfficeDefaultTwigBundle\Repository\OrderPaymentTransactionRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Payment\Service\CurrencyMinorUnit;
use Thelia\Domain\Payment\Service\PaymentAmount;
use Thelia\Domain\Payment\Enum\RefundReason;
use Thelia\Domain\Payment\Service\PaymentCaptureService;
use Thelia\Domain\Payment\Service\PaymentRefundService;
use Thelia\Domain\Payment\Service\PaymentTransactionTotalsReader;
use Thelia\Model\Order;

/**
 * Composes the payment journal part of the order sheet: the movements, what they add
 * up to, and whether this administrator may capture what the authorization still holds.
 *
 * Everything it returns is empty while the admin lacks the orders permission, the
 * same shape the history and returns blocks use, so the sheet shows no block rather
 * than failing.
 */
final readonly class OrderPaymentContextBuilder
{
    private const ADMIN_ROLE = 'ADMIN';

    public function __construct(
        private OrderPaymentTransactionRepository $transactions,
        private OrderPaymentLinePresenter $presenter,
        private PaymentTransactionTotalsReader $totalsReader,
        private PaymentCaptureService $captureService,
        private PaymentRefundService $refundService,
        #[Autowire(service: 'translator')]
        private TranslatorInterface $translator,
        private AdminAccessChecker $access,
        private SecurityContext $securityContext,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Order $order, string $locale): array
    {
        if (!$this->access->canView(AdminResources::ORDER)) {
            return [
                'payment_journal_enabled' => false,
                'payment_journal' => [],
                'payment_totals' => null,
                'payment_hold_notice' => false,
                'payment_supports_capture' => false,
                'payment_can_capture' => false,
                'payment_can_settle' => false,
                'payment_can_refund' => false,
                'payment_refund_mode' => 'offline',
                'payment_refund_reasons' => [],
                'payment_decimals' => 2,
                'payment_step' => '0.01',
            ];
        }

        $orderId = (int) $order->getId();
        $totals = $this->totalsReader->forOrder($orderId);
        $supportsCapture = $this->captureService->supportsCapture($order);

        // Taking money is a right of its own: an administrator who edits orders all day
        // does not thereby capture payments.
        $mayCapture = $this->securityContext->isGranted(
            [self::ADMIN_ROLE],
            [AdminResources::ORDER_PAYMENT_CAPTURE],
            [],
            [AccessManager::CREATE],
        );

        $mayRefund = $this->securityContext->isGranted(
            [self::ADMIN_ROLE],
            [AdminResources::ORDER_PAYMENT_REFUND],
            [],
            [AccessManager::CREATE],
        );

        $currencyCode = $order->getCurrency()->getCode();
        // What the dialog offers to give back: collected and not refunded yet, rounded down to
        // the smallest coin.
        $refundable = CurrencyMinorUnit::floor($totals->refundable(), $currencyCode);
        // Amounts read and typed in the smallest coin of the order currency: none for the
        // yen, three for the Kuwaiti dinar.
        $decimals = CurrencyMinorUnit::decimalsOf($currencyCode);

        return [
            'payment_journal_enabled' => true,
            'payment_journal' => $this->presenter->presentAll($this->transactions->findJournalOfOrder($orderId), $locale),
            'payment_totals' => [
                'has_authorization' => $totals->hasAuthorization(),
                'authorized' => PaymentAmount::toFloat($totals->authorized),
                'captured' => PaymentAmount::toFloat($totals->captured),
                'pending_capture' => PaymentAmount::toFloat($totals->pendingCapture),
                'voided' => PaymentAmount::toFloat($totals->voided),
                'refunded' => PaymentAmount::toFloat($totals->refunded),
                'remaining' => PaymentAmount::toFloat($totals->remainingToCapture),
                // Rounded down to the smallest coin: what the dialog offers never goes past
                // what the authorization holds, which the core would refuse.
                'capturable' => PaymentAmount::toFloat(CurrencyMinorUnit::floor($totals->remainingToCapture, $currencyCode)),
                'refundable' => PaymentAmount::toFloat($refundable),
            ],
            // Marking such an order paid by hand takes nothing from the buyer: the money
            // is taken by a capture, at the provider.
            'payment_hold_notice' => $totals->hasSomethingLeftToCapture() && !$order->isPaid(false),
            'payment_supports_capture' => $supportsCapture,
            'payment_can_capture' => $supportsCapture && $mayCapture && PaymentAmount::isPositive(CurrencyMinorUnit::floor($totals->remainingToCapture, $currencyCode)),
            // Recording by hand the outcome of a line the provider never confirmed is a
            // decision on the money too: the same right.
            'payment_can_settle' => $mayCapture,
            'payment_decimals' => $decimals,
            'payment_step' => $decimals === 0 ? '1' : '0.'.str_repeat('0', $decimals - 1).'1',
            // Giving money back is a right of its own; the module decides whether it goes
            // through the provider or is recorded as made outside it.
            'payment_can_refund' => $mayRefund && PaymentAmount::isPositive($refundable),
            'payment_refund_mode' => $this->refundService->supportsRefund($order) ? 'online' : 'offline',
            'payment_refund_reasons' => array_map(
                fn (RefundReason $reason): array => ['value' => $reason->value, 'label' => $this->translator->trans($reason->label(), [], null, $locale)],
                RefundReason::cases(),
            ),
        ];
    }
}
