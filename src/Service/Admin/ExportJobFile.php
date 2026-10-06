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

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Thelia\Core\Archiver\ArchiverManager;
use Thelia\Core\Serializer\SerializerManager;
use Thelia\Domain\DataTransfer\Job\JobStatus;
use Thelia\Model\ExportJob;

/**
 * The file an export job wrote, as the administrator downloads it.
 */
final readonly class ExportJobFile
{
    public function __construct(
        private SerializerManager $serializerManager,
        private ArchiverManager $archiverManager,
    ) {
    }

    /**
     * False once the cache has dropped the file, or when the export never wrote one.
     */
    public function isAvailable(ExportJob $job): bool
    {
        return JobStatus::DONE === $job->getJobStatus() && is_file((string) $job->getFilePath());
    }

    public function response(ExportJob $job): BinaryFileResponse
    {
        $archiverId = $job->getArchiver();
        $contentType = null !== $archiverId
            ? ($this->archiverManager->get($archiverId)?->getMimeType() ?? 'application/octet-stream')
            : $this->serializerManager->get($job->getSerializer())->getMimeType();

        return new BinaryFileResponse((string) $job->getFilePath(), Response::HTTP_OK, [
            'Content-Type' => $contentType,
            'Content-Disposition' => \sprintf('%s; filename="%s"', ResponseHeaderBag::DISPOSITION_ATTACHMENT, (string) $job->getFileName()),
        ], false);
    }
}
