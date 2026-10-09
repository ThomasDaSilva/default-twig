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

use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Model\Admin;

/**
 * The administrator signed in to the back office, for what a controller keys on them:
 * a rate limit, the ownership of a job.
 */
final readonly class CurrentAdministrator
{
    public function __construct(
        private SecurityContext $securityContext,
    ) {
    }

    public function id(): ?int
    {
        $admin = $this->securityContext->getAdminUser();

        return $admin instanceof Admin ? $admin->getId() : null;
    }

    public function isSuperAdministrator(): bool
    {
        $admin = $this->securityContext->getAdminUser();

        return $admin instanceof Admin && AdminResources::SUPERADMINISTRATOR === $admin->getPermissions();
    }
}
