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
