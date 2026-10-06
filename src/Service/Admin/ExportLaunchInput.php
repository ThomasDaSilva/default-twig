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

use Thelia\Model\Lang;

/**
 * An export as its form asks for it, once checked.
 */
final readonly class ExportLaunchInput
{
    /**
     * @param array{start: array<mixed>, end: array<mixed>}|null $rangeDate as the form sends it
     */
    public function __construct(
        public Lang $language,
        public string $serializerId,
        public ?string $archiverId,
        public bool $includeImages,
        public bool $includeDocuments,
        public ?array $rangeDate,
    ) {
    }
}
