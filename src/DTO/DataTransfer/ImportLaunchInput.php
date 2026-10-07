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

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Thelia\Model\Lang;

/**
 * An import as its form asks for it, once checked: the file whole, of a format the
 * shop reads, holding what it says it holds.
 */
final readonly class ImportLaunchInput
{
    public function __construct(
        public UploadedFile $file,
        public Lang $language,
    ) {
    }
}
