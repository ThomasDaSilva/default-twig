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

namespace BackOfficeDefaultTwigBundle\Service\Admin;

use BackOfficeDefaultTwigBundle\Repository\DataTransferRepository;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Messenger\Monitoring\BackgroundJobsMonitor;
use Thelia\Scheduler\RecurringTaskFailures;
use Thelia\Tools\TokenProvider;

/**
 * What Configuration > Background jobs shows, for the administrator looking at it.
 */
final readonly class BackgroundJobsScreen
{
    private const RECENT_JOBS = 20;

    public function __construct(
        private BackgroundJobsMonitor $monitor,
        private DataTransferRepository $repository,
        private RecurringTaskFailures $recurringTaskFailures,
        private DataTransferJobAccess $jobAccess,
        private AdminAccessChecker $access,
        private TokenProvider $tokens,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function view(): array
    {
        $authorId = $this->jobAccess->currentAdminId();
        $everyAuthor = $this->jobAccess->isSuperAdministrator();

        return [
            'has_queue' => $this->monitor->hasQueue(),
            'pending_count' => $this->monitor->pendingCount(),
            'failed_count' => $this->monitor->failedCount(),
            'failed_jobs' => $this->monitor->failedJobs(),
            'failed_recurring_tasks' => $this->recurringTaskFailures->all(),
            // An export or an import is the business of whoever asked for it, and of the
            // super-administrators: the lists show nothing else.
            'recent_exports' => $this->repository->findRecentExportJobs(self::RECENT_JOBS, $authorId, $everyAuthor),
            'recent_imports' => $this->repository->findRecentImportJobs(self::RECENT_JOBS, $authorId, $everyAuthor),
            // Asked without an audit entry: the screen adapts, nobody tried anything.
            'can_retry' => $this->access->can(AdminResources::BACKGROUND_JOBS, AccessManager::UPDATE),
            'can_delete' => $this->access->can(AdminResources::BACKGROUND_JOBS, AccessManager::DELETE),
            'token' => $this->tokens->assignToken(),
        ];
    }
}
