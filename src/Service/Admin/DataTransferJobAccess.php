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
        private CurrentAdministrator $administrator,
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
        return $this->administrator->isSuperAdministrator();
    }

    public function currentAdminId(): ?int
    {
        return $this->administrator->id();
    }
}
