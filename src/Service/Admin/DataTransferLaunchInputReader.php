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

use BackOfficeDefaultTwigBundle\DTO\DataTransfer\ExportLaunchInput;
use BackOfficeDefaultTwigBundle\DTO\DataTransfer\ImportLaunchInput;
use BackOfficeDefaultTwigBundle\DTO\DataTransfer\LaunchRefusal;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Archiver\ArchiverManager;
use Thelia\Core\Serializer\SerializerManager;
use Thelia\Domain\DataTransfer\Exception\UploadRefusedException;
use Thelia\Domain\DataTransfer\ImportHandler;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;

/**
 * Reads the form that launches an export or an import, and says why it cannot when it
 * cannot. Everything a form can get wrong is told here, before a launch is spent.
 */
final readonly class DataTransferLaunchInputReader
{
    public function __construct(
        private SerializerManager $serializerManager,
        private ArchiverManager $archiverManager,
        private ImportHandler $importHandler,
        private TranslatorInterface $translator,
    ) {
    }

    public function exportInput(Request $request): ExportLaunchInput|LaunchRefusal
    {
        $language = $this->language($request);
        if (!$language instanceof Lang) {
            return $language;
        }

        $serializerId = (string) $request->request->get('serializer', '');
        if (!$this->serializerManager->has($serializerId)) {
            return new LaunchRefusal($this->translator->trans('Unknown serializer.'));
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

    public function importInput(Request $request): ImportLaunchInput|LaunchRefusal
    {
        $file = $request->files->get('file_upload');
        if (!$file instanceof UploadedFile) {
            return new LaunchRefusal($this->translator->trans('Please select a file to import.'));
        }

        // A file the server did not take whole (too large, interrupted) has nothing to
        // read: the reason PHP gives is told rather than "not a CSV file".
        if (!$file->isValid()) {
            return new LaunchRefusal($file->getErrorMessage());
        }

        $language = $this->language($request);
        if (!$language instanceof Lang) {
            return $language;
        }

        // Its name and its content, as the launcher checks them again: a file refused
        // here does not spend a launch.
        try {
            $this->importHandler->validateUpload($file->getClientOriginalName(), $file);
        } catch (UploadRefusedException $refusal) {
            return new LaunchRefusal($refusal->getMessage());
        }

        return new ImportLaunchInput($file, $language);
    }

    private function language(Request $request): Lang|LaunchRefusal
    {
        return LangQuery::create()->findPk((int) $request->request->get('language', 0))
            ?? new LaunchRefusal($this->translator->trans('Invalid language selected.'));
    }
}
