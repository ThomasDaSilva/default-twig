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
use Thelia\Domain\DataTransfer\Service\ExportCachePurger;
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
     * False once the cache has dropped the file, when the export never wrote one, or
     * when its format went with its module since.
     */
    public function isAvailable(ExportJob $job): bool
    {
        return JobStatus::DONE === $job->getJobStatus()
            && null !== ExportCachePurger::resolve((string) $job->getFilePath())
            && (null !== $job->getArchiver() || $this->serializerManager->has($job->getSerializer()));
    }

    public function response(ExportJob $job): BinaryFileResponse
    {
        $file = ExportCachePurger::resolve((string) $job->getFilePath())
            ?? throw new \RuntimeException(\sprintf('Export job %d names a file outside the export folder.', (int) $job->getId()));

        $archiverId = $job->getArchiver();
        $contentType = null !== $archiverId
            ? ($this->archiverManager->get($archiverId)?->getMimeType() ?? 'application/octet-stream')
            : $this->serializerManager->get($job->getSerializer())->getMimeType();

        // The file holds customer and order data: never kept by a cache on the way.
        $response = new BinaryFileResponse($file, Response::HTTP_OK, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, private',
        ], false);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, (string) $job->getFileName(), self::asciiName((string) $job->getFileName()));

        return $response;
    }

    /**
     * The name a browser without UTF-8 support falls back to.
     */
    private static function asciiName(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\\/%]/', '_', $name) ?? '';

        return '' === $ascii ? 'export' : $ascii;
    }
}
