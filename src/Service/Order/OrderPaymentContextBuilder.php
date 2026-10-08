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
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Payment\Service\PaymentAmount;
use Thelia\Domain\Payment\Service\PaymentCaptureService;
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
                'payment_supports_capture' => false,
                'payment_can_capture' => false,
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

        return [
            'payment_journal_enabled' => true,
            'payment_journal' => $this->presenter->presentAll($this->transactions->findJournalOfOrder($orderId), $locale),
            'payment_totals' => [
                'has_authorization' => $totals->hasAuthorization(),
                'authorized' => PaymentAmount::toFloat($totals->authorized),
                'captured' => PaymentAmount::toFloat($totals->captured),
                'voided' => PaymentAmount::toFloat($totals->voided),
                'refunded' => PaymentAmount::toFloat($totals->refunded),
                'remaining' => PaymentAmount::toFloat($totals->remainingToCapture),
            ],
            'payment_supports_capture' => $supportsCapture,
            'payment_can_capture' => $supportsCapture && $mayCapture && $totals->hasSomethingLeftToCapture(),
        ];
    }
}
