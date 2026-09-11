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

use BackOfficeDefaultTwigBundle\Repository\OrderStatusRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Domain\Order\Service\OrderStatusTransitionGuard;
use Thelia\Model\OrderStatus;

/**
 * What the Transitions tab of a status shows: the other statuses with the ones
 * the graph allows checked, whether the status is free, and the statuses no
 * transition leads to any more.
 */
final readonly class OrderStatusTransitionsContextBuilder
{
    public function __construct(
        private OrderStatusTransitionGuard $transitionGuard,
        private OrderStatusRepository $statusRepository,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(OrderStatus $status, string $locale): array
    {
        $statusId = (int) $status->getId();
        $isFree = $this->transitionGuard->isFree($statusId);
        $allowedIds = array_map(static fn (OrderStatus $target): int => (int) $target->getId(), $this->transitionGuard->allowedTargets($statusId));
        $targets = [];

        foreach ($this->statusRepository->findLocalized($locale) as $candidate) {
            if ((int) $candidate->getId() === $statusId) {
                continue;
            }

            $targets[] = [
                'id' => (int) $candidate->getId(),
                'title' => (string) $candidate->getTitle(),
                'code' => (string) $candidate->getCode(),
                'color' => (string) ($candidate->getColor() ?: '#6c757d'),
                // A free status shows nothing checked: it reaches everything by default.
                'checked' => !$isFree && \in_array((int) $candidate->getId(), $allowedIds, true),
            ];
        }

        return [
            'transition_targets' => $targets,
            'transitions_free' => $isFree,
            'unreachable_statuses' => $this->unreachableStatusTitles($locale),
            'transitions_save_url' => $this->urls->generate('admin.order-status.transitions.save', ['order_status_id' => $statusId]),
        ];
    }

    /**
     * The titles of the statuses the current graph never leads to, for the warning
     * shown on the list and on the Transitions tab.
     *
     * @return list<string>
     */
    public function unreachableStatusTitles(string $locale): array
    {
        $unreachable = $this->transitionGuard->unreachableStatuses();

        if ([] === $unreachable) {
            return [];
        }

        $localized = $this->statusRepository->findLocalized($locale);
        $titles = [];

        foreach ($unreachable as $lost) {
            $titles[] = (string) ($localized[(int) $lost->getId()] ?? $lost)->getTitle();
        }

        return $titles;
    }
}
