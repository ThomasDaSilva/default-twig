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

use BackOfficeDefaultTwigBundle\Repository\OrderHistoryRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Order\Enum\OrderHistoryActorType;
use Thelia\Domain\Order\Enum\OrderHistoryEventType;
use Thelia\Model\OrderHistory;

/**
 * Turns one row of the order history into the line the merchant reads: an icon, a
 * sentence in the interface language, the author in clear, and the free text.
 *
 * The event type is a plain string in the database precisely so that a module can
 * follow an order through steps the core knows nothing about. Such a row is not an
 * error to swallow: it gets the generic icon and shows its own code, which is the
 * only honest thing to display for an event whose meaning lives in another package.
 */
final readonly class OrderHistoryEntryPresenter
{
    /** @var array<string, string> */
    private const ICONS = [
        'order_created' => 'bi-cart-check',
        'status_changed' => 'bi-arrow-left-right',
        'address_updated' => 'bi-geo-alt',
        'delivery_ref_updated' => 'bi-truck',
        'transaction_ref_updated' => 'bi-credit-card',
        'invoice_ref_allocated' => 'bi-receipt',
        'email_sent' => 'bi-envelope',
        'note' => 'bi-chat-left-text',
        'return_opened' => 'bi-arrow-return-left',
        'return_status_changed' => 'bi-arrow-repeat',
        'return_received' => 'bi-box-arrow-in-down',
        'order_edited' => 'bi-pencil-square',
    ];

    private const FALLBACK_ICON = 'bi-record-circle';

    private const DOMAIN = 'messages';

    public function __construct(
        // The catalogue the back-office templates read is registered on the Symfony
        // translator; the interface the core aliases points at Thelia's own translator,
        // which never loaded it. Asking for the service by name is what gives a PHP
        // service the very strings its templates use.
        #[Autowire(service: 'translator')]
        private TranslatorInterface $translator,
        private OrderHistoryRepository $history,
    ) {
    }

    /**
     * @param iterable<OrderHistory> $entries
     * @param int|null               $editableByAdminId the admin whose own notes may still be rewritten, or null when none may be
     *
     * @return list<array<string, mixed>>
     */
    public function presentAll(iterable $entries, string $locale, ?int $editableByAdminId = null): array
    {
        $statusTitles = $this->history->statusTitlesByCode($locale);
        $returnStatusTitles = $this->history->returnStatusTitlesByCode($locale);

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = $this->present($entry, $locale, $statusTitles, $returnStatusTitles, $editableByAdminId);
        }

        return $rows;
    }

    /**
     * @param array<string, string> $statusTitles
     * @param array<string, string> $returnStatusTitles
     *
     * @return array<string, mixed>
     */
    private function present(
        OrderHistory $entry,
        string $locale,
        array $statusTitles,
        array $returnStatusTitles,
        ?int $editableByAdminId,
    ): array {
        $eventType = (string) $entry->getEventType();
        $authorId = $entry->getAdminId();

        return [
            'id' => (int) $entry->getId(),
            'event_type' => $eventType,
            'icon' => self::ICONS[$eventType] ?? self::FALLBACK_ICON,
            'summary' => $this->summarize($entry, $eventType, $statusTitles, $returnStatusTitles, $locale),
            'actor' => $this->describeActor($entry, $locale),
            'created_at' => $entry->getCreatedAt(),
            'is_note' => OrderHistoryEventType::NOTE->value === $eventType,
            'visible_to_customer' => $entry->isVisibleToCustomer(),
            'comment' => (string) $entry->getComment(),
            'editable' => OrderHistoryEventType::NOTE->value === $eventType
                && null !== $editableByAdminId
                && null !== $authorId
                && $authorId === $editableByAdminId,
        ];
    }

    /**
     * @param array<string, string> $statusTitles
     * @param array<string, string> $returnStatusTitles
     */
    private function summarize(
        OrderHistory $entry,
        string $eventType,
        array $statusTitles,
        array $returnStatusTitles,
        string $locale,
    ): string {
        $payload = $entry->getDecodedPayload();

        return match (OrderHistoryEventType::tryFrom($eventType)) {
            OrderHistoryEventType::ORDER_CREATED => $this->summarizeOrderCreated($payload, $locale),
            OrderHistoryEventType::STATUS_CHANGED => $this->summarizeStatusChanged($payload, $statusTitles, $locale),
            OrderHistoryEventType::ADDRESS_UPDATED => $this->summarizeAddressUpdated($payload, $locale),
            OrderHistoryEventType::DELIVERY_REF_UPDATED => $this->summarizeReference(
                $this->readString($payload, 'delivery_ref'),
                'Tracking reference set to %reference%',
                'Tracking reference cleared',
                $locale,
            ),
            OrderHistoryEventType::TRANSACTION_REF_UPDATED => $this->summarizeReference(
                $this->readString($payload, 'transaction_ref'),
                'Transaction reference set to %reference%',
                'Transaction reference cleared',
                $locale,
            ),
            OrderHistoryEventType::INVOICE_REF_ALLOCATED => $this->summarizeReference(
                $this->readString($payload, 'invoice_ref'),
                'Invoice reference %reference% allocated',
                'Invoice reference allocated',
                $locale,
            ),
            OrderHistoryEventType::EMAIL_SENT => $this->summarizeReference(
                $this->readString($payload, 'message_code'),
                'E-mail sent (%reference%)',
                'E-mail sent',
                $locale,
            ),
            OrderHistoryEventType::ORDER_EDITED => $this->trans('Lines edited, total %before% → %after%', [
                '%before%' => $this->readString($payload, 'total_before'),
                '%after%' => $this->readString($payload, 'total_after'),
            ], $locale),
            OrderHistoryEventType::NOTE => $this->trans('Note', [], $locale),
            OrderHistoryEventType::RETURN_OPENED => $this->summarizeReference(
                $this->readString($payload, 'return_ref'),
                'Return %reference% opened',
                'Return opened',
                $locale,
            ),
            OrderHistoryEventType::RETURN_STATUS_CHANGED => $this->summarizeReturnStatusChanged(
                $payload,
                $returnStatusTitles,
                $locale,
            ),
            OrderHistoryEventType::RETURN_RECEIVED => $this->summarizeReference(
                $this->readString($payload, 'return_ref'),
                'Return %reference% received',
                'Return received',
                $locale,
            ),
            // A type this theme cannot name — a module's own code, or an enum case
            // added by a core more recent than this theme: its raw code is the only
            // thing that can be said about it without inventing a meaning, and a
            // line the theme cannot phrase must never break the order sheet.
            default => $eventType,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summarizeOrderCreated(array $payload, string $locale): string
    {
        $reference = $this->readString($payload, 'order_ref');

        return '' === $reference
            ? $this->trans('Order created', [], $locale)
            : $this->trans('Order %reference% created', ['%reference%' => $reference], $locale);
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $statusTitles
     */
    private function summarizeStatusChanged(array $payload, array $statusTitles, string $locale): string
    {
        $fromCode = $this->readString($payload, 'from');
        $toCode = $this->readString($payload, 'to');
        $to = $statusTitles[$toCode] ?? $toCode;

        if ('' === $fromCode) {
            return $this->trans('Status set to %to%', ['%to%' => $to], $locale);
        }

        return $this->trans(
            'Status changed from %from% to %to%',
            ['%from%' => $statusTitles[$fromCode] ?? $fromCode, '%to%' => $to],
            $locale,
        );
    }

    /**
     * The same sentence as an order status change, said about a return: the merchant
     * reads one timeline, and a line that only named the codes would not say which of
     * the two stories moved.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $returnStatusTitles
     */
    private function summarizeReturnStatusChanged(array $payload, array $returnStatusTitles, string $locale): string
    {
        $reference = $this->readString($payload, 'return_ref');
        $fromCode = $this->readString($payload, 'from');
        $toCode = $this->readString($payload, 'to');
        $to = $returnStatusTitles[$toCode] ?? $toCode;

        if ('' === $fromCode) {
            return '' === $reference
                ? $this->trans('Return status set to %to%', ['%to%' => $to], $locale)
                : $this->trans('Return %reference% set to %to%', ['%reference%' => $reference, '%to%' => $to], $locale);
        }

        $from = $returnStatusTitles[$fromCode] ?? $fromCode;

        return '' === $reference
            ? $this->trans('Return status changed from %from% to %to%', ['%from%' => $from, '%to%' => $to], $locale)
            : $this->trans(
                'Return %reference% changed from %from% to %to%',
                ['%reference%' => $reference, '%from%' => $from, '%to%' => $to],
                $locale,
            );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summarizeAddressUpdated(array $payload, string $locale): string
    {
        return match ($this->readString($payload, 'address_type')) {
            'delivery' => $this->trans('Delivery address updated', [], $locale),
            'invoice' => $this->trans('Invoice address updated', [], $locale),
            default => $this->trans('Address updated', [], $locale),
        };
    }

    private function summarizeReference(
        string $reference,
        string $withReferenceKey,
        string $withoutReferenceKey,
        string $locale,
    ): string {
        return '' === $reference
            ? $this->trans($withoutReferenceKey, [], $locale)
            : $this->trans($withReferenceKey, ['%reference%' => $reference], $locale);
    }

    private function describeActor(OrderHistory $entry, string $locale): string
    {
        $label = trim((string) $entry->getActorLabel());

        return match ($entry->getActorTypeEnum()) {
            OrderHistoryActorType::ADMIN => '' === $label
                ? $this->trans('An administrator', [], $locale)
                : $label,
            OrderHistoryActorType::CUSTOMER => '' === $label
                ? $this->trans('The customer', [], $locale)
                : $this->trans('Customer %reference%', ['%reference%' => $label], $locale),
            OrderHistoryActorType::MODULE => '' === $label
                ? $this->trans('A module', [], $locale)
                : $this->trans('Module %code%', ['%code%' => $label], $locale),
            OrderHistoryActorType::SYSTEM => $this->trans('System', [], $locale),
            // Same reasoning as an unknown event type: a module is free to write its own
            // actor kind, and its own words are the honest fallback.
            null => '' === $label ? (string) $entry->getActorType() : $label,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return \is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $id, array $parameters, string $locale): string
    {
        return $this->translator->trans($id, $parameters, self::DOMAIN, $locale);
    }
}
