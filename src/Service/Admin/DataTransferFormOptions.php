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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Archiver\ArchiverManager;
use Thelia\Core\Serializer\SerializerManager;
use Thelia\Model\LangQuery;

/**
 * The choices an export or an import form offers, and the reading of an export form.
 */
final readonly class DataTransferFormOptions
{
    public function __construct(
        private SerializerManager $serializerManager,
        private ArchiverManager $archiverManager,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * The export the form asks for, or why it cannot be launched.
     */
    public function exportInput(Request $request): ExportLaunchInput|string
    {
        $language = LangQuery::create()->findPk((int) $request->request->get('language', 0));
        if (null === $language) {
            return $this->translator->trans('Invalid language selected.');
        }

        $serializerId = (string) $request->request->get('serializer', '');
        if (!$this->serializerManager->has($serializerId)) {
            return $this->translator->trans('Unknown serializer.');
        }

        $archiverId = null;
        if ($request->request->getBoolean('do_compress')) {
            $requested = (string) $request->request->get('archiver', '');
            $archiverId = $this->archiverManager->has($requested) ? $this->archiverManager->get($requested, true)?->getId() : null;
        }

        $rangeDate = null;
        if ($request->request->all('range_date_start') !== [] && $request->request->all('range_date_end') !== []) {
            $rangeDate = ['start' => $request->request->all('range_date_start'), 'end' => $request->request->all('range_date_end')];
        }

        return new ExportLaunchInput(
            $language,
            $serializerId,
            $archiverId,
            $request->request->getBoolean('images'),
            $request->request->getBoolean('documents'),
            $rangeDate,
        );
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
        foreach (LangQuery::create()->orderByPosition()->find() as $language) {
            $options[] = ['id' => (int) $language->getId(), 'title' => (string) $language->getTitle(), 'is_default' => (bool) $language->getByDefault()];
        }

        return $options;
    }
}
