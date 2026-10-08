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

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Import;
use Thelia\Model\ImportQuery;
use Thelia\Model\LangQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The forms that launch an export or an import: who may send them, what happens to
 * one sent without its token, and how their messages are shown.
 */
final class DataTransferFormTest extends WebIntegrationTestCase
{
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
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();
        array_map(unlink(...), array_filter($this->files, is_file(...)));

        parent::tearDown();
    }

    /**
     * The name of an uploaded file comes back in the message that refuses it: it is
     * shown as text, never as markup.
     */
    public function testAMessageQuotingTheUploadedNameIsShownAsText(): void
    {
        $import = $this->stockImport();
        $this->loginAs($this->factory->admin());
        $token = $this->tokenOf('/admin/import/'.$import->getId());

        $this->client->request('POST', '/admin/import/'.$import->getId(), [
            '_token' => $token,
            'language' => $this->defaultLanguageId(),
        ], ['file_upload' => new UploadedFile($this->csvFile(), 'stock.<svg onload=alert(1)>', 'text/csv', null, true)]);
        $this->client->followRedirect();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<svg onload=alert(1)>', $html);
        self::assertStringContainsString('&lt;svg onload=alert(1)&gt;', $html);
    }

    /**
     * A form left open past its token is answered with a message, not a server error.
     */
    public function testAnImportSentWithAnExpiredTokenIsSentBackToItsForm(): void
    {
        $import = $this->stockImport();
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', '/admin/import/'.$import->getId(), [
            '_token' => 'expired',
            'language' => $this->defaultLanguageId(),
        ], ['file_upload' => new UploadedFile($this->csvFile(), 'stock.csv', 'text/csv', null, true)]);

        self::assertTrue($this->client->getResponse()->isRedirect('/admin/import/'.$import->getId()), (string) $this->client->getResponse()->getStatusCode());
    }

    public function testAnExportSentWithAnExpiredTokenIsSentBackToItsForm(): void
    {
        $export = \Thelia\Model\ExportQuery::create()->findOneByRef('thelia.export.orders');
        self::assertNotNull($export);
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', '/admin/export/'.$export->getId(), [
            '_token' => 'expired',
            'language' => $this->defaultLanguageId(),
            'serializer' => 'thelia.csv',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect('/admin/export/'.$export->getId()), (string) $this->client->getResponse()->getStatusCode());
    }

    /**
     * An import changes the catalog: seeing the imports is not enough to run one.
     */
    public function testAnAdministratorWhoMayOnlySeeTheImportsCannotRunOne(): void
    {
        $import = $this->stockImport();
        $this->loginAs($this->factory->restrictedAdmin([AdminResources::IMPORT => [AccessManager::VIEW]]));
        $token = $this->tokenOf('/admin/import/'.$import->getId());

        $this->client->request('POST', '/admin/import/'.$import->getId(), [
            '_token' => $token,
            'language' => $this->defaultLanguageId(),
        ], ['file_upload' => new UploadedFile($this->csvFile(), 'stock.csv', 'text/csv', null, true)]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The description of an import is HTML a module wrote: it keeps its lists, never
     * what would run in the page of the administrator reading it.
     */
    public function testTheDescriptionOfAnImportKeepsItsHtmlButNotItsScripts(): void
    {
        $import = $this->stockImport();
        $locale = (string) LangQuery::create()->findOneByByDefault(1)?->getLocale();
        $import->setLocale($locale)
            ->setDescription('<ul><li onclick="steal()" id="bo-token">id: the combination</li></ul><script>steal()</script><link rel="stylesheet" href="https://elsewhere.example/a.css"><img src="https://elsewhere.example/pixel.gif">')
            ->save($this->getPropelConnection());
        $this->loginAs($this->factory->admin());

        $this->client->request('GET', '/admin/import/'.$import->getId());
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('<li>id: the combination</li>', $html);
        self::assertStringNotContainsString('steal()', $html);
        self::assertStringNotContainsString('elsewhere.example', $html);
        self::assertStringNotContainsString('id="bo-token"', $html);
    }

    /**
     * Thousands of refused rows do not go into the session: a few are quoted, and the
     * page of the job lists them all.
     */
    public function testAnImportWithManyRefusedRowsLeadsToItsJobPage(): void
    {
        $import = $this->stockImport();
        $this->loginAs($this->factory->admin());
        $token = $this->tokenOf('/admin/import/'.$import->getId());
        $path = $this->csvFile();
        file_put_contents($path, "id,stock\n".implode('', array_map(static fn (int $row): string => (999999000 + $row).",1\n", range(1, 12))));

        $this->client->request('POST', '/admin/import/'.$import->getId(), [
            '_token' => $token,
            'language' => $this->defaultLanguageId(),
        ], ['file_upload' => new UploadedFile($path, 'stock.csv', 'text/csv', null, true)]);

        self::assertMatchesRegularExpression('#^/admin/import/job/\d+$#', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->followRedirect();
        self::assertStringContainsString('2 more rows refused', (string) $this->client->getResponse()->getContent());
    }

    /**
     * A file the server did not take whole is told for what it is, not as a file of
     * the wrong format.
     */
    public function testAnUploadTheServerRefusedSaysWhy(): void
    {
        $import = $this->stockImport();
        $this->loginAs($this->factory->admin());
        $token = $this->tokenOf('/admin/import/'.$import->getId());

        $this->client->request('POST', '/admin/import/'.$import->getId(), [
            '_token' => $token,
            'language' => $this->defaultLanguageId(),
        ], ['file_upload' => new UploadedFile($this->csvFile(), 'stock.csv', 'text/csv', \UPLOAD_ERR_INI_SIZE, true)]);
        $this->client->followRedirect();

        self::assertStringContainsString('upload_max_filesize', (string) $this->client->getResponse()->getContent());
    }

    /**
     * A file over post_max_size empties the whole request, its token with it: the
     * administrator reads that the file is too large, not that the form expired.
     */
    public function testAnUploadOverThePostLimitSaysTheFileIsTooLarge(): void
    {
        $import = $this->stockImport();
        $this->loginAs($this->factory->admin());

        $this->client->request('POST', '/admin/import/'.$import->getId(), [], [], ['CONTENT_LENGTH' => '20000000']);
        $this->client->followRedirect();

        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('post_max_size', $page);
        self::assertStringNotContainsString('The form has expired', $page);
    }

    /**
     * The order of the exports is changed from their list, with its token.
     */
    public function testAnExportIsMovedInItsList(): void
    {
        $export = \Thelia\Model\ExportQuery::create()->filterByExportCategoryId(
            (int) \Thelia\Model\ExportQuery::create()->findOneByRef('thelia.export.orders')?->getExportCategoryId(),
        )->orderByPosition()->findOne();
        self::assertNotNull($export);
        $this->loginAs($this->factory->admin());
        $html = (string) $this->client->request('GET', '/admin/export')->html();
        self::assertSame(1, preg_match('/data-[a-z-]*token[a-z-]*="([^"]+)"|name="_token" value="([^"]+)"/', $html, $matches), 'The list renders its token.');
        $token = '' !== ($matches[1] ?? '') ? $matches[1] : $matches[2];

        $this->client->request('POST', '/admin/export/position', ['_token' => $token, 'export_id' => $export->getId(), 'mode' => UpdatePositionEvent::POSITION_ABSOLUTE, 'position' => 2]);

        self::assertTrue($this->client->getResponse()->isRedirect('/admin/export'), (string) $this->client->getResponse()->getStatusCode());
        self::assertSame(2, (int) \Thelia\Model\ExportQuery::create()->findPk($export->getId())?->getPosition());
    }

    public function testAnAdministratorWhoMayOnlySeeTheImportsIsOfferedNoButtonToRunOne(): void
    {
        $import = $this->stockImport();
        $this->loginAs($this->factory->restrictedAdmin([AdminResources::IMPORT => [AccessManager::VIEW]]));

        $html = (string) $this->client->request('GET', '/admin/import/'.$import->getId())->html();

        self::assertStringContainsString('data-testid="import-not-allowed"', $html);
        self::assertStringNotContainsString('Import this file', $html);
    }

    private function stockImport(): Import
    {
        $import = ImportQuery::create()->findOneByRef('thelia.import.stock');
        self::assertNotNull($import);

        return $import;
    }

    private function csvFile(): string
    {
        $path = sys_get_temp_dir().'/bo-import-form-'.uniqid('', true).'.csv';
        file_put_contents($path, "id,stock\n");
        $this->files[] = $path;

        return $path;
    }

    private function defaultLanguageId(): string
    {
        return (string) LangQuery::create()->findOneByByDefault(1)?->getId();
    }

    private function tokenOf(string $url): string
    {
        $html = (string) $this->client->request('GET', $url)->html();
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $html, $matches), 'The form renders its token.');

        return $matches[1];
    }

    private function loginAs(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector?->setAdmin($admin);
    }
}
