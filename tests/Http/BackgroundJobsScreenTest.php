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

namespace BackOfficeDefaultTwigBundle\Tests\Http;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mailer\Exception\TransportException as MailerTransportException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\DataTransfer\Job\RunExportJob;
use Thelia\Messenger\JobSetAsideException;
use Thelia\Messenger\Message\UndecodableJob;
use Thelia\Messenger\Monitoring\BackgroundJobsMonitor;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ExportJob;
use Thelia\Model\ExportJobQuery;
use Thelia\Model\ExportQuery;
use Thelia\Model\ImportJobQuery;
use Thelia\Model\ImportQuery;
use Thelia\Model\LangQuery;
use Thelia\Scheduler\RecurringTaskFailures;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * Configuration > Background jobs, and the export run as a job.
 *
 * The failed job below is written to the failure transport of the test shop, the
 * `failed` queue of its database, through a connection of its own that the test
 * transaction does not cover: every test empties it afterwards.
 */
final class BackgroundJobsScreenTest extends WebIntegrationTestCase
{
    private const URL = '/admin/configuration/background-jobs';

    private ?AdminSessionInjector $injector = null;

    private FixtureFactory $factory;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->emptyTheFailures();
    }

    protected function tearDown(): void
    {
        $this->emptyTheFailures();
        $this->injector?->clear();

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testAShopWithoutQueueSaysTheJobsRunAtOnce(): void
    {
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::URL);

        $html = $this->html();
        self::assertStringContainsString('data-testid="background-jobs-queue"', $html);
        self::assertStringContainsString('MESSENGER_TRANSPORT_DSN', $html);
        self::assertStringContainsString('data-testid="background-jobs-failed-empty"', $html);
    }

    /**
     * The login page shows the flashes of the session: a gesture posted by a visitor
     * who is not signed in is sent there before anything is said of the job.
     */
    public function testAVisitorWhoIsNotSignedInLearnsNothingOfTheJobs(): void
    {
        $id = $this->setAsideAMail('SMTP down for buyer@example.com');
        $export = ExportQuery::create()->findOne();
        $import = ImportQuery::create()->findOne();
        self::assertNotNull($export, 'The test shop has exports.');
        self::assertNotNull($import, 'The test shop has imports.');

        foreach ([
            self::URL.'/'.$id.'/retry',
            self::URL.'/'.$id.'/delete',
            '/admin/export/'.$export->getId(),
            '/admin/import/'.$import->getId(),
        ] as $url) {
            $this->client->request('POST', $url, ['_token' => 'forged']);

            self::assertTrue($this->client->getResponse()->isRedirection(), $url);
            self::assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'), $url);
            $this->client->followRedirect();
            self::assertCount(0, $this->client->getCrawler()->filter('[data-testid^="bo-flash-"]'), $url);
        }

        self::assertSame(1, $this->monitor()->failedCount());
    }

    public function testAFailedJobIsListedWithItsReason(): void
    {
        $this->setAsideAMail('Connection could not be established with host "smtp.example.com"');
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::URL);

        $html = $this->html();
        self::assertStringContainsString('Your order ORD000000000042', $html);
        self::assertStringContainsString('smtp.example.com', $html);
        self::assertSame('1', trim($this->client->getCrawler()->filter('[data-testid="background-jobs-failed-count"]')->text()));
    }

    /**
     * Without a queue the replayed mail runs at once, on the null mailer of the test
     * shop, and leaves the list.
     */
    public function testAReplayedJobLeavesTheFailures(): void
    {
        $id = $this->setAsideAMail('SMTP down');
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', self::URL.'/'.$id.'/retry', ['_token' => $this->token()]);

        self::assertTrue($this->client->getResponse()->isRedirect(self::URL));
        self::assertSame(0, $this->monitor()->failedCount());
    }

    public function testADeletedJobLeavesTheFailures(): void
    {
        $id = $this->setAsideAMail('SMTP down');
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', self::URL.'/'.$id.'/delete', ['_token' => $this->token()]);

        self::assertTrue($this->client->getResponse()->isRedirect(self::URL));
        self::assertSame(0, $this->monitor()->failedCount());
    }

    public function testAGestureWithoutTheTokenIsRefused(): void
    {
        $id = $this->setAsideAMail('SMTP down');
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', self::URL.'/'.$id.'/delete', ['_token' => 'forged']);

        self::assertSame(1, $this->monitor()->failedCount());
    }

    /**
     * The reason of a failure may quote personal data: an administrator allowed on
     * the advanced configuration, but not on the background jobs, does not see it.
     */
    public function testAnAdministratorWithoutTheResourceIsRefused(): void
    {
        $this->loginAs($this->factory->restrictedAdmin([
            AdminResources::ADVANCED_CONFIGURATION => [AccessManager::VIEW],
        ]));

        $this->client->request('GET', self::URL);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAnAdministratorWhoMaySeeButNotDeleteGetsNoDeleteButton(): void
    {
        $id = $this->setAsideAMail('SMTP down');
        $this->loginAs($this->factory->restrictedAdmin([
            AdminResources::BACKGROUND_JOBS => [AccessManager::VIEW],
        ]));

        $this->assertPageRenders(self::URL);

        self::assertStringNotContainsString('background-jobs-delete-'.$id, $this->html());
        self::assertStringNotContainsString('background-jobs-retry-'.$id, $this->html());
    }

    /**
     * Without a queue the export runs in the request and the file comes back as it
     * did before; the job it was run as is recorded, and its page offers the file.
     */
    public function testWithoutAQueueAnExportIsDownloadedAtOnceAndRecordedAsAJob(): void
    {
        $this->factory->order();
        $export = ExportQuery::create()->findOneByRef('thelia.export.orders');
        self::assertNotNull($export);
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', '/admin/export/'.$export->getId(), [
            '_token' => $this->token(),
            'language' => (string) LangQuery::create()->findOneByByDefault(1)?->getId(),
            'serializer' => 'thelia.csv',
        ]);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $job = ExportJobQuery::create()->filterByExportId($export->getId())->orderById('desc')->findOne();
        self::assertNotNull($job);
        $this->files[] = (string) $job->getFilePath();
        self::assertSame('done', $job->getStatus());

        $this->assertPageRenders('/admin/export/job/'.$job->getId());
        self::assertStringContainsString('data-testid="export-job-download"', $this->html());

        $this->client->request('GET', '/admin/export/job/'.$job->getId().'/download');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Without a queue the import runs in the request and its outcome is told on the
     * import page, as before; the job it was run as is listed on the jobs screen.
     */
    public function testWithoutAQueueAnImportIsDoneAtOnceAndListedAsAJob(): void
    {
        $import = ImportQuery::create()->findOneByRef('thelia.import.stock');
        self::assertNotNull($import);
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency());
        $combination = $this->factory->productSaleElement($product, ['quantity' => 5]);
        $this->loginAs($this->factory->admin());

        $path = sys_get_temp_dir().'/bo-import-'.uniqid('', true).'.csv';
        file_put_contents($path, "id,stock\n".$combination->getId().",21\n");
        $this->files[] = $path;

        $this->client->request('POST', '/admin/import/'.$import->getId(), [
            '_token' => $this->token(),
            'language' => (string) LangQuery::create()->findOneByByDefault(1)?->getId(),
        ], ['file_upload' => new UploadedFile($path, 'stock.csv', 'text/csv', null, true)]);

        self::assertTrue($this->client->getResponse()->isRedirect('/admin/import/'.$import->getId()), (string) $this->client->getResponse()->headers->get('Location'));

        $job = ImportJobQuery::create()->filterByImportId($import->getId())->orderById('desc')->findOne();
        self::assertNotNull($job);
        self::assertSame('done', $job->getStatus());
        self::assertSame(1, $job->getImportedRows());

        $this->assertPageRenders(self::URL);
        self::assertStringContainsString('background-jobs-import-'.$job->getId(), $this->html());

        $this->assertPageRenders('/admin/import/job/'.$job->getId());
        self::assertStringContainsString('data-testid="import-job-rows"', $this->html());
    }

    /**
     * An exported file holds customer and order data: it is for the administrator who
     * asked for it and for a super-administrator, not for every colleague allowed to
     * run an export.
     */
    public function testAnExportIsForItsAuthorAndTheSuperAdministratorsOnly(): void
    {
        $author = $this->factory->restrictedAdmin([AdminResources::EXPORT => [AccessManager::VIEW]]);
        $colleague = $this->factory->restrictedAdmin([AdminResources::EXPORT => [AccessManager::VIEW]]);
        $job = $this->doneExportJob((int) $author->getId());

        $this->loginAs($colleague);
        $this->client->request('GET', '/admin/export/job/'.$job->getId());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/admin/export/job/'.$job->getId().'/download');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->loginAs($author);
        $this->client->request('GET', '/admin/export/job/'.$job->getId().'/download');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->loginAs($this->factory->admin());
        $this->client->request('GET', '/admin/export/job/'.$job->getId().'/download');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The refused rows of an import quote its file: the same rule as an export.
     */
    public function testAnImportIsForItsAuthorAndTheSuperAdministratorsOnly(): void
    {
        $author = $this->factory->restrictedAdmin([AdminResources::IMPORT => [AccessManager::VIEW]]);
        $colleague = $this->factory->restrictedAdmin([AdminResources::IMPORT => [AccessManager::VIEW]]);
        $job = (new \Thelia\Model\ImportJob())
            ->setImportId((int) ImportQuery::create()->findOneByRef('thelia.import.stock')?->getId())
            ->setAdminId((int) $author->getId())
            ->setStatus('done')
            ->setFilePath('var/data-transfer/import/none.csv')
            ->setFileName('stock.csv');
        $job->save($this->getPropelConnection());

        $this->loginAs($colleague);
        $this->client->request('GET', '/admin/import/job/'.$job->getId());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->loginAs($author);
        $this->assertPageRenders('/admin/import/job/'.$job->getId());

        $this->loginAs($this->factory->admin());
        $this->assertPageRenders('/admin/import/job/'.$job->getId());
    }

    /**
     * An error is shown in the colours of an error, as the forms used to show it.
     */
    public function testAnErrorMessageIsShownAsDanger(): void
    {
        $this->loginAs($this->factory->admin());

        $this->client->request('GET', '/admin/export/job/999999999/download');
        $this->client->followRedirect();

        self::assertStringContainsString('alert alert-danger', $this->html());
        self::assertStringContainsString('The file of this export is no longer available', $this->html());
    }

    /**
     * The screen adapts to what the administrator may do, without writing that they
     * tried what they may not.
     */
    public function testAReadOnlyAdministratorLeavesNoAuditEntryByLooking(): void
    {
        $admin = $this->factory->restrictedAdmin([AdminResources::BACKGROUND_JOBS => [AccessManager::VIEW]]);
        $this->loginAs($admin);
        $before = \Thelia\Model\AdminLogQuery::create()->count();

        $this->assertPageRenders(self::URL);

        self::assertSame($before, \Thelia\Model\AdminLogQuery::create()->count());
    }

    /**
     * The recent exports are the business of their author and of the super
     * administrators: a colleague's are not listed, not even by name.
     */
    public function testTheRecentExportsListOnlyWhatTheAdministratorMaySee(): void
    {
        $author = $this->factory->restrictedAdmin([AdminResources::BACKGROUND_JOBS => [AccessManager::VIEW], AdminResources::EXPORT => [AccessManager::VIEW]]);
        $colleague = $this->factory->restrictedAdmin([AdminResources::BACKGROUND_JOBS => [AccessManager::VIEW], AdminResources::EXPORT => [AccessManager::VIEW]]);
        $job = $this->doneExportJob((int) $author->getId());

        $this->loginAs($colleague);
        $this->assertPageRenders(self::URL);
        self::assertStringNotContainsString('background-jobs-export-'.$job->getId().'"', $this->html());

        $this->loginAs($author);
        $this->assertPageRenders(self::URL);
        self::assertStringContainsString('background-jobs-export-'.$job->getId().'"', $this->html());

        $this->loginAs($this->factory->admin());
        $this->assertPageRenders(self::URL);
        self::assertStringContainsString('background-jobs-export-'.$job->getId().'"', $this->html());
    }

    /**
     * The page of a job reloads itself for five minutes at most, and stops when asked.
     */
    /**
     * The page of a job needs the right on the exports: without it, the job is listed
     * but not offered as a link that would answer 403.
     */
    public function testNoDetailsLinkIsOfferedWithoutTheRightToFollowIt(): void
    {
        $admin = $this->factory->restrictedAdmin([AdminResources::BACKGROUND_JOBS => [AccessManager::VIEW]]);
        $job = $this->doneExportJob((int) $admin->getId());
        $this->loginAs($admin);

        $this->assertPageRenders(self::URL);

        self::assertStringContainsString('background-jobs-export-'.$job->getId().'"', $this->html());
        self::assertStringNotContainsString('background-jobs-export-details-'.$job->getId(), $this->html());
    }

    public function testTheJobPageStopsReloadingWhenAskedOrAfterAWhile(): void
    {
        $job = $this->doneExportJob(null);
        $job->setStatus('queued')->save($this->getPropelConnection());
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders('/admin/export/job/'.$job->getId());
        self::assertStringContainsString('http-equiv="refresh"', $this->html());
        self::assertStringContainsString('round=1', $this->html());

        $this->assertPageRenders('/admin/export/job/'.$job->getId().'?round=100');
        self::assertStringNotContainsString('http-equiv="refresh"', $this->html());
        self::assertStringContainsString('This page no longer refreshes on its own.', $this->html());
    }

    public function testAnExportedFileIsNeverKeptByACache(): void
    {
        $job = $this->doneExportJob(null);
        $this->loginAs($this->factory->admin());

        $this->client->request('GET', '/admin/export/job/'.$job->getId().'/download');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testAFileTheCacheAlreadyDroppedIsNotServed(): void
    {
        $job = $this->doneExportJob(null);
        unlink((string) $job->getFilePath());
        $this->loginAs($this->factory->admin());

        $this->client->request('GET', '/admin/export/job/'.$job->getId().'/download');

        self::assertTrue($this->client->getResponse()->isRedirect('/admin/export/job/'.$job->getId()));
    }

    /**
     * Replayed after the purge took its row, the export cannot run: the job says so
     * and stays among the failures instead of being reported back in the queue.
     */
    public function testReplayingAJobWhoseRowIsGoneKeepsItAmongTheFailures(): void
    {
        $this->failureTransport()->send(new Envelope(new RunExportJob(999999999), [
            new SentToFailureTransportStamp('async'),
            new RedeliveryStamp(0, new \DateTimeImmutable('-1 hour')),
            new ErrorDetailsStamp(JobSetAsideException::class, 0, 'The export failed'),
        ]));
        $id = $this->monitor()->failedJobs()[0]->id;
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', self::URL.'/'.$id.'/retry', ['_token' => $this->token()]);

        self::assertSame(1, $this->monitor()->failedCount());

        // Why it failed again goes to the log: the screen says where to look.
        $this->client->followRedirect();
        self::assertStringContainsString('The job failed again. The details are in the server log.', $this->html());
        self::assertStringNotContainsString('999999999 no longer exists', $this->html());
    }

    /**
     * Replayed, a job the shop cannot read fails the same way: it is only offered for
     * deletion.
     */
    public function testAnUnreadableJobIsNotOfferedForReplay(): void
    {
        $this->failureTransport()->send(new Envelope(new UndecodableJob('Vendor\\Gone\\Job', 'The class no longer exists.', '{}'), [
            new SentToFailureTransportStamp('async'),
            new RedeliveryStamp(0, new \DateTimeImmutable('-1 hour')),
            new ErrorDetailsStamp(JobSetAsideException::class, 0, 'Unreadable'),
        ]));
        $id = $this->monitor()->failedJobs()[0]->id;
        $this->loginAs($this->factory->admin());

        $this->assertPageRenders(self::URL);

        self::assertStringNotContainsString('background-jobs-retry-'.$id, $this->html());
        self::assertStringContainsString('background-jobs-delete-'.$id, $this->html());
    }

    /**
     * A recurring task never reaches the failed jobs: its last failure is listed on
     * its own.
     */
    public function testARecurringTaskThatFailedIsListed(): void
    {
        $failures = $this->getService(RecurringTaskFailures::class);
        $failures->record('maintenance:purge', 'Command "maintenance:purge" exited with code "1".');
        $this->loginAs($this->factory->admin());

        try {
            $this->assertPageRenders(self::URL);

            self::assertStringContainsString('data-testid="background-jobs-recurring-failure"', $this->html());
            self::assertStringContainsString('maintenance:purge', $this->html());
        } finally {
            $failures->forget('maintenance:purge');
        }
    }

    /**
     * An export reads, an import rewrites, the whole catalog: a form sent over and over
     * would hold the queue for hours.
     */
    public function testAnAdministratorWhoLaunchesTooManyImportsIsAskedToWait(): void
    {
        $import = ImportQuery::create()->findOneByRef('thelia.import.stock');
        self::assertNotNull($import);
        $this->loginAs($this->factory->admin());
        $token = $this->token();

        $language = (string) LangQuery::create()->findOneByByDefault(1)?->getId();

        // A form sent without its file, or with a file the shop refuses, is answered
        // without spending a launch.
        $this->client->request('POST', '/admin/import/'.$import->getId(), ['_token' => $token, 'language' => $language]);
        $this->client->followRedirect();
        self::assertStringNotContainsString('Too many exports and imports', $this->html());
        $refused = sys_get_temp_dir().'/bo-import-limit-'.uniqid('', true).'.exe';
        file_put_contents($refused, "MZ\x90\x00");
        $this->files[] = $refused;
        $this->client->request('POST', '/admin/import/'.$import->getId(), ['_token' => $token, 'language' => $language], ['file_upload' => new UploadedFile($refused, 'stock.exe', 'application/octet-stream', null, true)]);
        $this->client->followRedirect();
        self::assertStringContainsString('is not allowed', $this->html());

        for ($launch = 1; $launch <= 11; ++$launch) {
            $path = sys_get_temp_dir().'/bo-import-limit-'.uniqid('', true).'.csv';
            file_put_contents($path, "id,stock\n");
            $this->files[] = $path;

            $this->client->request('POST', '/admin/import/'.$import->getId(), ['_token' => $token, 'language' => $language], ['file_upload' => new UploadedFile($path, 'stock.csv', 'text/csv', null, true)]);
            $this->client->followRedirect();

            // Ten launches in ten minutes are allowed, the eleventh is not.
            self::assertSame(11 === $launch, str_contains($this->html(), 'Too many exports and imports asked for in a short time'), 'Launch '.$launch);
        }
    }

    private function doneExportJob(?int $adminId): ExportJob
    {
        $file = THELIA_CACHE_DIR.'export'.\DIRECTORY_SEPARATOR.'background-jobs-screen-'.uniqid('', true).'.csv';
        (new Filesystem())->dumpFile($file, "ref\nORD-1\n");
        $this->files[] = $file;

        $job = (new ExportJob())
            ->setExportId((int) ExportQuery::create()->findOneByRef('thelia.export.orders')?->getId())
            ->setAdminId($adminId)
            ->setStatus('done')
            ->setSerializer('thelia.csv')
            ->setFilePath($file)
            ->setFileName('order.csv');
        $job->save($this->getPropelConnection());

        return $job;
    }

    private function setAsideAMail(string $reason): string
    {
        $email = (new Email())->from('shop@example.com')->to('buyer@example.com')->subject('Your order ORD000000000042')->text('Thank you.');

        $this->failureTransport()->send(new Envelope(new SendEmailMessage($email), [
            new SentToFailureTransportStamp('async'),
            new RedeliveryStamp(0, new \DateTimeImmutable('-1 hour')),
            new ErrorDetailsStamp(MailerTransportException::class, 0, $reason),
        ]));

        return $this->monitor()->failedJobs()[0]->id;
    }

    private function emptyTheFailures(): void
    {
        foreach ($this->monitor()->failedJobs() as $job) {
            $this->monitor()->remove($job->id);
        }
    }

    private function monitor(): BackgroundJobsMonitor
    {
        return $this->getService(BackgroundJobsMonitor::class);
    }

    private function failureTransport(): TransportInterface
    {
        $transport = static::getContainer()->get('messenger.transport.failed');
        \assert($transport instanceof TransportInterface);

        return $transport;
    }

    private function token(): string
    {
        $html = (string) $this->client->request('GET', self::URL)->html();

        $found = preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches) === 1
            || preg_match('/name="_token" value="([^"]+)"/', $html, $matches) === 1;
        self::assertTrue($found, 'The background jobs screen renders the back-office token.');

        return $matches[1];
    }

    private function loginAs(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector?->setAdmin($admin);
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
