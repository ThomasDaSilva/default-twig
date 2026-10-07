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

namespace BackOfficeDefaultTwigBundle\DTO\DataTransfer;

/**
 * Why a form cannot launch an export or an import, as the administrator reads it.
 */
final readonly class LaunchRefusal
{
    public function __construct(
        public string $reason,
    ) {
    }
}
