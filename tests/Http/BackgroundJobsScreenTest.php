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

use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Messenger\Monitoring\BackgroundJobsMonitor;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ExportJobQuery;
use Thelia\Model\ExportQuery;
use Thelia\Model\LangQuery;
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

    private function setAsideAMail(string $reason): string
    {
        $email = (new Email())->from('shop@example.com')->to('buyer@example.com')->subject('Your order ORD000000000042')->text('Thank you.');

        $this->failureTransport()->send(new Envelope(new SendEmailMessage($email), [
            new SentToFailureTransportStamp('async'),
            new RedeliveryStamp(0, new \DateTimeImmutable('-1 hour')),
            new ErrorDetailsStamp(\RuntimeException::class, 0, $reason),
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
