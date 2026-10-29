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

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * Configuration > System variables: the purpose of each variable, read from `config_i18n`.
 *
 * The screen is judged on `check-available-stock`, the first variable every seed
 * has ever shipped, with its title in English and in French.
 */
final class ConfigVariablesScreenTest extends WebIntegrationTestCase
{
    private const string ENGLISH_PURPOSE = 'Check available product stock (1) or ignore it (0)';
    private const string FRENCH_PURPOSE = 'Vérifier la présence de produits en stock (1) ou l\'ignorer (0)';

    private ?AdminSessionInjector $injector = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();
        parent::tearDown();
    }

    /**
     * The purpose follows the language of the back office, not the default
     * language of the shop: an administrator working in French reads it in French.
     * Judged on the cells alone: the edit dialog of each variable carries the
     * title in the default language of the shop, as the value to edit.
     */
    public function testThePurposeIsShownInTheLanguageOfTheBackOffice(): void
    {
        $this->client->request('GET', '/admin/configuration/variables?lang=fr');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $purposes = $this->purposes();
        self::assertStringContainsString(self::FRENCH_PURPOSE, $purposes);
        self::assertStringNotContainsString(self::ENGLISH_PURPOSE, $purposes);

        $this->client->request('GET', '/admin/configuration/variables?lang=en');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $purposes = $this->purposes();
        self::assertStringContainsString(self::ENGLISH_PURPOSE, $purposes);
        self::assertStringNotContainsString(self::FRENCH_PURPOSE, $purposes);
    }

    /**
     * The text of every purpose cell of the table, one per line.
     */
    private function purposes(): string
    {
        $cells = $this->client->getCrawler()->filter('[data-testid="datatable-variables-cell-title"]');
        self::assertGreaterThan(0, $cells->count(), 'The table shows no purpose cell.');

        return implode("\n", $cells->each(static fn ($cell): string => trim($cell->text())));
    }
}
