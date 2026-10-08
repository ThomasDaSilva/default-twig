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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Order\Enum\OrderHistoryActorType;
use Thelia\Model\OrderPaymentTransaction;

/**
 * Turns one line of the payment journal into the row the merchant reads: the movement
 * and its outcome in the interface language, the amount, the provider reference, the
 * author in clear and the provider's message when it failed.
 *
 * The type and the state are plain strings in the database so a module can write a
 * movement the core does not know; such a row shows its own code rather than failing.
 */
final readonly class OrderPaymentLinePresenter
{
    /** @var array<string, string> */
    private const TYPE_LABELS = [
        'authorization' => 'Authorization',
        'capture' => 'Capture',
        'refund' => 'Refund',
        'void' => 'Void',
    ];

    /** @var array<string, string> */
    private const STATE_LABELS = [
        'pending' => 'Pending',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
    ];

    /** @var array<string, string> */
    private const STATE_BADGES = [
        'pending' => 'text-bg-warning',
        'succeeded' => 'text-bg-success',
        'failed' => 'text-bg-danger',
    ];

    private const FALLBACK_BADGE = 'text-bg-secondary';

    private const DOMAIN = 'messages';

    public function __construct(
        // The catalogue the back-office templates read is registered on the Symfony
        // translator, not on the one the core aliases.
        #[Autowire(service: 'translator')]
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param iterable<OrderPaymentTransaction> $lines
     *
     * @return list<array<string, mixed>>
     */
    public function presentAll(iterable $lines, string $locale): array
    {
        $rows = [];

        foreach ($lines as $line) {
            $rows[] = $this->present($line, $locale);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OrderPaymentTransaction $line, string $locale): array
    {
        $type = (string) $line->getType();
        $state = (string) $line->getState();

        return [
            'id' => (int) $line->getId(),
            'type' => $type,
            'type_label' => isset(self::TYPE_LABELS[$type]) ? $this->trans(self::TYPE_LABELS[$type], $locale) : $type,
            'state' => $state,
            'state_label' => isset(self::STATE_LABELS[$state]) ? $this->trans(self::STATE_LABELS[$state], $locale) : $state,
            'state_badge' => self::STATE_BADGES[$state] ?? self::FALLBACK_BADGE,
            'amount' => $line->getAmountAsFloat(),
            'psp_reference' => $line->getPspReference(),
            'parent_id' => $line->getParentId(),
            'actor' => $this->actorOf($line, $locale),
            'actor_type' => (string) $line->getActorType(),
            'error_code' => $line->getErrorCode(),
            'error_message' => $line->getErrorMessage(),
            'created_at' => $line->getCreatedAt(),
        ];
    }

    private function actorOf(OrderPaymentTransaction $line, string $locale): string
    {
        $label = (string) $line->getActorLabel();

        if ('' !== $label) {
            return $label;
        }

        return OrderHistoryActorType::SYSTEM->value === $line->getActorType()
            ? $this->trans('System', $locale)
            : (string) $line->getActorType();
    }

    private function trans(string $message, string $locale): string
    {
        return $this->translator->trans($message, [], self::DOMAIN, $locale);
    }
}
