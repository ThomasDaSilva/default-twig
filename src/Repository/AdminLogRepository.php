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

use BackOfficeDefaultTwigBundle\Service\Order\ForcedStatusChangeLog;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\AdminLog;
use Thelia\Model\AdminLogQuery;

/**
 * Reads back what the administration log holds about one order.
 */
final readonly class AdminLogRepository
{
    private const FORCED_CHANGES_LIMIT = 20;

    /**
     * The forced status changes an order has received, most recent first.
     *
     * Three conditions name them without ambiguity: the resource and the access
     * the order screens write under, the order itself, and the opening words of
     * the sentence a forced change writes. Nothing else the order sheet logs
     * starts with them.
     *
     * @return list<array{created_at: ?\DateTimeInterface, admin: string, from_code: string, to_code: string}>
     */
    public function findForcedOrderStatusChanges(int $orderId): array
    {
        $logs = AdminLogQuery::create()
            ->filterByResource(AdminResources::ORDER)
            ->filterByResourceId($orderId)
            ->filterByAction(AccessManager::UPDATE)
            ->filterByMessage(ForcedStatusChangeLog::MESSAGE_PREFIX.'%', Criteria::LIKE)
            ->orderById(Criteria::DESC)
            ->limit(self::FORCED_CHANGES_LIMIT)
            ->find();

        $changes = [];

        foreach ($logs as $log) {
            $parsed = ForcedStatusChangeLog::parse((string) $log->getMessage());

            if ($parsed === null) {
                continue;
            }

            $changes[] = [
                'created_at' => $log->getCreatedAt(),
                'admin' => $this->adminName($log),
                'from_code' => $parsed['from'],
                'to_code' => $parsed['to'],
            ];
        }

        return $changes;
    }

    private function adminName(AdminLog $log): string
    {
        $name = trim((string) $log->getAdminFirstname().' '.(string) $log->getAdminLastname());

        // The log falls back to placeholders when the administrator is gone from the
        // request; the login is then the only thing left worth showing.
        return $name === '' || str_contains($name, '<no ') ? (string) $log->getAdminLogin() : $name;
    }
}
