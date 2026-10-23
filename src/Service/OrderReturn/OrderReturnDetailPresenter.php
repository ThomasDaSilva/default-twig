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

namespace BackOfficeDefaultTwigBundle\Service\OrderReturn;

use BackOfficeDefaultTwigBundle\Repository\OrderReturnRepository;
use BackOfficeDefaultTwigBundle\Service\I18n\CountryFlag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Action\OrderReturn as OrderReturnAction;
use Thelia\Domain\OrderReturn\OrderReturnStateMachine;
use Thelia\Domain\OrderReturn\Service\RefundAmountCalculator;
use Thelia\Model\ConfigQuery;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnStatus;

/**
 * Composes the return sheet: the requested lines with what each of them is
 * worth, the customer's own words, and the cycle actions the merchant may take
 * right now.
 *
 * The actions are read from the state machine, never from a list written here:
 * a status the shop added with an equivalence follows the transitions of the
 * canonical code it stands for, and a graph change in the core reaches the
 * screen without touching it.
 */
final readonly class OrderReturnDetailPresenter
{
    private const FALLBACK_STATUS_COLOR = '#6c757d';

    /**
     * How each reachable status is offered to the merchant: the wording of the
     * button, its Bootstrap variant and its icon. A status the shop added on top
     * of the canonical ones falls back to its own title and a neutral button.
     *
     * @var array<string, array{label: string, variant: string, icon: string}>
     */
    private const ACTION_STYLES = [
        OrderReturnStatus::CODE_ACCEPTED => ['label' => 'Accept the return', 'variant' => 'success', 'icon' => 'bi-check2-circle'],
        OrderReturnStatus::CODE_REFUSED => ['label' => 'Refuse the return', 'variant' => 'danger', 'icon' => 'bi-x-circle'],
        OrderReturnStatus::CODE_INFO_AWAITED => ['label' => 'Ask for more information', 'variant' => 'warning', 'icon' => 'bi-question-circle'],
        OrderReturnStatus::CODE_REQUESTED => ['label' => 'Put back in the queue', 'variant' => 'secondary', 'icon' => 'bi-arrow-counterclockwise'],
        OrderReturnStatus::CODE_RECEIVED => ['label' => 'Record the reception', 'variant' => 'primary', 'icon' => 'bi-box-arrow-in-down'],
        OrderReturnStatus::CODE_EXPIRED => ['label' => 'Mark as expired', 'variant' => 'outline-secondary', 'icon' => 'bi-hourglass-bottom'],
        OrderReturnStatus::CODE_SETTLED => ['label' => 'Mark as settled', 'variant' => 'success', 'icon' => 'bi-cash-coin'],
    ];

    public function __construct(
        private OrderReturnRepository $returns,
        private OrderReturnStateMachine $stateMachine,
        private RefundAmountCalculator $refundCalculator,
        private TranslatorInterface $translator,
        private OrderReturnResolutionLabels $resolutionLabels,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(OrderReturn $orderReturn, string $locale): array
    {
        // status_id is a required column behind a RESTRICT foreign key: a return
        // always carries its status.
        $status = $orderReturn->getOrderReturnStatus();
        $status->setLocale($locale);
        $currentCode = $status->getEffectiveCode();

        return [
            'return' => $orderReturn,
            'status_title' => (string) $status->getTitle(),
            'status_code' => $currentCode,
            'status_color' => (string) ($status->getColor() ?: self::FALLBACK_STATUS_COLOR),
            'is_terminal' => $this->stateMachine->isTerminal($currentCode),
            'lines' => $this->presentLines($orderReturn),
            'reason_title' => $this->reasonTitle($orderReturn, $locale),
            'actions' => $this->buildActions($currentCode, $locale),
            'computed_refund' => $this->refundCalculator->compute($orderReturn),
            'received_refund' => $this->refundCalculator->compute($orderReturn, true),
            'resolution_label' => $this->resolutionLabels->label((string) $orderReturn->getExpectedResolution()),
            'wished_exchange' => $this->resolutionLabels->wishedExchange($orderReturn),
            'customer_flag' => $this->customerFlag($orderReturn),
        ];
    }

    /**
     * @return list<array{
     *     id: int,
     *     title: string,
     *     ref: string,
     *     attributes: list<string>,
     *     quantity: float,
     *     quantity_received: float,
     *     resellable: bool,
     *     received_condition: ?string,
     *     unit_price: float,
     *     refund_amount: float
     * }>
     */
    public function presentLines(OrderReturn $orderReturn): array
    {
        $returnLines = $this->returns->findLines((int) $orderReturn->getId());
        $orderProductIds = [];
        foreach ($returnLines as $line) {
            $orderProductIds[] = (int) $line->getOrderProductId();
        }
        $attributes = $this->returns->findAttributeValues($orderProductIds);

        $lines = [];

        foreach ($returnLines as $line) {
            // order_product_id is a required column behind a RESTRICT foreign key.
            $orderProduct = $line->getOrderProduct();

            $lines[] = [
                'id' => (int) $line->getId(),
                'title' => (string) $orderProduct->getTitle(),
                // The reference of the ordered combination, as in the queue; the product's when it has none.
                'ref' => (string) ($orderProduct->getProductSaleElementsRef() ?: $orderProduct->getProductRef()),
                'attributes' => array_map(
                    fn (array $attribute): string => $this->translator->trans('%attribute%: %value%', ['%attribute%' => $attribute['attribute'], '%value%' => $attribute['value']]),
                    $attributes[(int) $orderProduct->getId()] ?? [],
                ),
                'quantity' => (float) $line->getQuantity(),
                'quantity_received' => (float) $line->getQuantityReceived(),
                'resellable' => (bool) $line->getResellable(),
                'received_condition' => $line->getReceivedCondition(),
                'unit_price' => $this->refundCalculator->unitTaxedPrice($orderProduct),
                'refund_amount' => (float) $line->getRefundAmount(),
            ];
        }

        return $lines;
    }

    /**
     * The cycle actions available from the current status, each carrying the id
     * of the status it moves to.
     *
     * @return list<array{code: string, status_id: int, label: string, variant: string, icon: string}>
     */
    public function buildActions(string $currentCode, string $locale): array
    {
        $statusesByCode = [];
        foreach ($this->returns->findStatusesLocalized($locale) as $status) {
            // A shop may hold several statuses standing for the same canonical code;
            // the first one in position order is the one the button moves to.
            $statusesByCode[$status['effective_code']] ??= $status;
        }

        $actions = [];
        foreach ($this->stateMachine->allowedTargets($currentCode) as $targetCode) {
            $status = $statusesByCode[$targetCode] ?? null;

            if ($status === null) {
                continue;
            }

            $style = self::ACTION_STYLES[$targetCode] ?? null;

            $actions[] = [
                'code' => $targetCode,
                'status_id' => $status['id'],
                'label' => $style !== null ? $this->translator->trans($style['label']) : $status['title'],
                'variant' => $style['variant'] ?? 'outline-primary',
                'icon' => $style['icon'] ?? 'bi-arrow-right-circle',
            ];
        }

        return $actions;
    }

    /**
     * The state the "put back in stock" box starts in, according to the shop
     * setting: always on when the shop restocks everything, always off when it
     * never restocks, and on by default when it follows the resellable flag —
     * the merchant unticks what came back damaged.
     *
     * @return array{checked: bool, editable: bool}
     */
    public function restockDefault(): array
    {
        $mode = (string) ConfigQuery::read(
            OrderReturnAction::RESTOCK_MODE_CONFIG_KEY,
            OrderReturnAction::RESTOCK_MODE_RESELLABLE,
        );

        return match ($mode) {
            OrderReturnAction::RESTOCK_MODE_AUTO => ['checked' => true, 'editable' => false],
            OrderReturnAction::RESTOCK_MODE_NEVER => ['checked' => false, 'editable' => false],
            default => ['checked' => true, 'editable' => true],
        };
    }

    /**
     * The flag of the delivery country of the order, before the customer name as in
     * the queue.
     */
    private function customerFlag(OrderReturn $orderReturn): string
    {
        // delivery_order_address_id and country_id are required columns behind foreign keys.
        return CountryFlag::prefix($orderReturn->getOrder()->getOrderAddressRelatedByDeliveryOrderAddressId()->getCountry()->getIsoalpha2());
    }

    private function reasonTitle(OrderReturn $orderReturn, string $locale): string
    {
        $reason = $orderReturn->getOrderReturnReason();

        if ($reason !== null) {
            $reason->setLocale($locale);
            $title = (string) $reason->getTitle();

            if ($title !== '') {
                return $title;
            }
        }

        // The reason may have been deleted since: the return keeps the label it
        // was opened with.
        return (string) $orderReturn->getReasonTitle();
    }
}
