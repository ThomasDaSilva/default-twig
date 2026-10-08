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
use Thelia\Core\Archiver\ArchiverManager;
use Thelia\Core\Serializer\SerializerManager;

/**
 * The choices an export or an import form offers.
 */
final readonly class DataTransferFormOptions
{
    public function __construct(
        private SerializerManager $serializerManager,
        private ArchiverManager $archiverManager,
        private DataTransferRepository $repository,
    ) {
    }

    /** @return list<array{id: string, name: string, extension: string}> */
    public function serializers(): array
    {
        $options = [];
        foreach ($this->serializerManager->getSerializers() as $serializer) {
            $options[] = ['id' => (string) $serializer->getId(), 'name' => (string) $serializer->getName(), 'extension' => (string) $serializer->getExtension()];
        }

        return $options;
    }

    /** @return list<array{id: string, name: string, extension: string}> */
    public function archivers(): array
    {
        $options = [];
        foreach ($this->archiverManager->getArchivers(true) as $archiver) {
            $options[] = ['id' => (string) $archiver->getId(), 'name' => (string) $archiver->getName(), 'extension' => (string) $archiver->getExtension()];
        }

        return $options;
    }

    /** @return list<array{id: int, title: string, is_default: bool}> */
    public function languages(): array
    {
        $options = [];
        foreach ($this->repository->languages() as $language) {
            $options[] = ['id' => (int) $language->getId(), 'title' => (string) $language->getTitle(), 'is_default' => (bool) $language->getByDefault()];
        }

        return $options;
    }
}
