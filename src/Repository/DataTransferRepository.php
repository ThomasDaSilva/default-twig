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

namespace BackOfficeDefaultTwigBundle\Repository;

use BackOfficeDefaultTwigBundle\Service\Admin\ImportTemplateBuilder;
use Thelia\Model\ExportCategoryQuery;
use Thelia\Model\ExportJob;
use Thelia\Model\ExportJobQuery;
use Thelia\Model\ExportQuery;
use Thelia\Model\ImportCategoryQuery;
use Thelia\Model\ImportJob;
use Thelia\Model\ImportJobQuery;
use Thelia\Model\ImportQuery;
use Thelia\Model\LangQuery;

/**
 * Localized export/import catalogues for the data-transfer back-office screens, and
 * the jobs they were run as. Each category carries its ordered definitions so the
 * controller stays thin.
 */
final readonly class DataTransferRepository
{
    public function __construct(
        private ImportTemplateBuilder $importTemplateBuilder,
    ) {
    }

    public function findExportIdByRef(string $ref): ?int
    {
        $export = ExportQuery::create()->findOneByRef($ref);

        return null === $export ? null : (int) $export->getId();
    }

    /**
     * @return list<array{id: int, title: string, exports: list<array{id: int, ref: string, title: string, description: string, position: int}>}>
     */
    public function findExportCatalogue(string $locale): array
    {
        $categories = [];
        foreach (ExportCategoryQuery::create()->orderByPosition()->find() as $category) {
            $category->setLocale($locale);
            $exports = [];
            foreach (ExportQuery::create()->filterByExportCategoryId($category->getId())->orderByPosition()->find() as $export) {
                $export->setLocale($locale);
                $exports[] = [
                    'id' => (int) $export->getId(),
                    'ref' => (string) $export->getRef(),
                    'title' => (string) $export->getTitle(),
                    'description' => (string) $export->getDescription(),
                    'position' => (int) $export->getPosition(),
                    'handler_available' => $export->isHandlerAvailable(),
                ];
            }
            $categories[] = [
                'id' => (int) $category->getId(),
                'title' => (string) $category->getTitle(),
                'exports' => $exports,
            ];
        }

        return $categories;
    }

    /**
     * @return list<array{id: int, title: string, imports: list<array{id: int, ref: string, title: string, description: string, position: int, has_template: bool}>}>
     */
    public function findImportCatalogue(string $locale): array
    {
        $categories = [];
        foreach (ImportCategoryQuery::create()->orderByPosition()->find() as $category) {
            $category->setLocale($locale);
            $imports = [];
            foreach (ImportQuery::create()->filterByImportCategoryId($category->getId())->orderByPosition()->find() as $import) {
                $import->setLocale($locale);
                $imports[] = [
                    'id' => (int) $import->getId(),
                    'ref' => (string) $import->getRef(),
                    'title' => (string) $import->getTitle(),
                    'description' => (string) $import->getDescription(),
                    'position' => (int) $import->getPosition(),
                    'has_template' => $this->importTemplateBuilder->columnsFor($import) !== [],
                    'handler_available' => $import->isHandlerAvailable(),
                ];
            }
            $categories[] = [
                'id' => (int) $category->getId(),
                'title' => (string) $category->getTitle(),
                'imports' => $imports,
            ];
        }

        return $categories;
    }

    public function findExportJob(int $jobId): ?ExportJob
    {
        return ExportJobQuery::create()->findPk($jobId);
    }

    public function findImportJob(int $jobId): ?ImportJob
    {
        return ImportJobQuery::create()->findPk($jobId);
    }

    /**
     * The last jobs of every administrator, or of one only.
     *
     * @return list<ExportJob>
     */
    public function findRecentExportJobs(int $limit, ?int $authorId = null, bool $everyAuthor = false): array
    {
        $query = ExportJobQuery::create()->orderByCreatedAt('desc')->orderById('desc')->limit($limit);

        if (!$everyAuthor) {
            // A job whose author is gone, or an administrator not signed in, sees none.
            $query->filterByAdminId($authorId ?? 0);
        }

        $jobs = self::listOf($query->find());
        $locale = $this->defaultLocale();

        foreach ($jobs as $job) {
            $job->getExport()->setLocale($locale);
        }

        return $jobs;
    }

    /**
     * The last jobs of every administrator, or of one only.
     *
     * @return list<ImportJob>
     */
    public function findRecentImportJobs(int $limit, ?int $authorId = null, bool $everyAuthor = false): array
    {
        $query = ImportJobQuery::create()->orderByCreatedAt('desc')->orderById('desc')->limit($limit);

        if (!$everyAuthor) {
            // A job whose author is gone, or an administrator not signed in, sees none.
            $query->filterByAdminId($authorId ?? 0);
        }

        $jobs = self::listOf($query->find());
        $locale = $this->defaultLocale();

        foreach ($jobs as $job) {
            $job->getImport()->setLocale($locale);
        }

        return $jobs;
    }

    /**
     * The locale the screens show the exports and imports in.
     */
    public function defaultLocale(): string
    {
        return (string) (LangQuery::create()->findOneByByDefault(1)?->getLocale() ?? 'en_US');
    }

    /**
     * @template T of object
     *
     * @param iterable<T> $rows
     *
     * @return list<T>
     */
    private static function listOf(iterable $rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            $list[] = $row;
        }

        return $list;
    }
}
