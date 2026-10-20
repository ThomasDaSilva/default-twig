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

namespace BackOfficeDefaultTwigBundle\Repository;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Domain\Webhook\WebhookStatus;
use Thelia\Model\WebhookDeliveryQuery;

/**
 * Reads the delivery log of the webhooks, one page at a time. The list never loads the signed
 * body nor the answer of the receiver: they are the heavy columns of the table, and only the
 * detail of a delivery shows them.
 */
final readonly class WebhookDeliveryRepository
{
    public const int PAGE_SIZE = 25;

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, lastPage: int}
     */
    public function search(?int $subscriptionId, ?string $event, ?string $status, int $page): array
    {
        $query = WebhookDeliveryQuery::create();

        if (null !== $subscriptionId) {
            $query->filterBySubscriptionId($subscriptionId);
        }

        if (null !== $event) {
            $query->filterByEvent($event);
        }

        if (null !== $status) {
            $query->filterByStatus($status);
        }

        $total = $query->count();
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $lastPage);

        $rows = $query
            ->select(['Id', 'SubscriptionId', 'Event', 'Status', 'Attempts', 'ResponseCode', 'DurationMs', 'CreatedAt'])
            ->orderByCreatedAt(Criteria::DESC)
            ->orderById(Criteria::DESC)
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE)
            ->find()
            ->getData();

        return ['rows' => array_values($rows), 'total' => $total, 'page' => $page, 'lastPage' => $lastPage];
    }

    /**
     * @return list<string>
     */
    public function knownStatuses(): array
    {
        return [WebhookStatus::DELIVERY_PENDING, WebhookStatus::DELIVERY_SUCCEEDED, WebhookStatus::DELIVERY_FAILED];
    }
}
