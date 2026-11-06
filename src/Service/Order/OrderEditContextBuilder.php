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

use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Order\Service\EditableOrderStatuses;
use Thelia\Domain\Order\Service\OrderEditor;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;

final readonly class OrderEditContextBuilder
{
    public function __construct(
        private AdminAccessChecker $access,
        private OrderRepository $orders,
        private ?OrderEditor $editor = null,
        private ?EditableOrderStatuses $editableStatuses = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->editor;
    }

    public function canEdit(Order $order): bool
    {
        return null !== $this->editor
            && null !== $this->editableStatuses
            && null === $this->access->check(AdminResources::ORDER_EDIT, [], AccessManager::UPDATE)
            && $this->editableStatuses->allowsEditing($order)
            && !$this->isInvoiced($order);
    }

    /**
     * Why an administrator entitled to edit orders is not offered the editor on this one:
     * `status` or `invoice`, null when it is offered or when nothing is worth saying (no
     * editor in the core, no right to edit).
     */
    public function unavailableReason(Order $order): ?string
    {
        if (null === $this->editor || null === $this->editableStatuses
            || null !== $this->access->check(AdminResources::ORDER_EDIT, [], AccessManager::UPDATE)) {
            return null;
        }

        if ($this->isInvoiced($order)) {
            return 'invoice';
        }

        return $this->editableStatuses->allowsEditing($order) ? null : 'status';
    }

    private function isInvoiced(Order $order): bool
    {
        return '' !== (string) $order->getInvoiceRef();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Order $order): array
    {
        if (!$this->canEdit($order)) {
            return ['can_edit_order' => false, 'edit_unavailable_reason' => $this->unavailableReason($order)];
        }

        return [
            'can_edit_order' => true,
            'edit_unavailable_reason' => null,
            ...$this->snapshot($order),
            'edit_state' => $this->editor->stateOf($order),
        ];
    }

    /**
     * @return array{edit_lines: list<array<string, mixed>>, edit_discount: string, edit_postage: string}
     */
    public function snapshot(Order $order): array
    {
        $products = $this->orders->findAllOrderProducts((int) $order->getId());
        $lineIds = array_map(static fn (OrderProduct $line): int => (int) $line->getId(), $products);
        $inUse = $this->orders->findOrderProductIdsInUse($lineIds);

        $lines = [];
        foreach ($products as $line) {
            $lineId = (int) $line->getId();
            $editable = $line->isProductLine() && !$line->isBundleLine() && !$line->isBundleComponentLine() && !isset($inUse[$lineId]);

            $lines[] = [
                'id' => $lineId,
                'ref' => (string) $line->getProductRef(),
                'title' => (string) $line->getTitle(),
                'quantity' => self::plain($line->getQuantity()),
                'price' => self::plain($line->getWasInPromo() ? $line->getPromoPrice() : $line->getPrice()),
                'editable' => $editable,
                'locked_reason' => $editable ? '' : (isset($inUse[$lineId]) ? 'in_use' : 'not_editable'),
            ];
        }

        return [
            'edit_lines' => $lines,
            'edit_discount' => self::plain($order->getDiscount()),
            'edit_postage' => self::plain($order->getUntaxedPostage()),
        ];
    }

    private static function plain(string|float|int|null $value): string
    {
        $formatted = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');

        return '' === $formatted ? '0' : $formatted;
    }
}
