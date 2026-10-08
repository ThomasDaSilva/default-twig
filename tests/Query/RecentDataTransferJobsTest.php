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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\Repository\DataTransferRepository;
use BackOfficeDefaultTwigBundle\Tests\Support\QueryCounter;
use Thelia\Model\ExportJob;
use Thelia\Model\ExportQuery;
use Thelia\Model\ImportJob;
use Thelia\Model\ImportQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The background jobs screen lists the last exports and imports with their title:
 * a fixed number of queries, whatever the number of jobs.
 */
final class RecentDataTransferJobsTest extends IntegrationTestCase
{
    public function testTheLastJobsAreListedWithTheirTitleInAFixedNumberOfQueries(): void
    {
        $exports = ExportQuery::create()->limit(5)->find();
        $imports = ImportQuery::create()->limit(2)->find();
        self::assertGreaterThanOrEqual(2, \count($exports));
        self::assertGreaterThanOrEqual(2, \count($imports));

        for ($i = 0; $i < 10; ++$i) {
            (new ExportJob())->setExportId($exports[$i % \count($exports)]->getId())->setStatus('done')->setSerializer('thelia.csv')->save($this->getPropelConnection());
            (new ImportJob())->setImportId($imports[$i % \count($imports)]->getId())->setStatus('done')->setFilePath('/nowhere.csv')->setFileName('stock.csv')->save($this->getPropelConnection());
        }

        $repository = $this->getService(DataTransferRepository::class);
        $titles = [];

        $queries = QueryCounter::count(static function () use ($repository, &$titles): void {
            foreach ($repository->findRecentExportJobs(20, everyAuthor: true) as $job) {
                $titles[] = $job->getExport()->getTitle();
            }

            foreach ($repository->findRecentImportJobs(20, everyAuthor: true) as $job) {
                $titles[] = $job->getImport()->getTitle();
            }
        });

        self::assertGreaterThanOrEqual(20, \count($titles));
        self::assertNotContains('', array_map('strval', $titles));
        self::assertLessThanOrEqual(4, $queries, 'For each list, its default language, then the list with its titles.');
    }
}
