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
    /**
     * The administration log is never purged, and nothing caps how many times an
     * order may be forced: the read is bounded, and says how many entries it left
     * behind so the sheet can own up to it rather than end on a silent cut.
     */
    private const FORCED_CHANGES_LIMIT = 100;

    /**
     * The forced status changes an order has received, most recent first, and the
     * number of older ones the limit left out.
     *
     * Three conditions name them without ambiguity: the resource and the access
     * the order screens write under, the order itself, and the opening words of
     * the sentence a forced change writes. Nothing else the order sheet logs
     * starts with them.
     *
     * @return array{changes: list<array{created_at: ?\DateTimeInterface, admin: string, from_code: string, to_code: string}>, hidden: int}
     */
    public function findForcedOrderStatusChanges(int $orderId): array
    {
        $logs = $this->forcedOrderStatusChangesQuery($orderId)
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

        // Counted only when the page is full: an order forced a handful of times,
        // which is every order in practice, pays for one query.
        $hidden = \count($logs) < self::FORCED_CHANGES_LIMIT
            ? 0
            : max(0, $this->forcedOrderStatusChangesQuery($orderId)->count() - \count($logs));

        return ['changes' => $changes, 'hidden' => $hidden];
    }

    private function forcedOrderStatusChangesQuery(int $orderId): AdminLogQuery
    {
        return AdminLogQuery::create()
            ->filterByResource(AdminResources::ORDER)
            ->filterByResourceId($orderId)
            ->filterByAction(AccessManager::UPDATE)
            ->filterByMessage(self::escapeLikeValue(ForcedStatusChangeLog::MESSAGE_PREFIX).'%', Criteria::LIKE);
    }

    /**
     * A LIKE pattern reads `%`, `_` and the escape character itself; the prefix is
     * meant literally, whatever it is made of.
     */
    private static function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function adminName(AdminLog $log): string
    {
        $name = trim((string) $log->getAdminFirstname().' '.(string) $log->getAdminLastname());

        // The log falls back to placeholders when the administrator is gone from the
        // request; the login is then the only thing left worth showing.
        return $name === '' || str_contains($name, '<no ') ? (string) $log->getAdminLogin() : $name;
    }
}
