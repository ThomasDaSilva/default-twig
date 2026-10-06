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

use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Model\Admin;

/**
 * Who may open an export or an import job.
 *
 * An exported file and the refused rows of an import hold customer and order data:
 * they are the business of the administrator who asked for them, and of a
 * super-administrator, not of every colleague who may run an export.
 */
final readonly class DataTransferJobAccess
{
    public function __construct(
        private SecurityContext $securityContext,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * What an administrator gets for a job that is not theirs.
     */
    public function forbidden(): Response
    {
        return new Response($this->translator->trans("Sorry, you're not allowed to perform this action"), Response::HTTP_FORBIDDEN);
    }

    public function maySee(?int $ownerId): bool
    {
        return $this->isSuperAdministrator() || (null !== $ownerId && $ownerId === $this->currentAdminId());
    }

    public function isSuperAdministrator(): bool
    {
        $admin = $this->securityContext->getAdminUser();

        return $admin instanceof Admin && AdminResources::SUPERADMINISTRATOR === $admin->getPermissions();
    }

    public function currentAdminId(): ?int
    {
        $admin = $this->securityContext->getAdminUser();

        return $admin instanceof Admin ? $admin->getId() : null;
    }
}
