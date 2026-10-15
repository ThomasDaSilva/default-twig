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

use BackOfficeDefaultTwigBundle\BackOfficeDefaultTwigBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * A creation form of the back-office leads to the sheet of what it created.
 * The redirect needs the id of the new row: without it, the URL of the sheet
 * cannot be built, the user lands back on the list with a routing error
 * although the row was saved.
 */
final class CreationRedirectTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== BackOfficeDefaultTwigBundle::ACTIVE_TEMPLATE_NAME) {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());

        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        $this->injector->clear();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function creationForms(): iterable
    {
        yield 'brand' => ['/admin/brand', '/admin/brand/create', '#/admin/brand/update/[1-9]\d*$#'];
        yield 'category' => ['/admin/categories', '/admin/categories/create', '#/admin/categories/update\?category_id=[1-9]\d*$#'];
        yield 'folder' => ['/admin/folders', '/admin/folders/create', '#/admin/folders/update/[1-9]\d*$#'];
        yield 'content' => ['/admin/folders?folder_id={folder}', '/admin/content/create', '#/admin/content/update/[1-9]\d*$#'];
        yield 'attribute' => ['/admin/configuration/attributes', '/admin/configuration/attributes/create', '#/admin/configuration/attributes/update\?attribute_id=[1-9]\d*$#'];
        yield 'feature' => ['/admin/configuration/features', '/admin/configuration/features/create', '#/admin/configuration/features/update\?feature_id=[1-9]\d*$#'];
        yield 'template' => ['/admin/configuration/templates', '/admin/configuration/templates/create', '#/admin/configuration/templates/update\?template_id=[1-9]\d*$#'];
    }

    #[DataProvider('creationForms')]
    public function testACreationLeadsToTheSheetOfTheNewRow(string $listUrl, string $createPath, string $sheetPattern): void
    {
        $listUrl = str_replace('{folder}', (string) $this->factory->folder()->getId(), $listUrl);

        $crawler = $this->client->request('GET', $listUrl);
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="'.$createPath.'"]')->form();
        foreach ($form->all() as $field) {
            if (str_ends_with($field->getName(), '[title]') || str_ends_with($field->getName(), '[name]')) {
                $field->setValue('Created through the form');
            }
        }
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertMatchesRegularExpression($sheetPattern, (string) $this->client->getResponse()->headers->get('Location'));
    }
}
