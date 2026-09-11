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

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Order\Enum\OrderStatusActionTrigger;
use Thelia\Domain\Order\StatusAction\Effect\AdjustStockAction;
use Thelia\Domain\Order\StatusAction\Effect\AllocateInvoiceRefAction;
use Thelia\Domain\Order\StatusAction\Effect\ReleaseCouponsAction;
use Thelia\Domain\Order\StatusAction\Effect\SendCustomerEmailAction;
use Thelia\Domain\Order\StatusAction\Effect\SendShopManagersEmailAction;
use Thelia\Domain\Order\StatusAction\OrderStatusActionInterface;
use Thelia\Domain\Order\StatusAction\OrderStatusActionRegistry;
use Thelia\Model\OrderStatusAction;

/**
 * Words for the action engine: the label of each action type and the one-line
 * summary of a configured action, for the actions tab of a status.
 */
final readonly class OrderStatusActionPresenter
{
    public function __construct(
        private OrderStatusActionRegistry $registry,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, string> type => label, for the "add an action" select
     */
    public function typeChoices(): array
    {
        $choices = [];

        foreach ($this->registry->all() as $type => $action) {
            $choices[$type] = $this->typeLabel($type);
        }

        asort($choices);

        return $choices;
    }

    public function typeLabel(string $type): string
    {
        return match ($type) {
            SendCustomerEmailAction::getType() => $this->translator->trans('Send an e-mail to the customer'),
            SendShopManagersEmailAction::getType() => $this->translator->trans('Send an e-mail to the shop managers'),
            AdjustStockAction::getType() => $this->translator->trans('Adjust the stock'),
            AllocateInvoiceRefAction::getType() => $this->translator->trans('Number the invoice'),
            ReleaseCouponsAction::getType() => $this->translator->trans('Give the coupons back'),
            default => $type,
        };
    }

    /**
     * The fields of every installed type, for the "add an action" dialog.
     *
     * @return array<string, list<array{name: string, label: string, type: string, required: bool, choices: array<string, string>|null}>>
     */
    public function payloadFieldsByType(): array
    {
        $fields = [];

        foreach ($this->registry->all() as $type => $action) {
            $fields[$type] = array_map(
                fn ($field): array => [
                    'name' => $field->name,
                    'label' => $this->fieldLabel($field->label),
                    'type' => $field->type,
                    'required' => $field->required,
                    'choices' => null === $field->choices ? null : array_map(fn (string $label): string => $this->fieldLabel($label), $field->choices),
                ],
                $action->describePayload(),
            );
        }

        return $fields;
    }

    /**
     * @param array<int, string> $statusTitles localized titles by status id
     *
     * @return array<string, mixed>
     */
    public function row(OrderStatusAction $action, array $statusTitles, int $failures): array
    {
        $type = $action->getActionType();
        $installed = $this->registry->has($type);

        return [
            'id' => (int) $action->getId(),
            'position' => (int) $action->getPosition(),
            'trigger' => $this->triggerLabel($action, $statusTitles),
            'type' => $type,
            'type_label' => $installed ? $this->typeLabel($type) : $type,
            'installed' => $installed,
            'summary' => $this->summary($action),
            'active' => (bool) $action->getActive(),
            'failures' => $failures,
        ];
    }

    private function fieldLabel(string $label): string
    {
        return $this->translator->trans($label);
    }

    /**
     * @param array<int, string> $statusTitles
     */
    private function triggerLabel(OrderStatusAction $action, array $statusTitles): string
    {
        if (OrderStatusActionTrigger::TRANSITION->value !== $action->getTriggerType()) {
            return $this->translator->trans('On entering this status');
        }

        return $this->translator->trans('Coming from %status%', ['%status%' => $statusTitles[(int) $action->getFromStatusId()] ?? '#'.$action->getFromStatusId()]);
    }

    private function summary(OrderStatusAction $action): string
    {
        $service = $this->registry->get($action->getActionType());
        $payload = $action->getDecodedPayload();

        if (!$service instanceof OrderStatusActionInterface || [] === $payload) {
            return '';
        }

        $parts = [];

        foreach ($service->describePayload() as $field) {
            if (!\array_key_exists($field->name, $payload)) {
                continue;
            }

            $value = (string) $payload[$field->name];
            $parts[] = $this->fieldLabel($field->label).' : '.($field->choices[$value] ?? null ? $this->fieldLabel($field->choices[$value]) : $value);
        }

        return implode(', ', $parts);
    }
}
