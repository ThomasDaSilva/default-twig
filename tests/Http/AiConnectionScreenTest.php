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
use Thelia\Domain\Ai\Service\AiSettingsWriter;
use Thelia\Model\AiConnectionQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Thelia\Tools\TokenProvider;

/**
 * Configuration > Artificial intelligence > Connections: the key goes in by the vault and never comes back, a
 * form without its token writes nothing.
 */
final class AiConnectionScreenTest extends WebIntegrationTestCase
{
    private const KEY = 'sk-never-shown-9f3a7c21';

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

    public function testTheListRenders(): void
    {
        $this->assertPageRenders('/admin/configuration/ai');

        self::assertStringContainsString('data-testid="ai-connections-page"', $this->html());
    }

    public function testTheEnvironmentVariableFieldTellsWhichNamesAreAccepted(): void
    {
        foreach (['en' => 'It must start with THELIA_AI_ and end with _KEY or _TOKEN', 'fr' => 'Elle doit commencer par THELIA_AI_ et finir par _KEY ou _TOKEN'] as $lang => $sentence) {
            $this->client->request('GET', '/admin/configuration/ai/connections/new?lang='.$lang);

            self::assertStringContainsString($sentence, $this->html(), $lang);
            self::assertStringContainsString('MISTRAL_API_KEY', $this->html(), $lang);
        }
    }

    public function testTheKeyIsStoredEncryptedAndNeverRenderedAgain(): void
    {
        $this->client->request('GET', '/admin/configuration/ai/connections/new');
        $form = $this->client->getCrawler()->filter('form[name="thelia_ai_connection"]')->form([
            'thelia_ai_connection[title]' => 'Mistral',
            'thelia_ai_connection[code]' => 'mistral-screen',
            'thelia_ai_connection[provider]' => 'mistral',
            'thelia_ai_connection[api_key]' => self::KEY,
        ]);
        $this->client->submit($form);

        self::assertTrue($this->client->getResponse()->isRedirection());
        $connection = AiConnectionQuery::create()->findOneByCode('mistral-screen');
        self::assertNotNull($connection);
        self::assertNotSame(self::KEY, $connection->getApiKeyEncrypted());
        self::assertStringNotContainsString(self::KEY, (string) $connection->getApiKeyEncrypted());

        $this->client->followRedirect();
        $html = $this->html();
        self::assertStringNotContainsString(self::KEY, $html);
        self::assertStringContainsString('Key saved: yes', $html);

        $this->client->request('GET', '/admin/configuration/ai');
        self::assertStringNotContainsString(self::KEY, $this->html());
    }

    public function testAFormWithoutItsTokenWritesNothing(): void
    {
        $this->client->request('POST', '/admin/configuration/ai/connections', ['thelia_ai_connection' => [
            'title' => 'Forged',
            'code' => 'forged',
            'provider' => 'mistral',
            'api_key' => self::KEY,
        ]]);

        self::assertNull(AiConnectionQuery::create()->findOneByCode('forged'));
    }

    public function testADeletionWithoutItsTokenKeepsTheConnection(): void
    {
        $connection = (new \Thelia\Model\AiConnection())->setCode('keep-me')->setTitle('Keep')->setProvider('mistral')->setJurisdiction('eu');
        $connection->save();

        $this->client->request('POST', '/admin/configuration/ai/connections/delete', ['connection_id' => $connection->getId()]);

        self::assertNotNull(AiConnectionQuery::create()->findOneByCode('keep-me'));
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    public function testAFailureOfTheCoreIsShownInTheLanguageOfTheAdministrator(): void
    {
        $this->client->request('GET', '/admin/configuration/ai/connections/new?lang=fr');
        $values = ['thelia_ai_connection[title]' => 'Mistral', 'thelia_ai_connection[code]' => 'mistral-twice', 'thelia_ai_connection[provider]' => 'mistral', 'thelia_ai_connection[api_key]' => self::KEY];
        $this->client->submit($this->client->getCrawler()->filter('form[name="thelia_ai_connection"]')->form($values));
        self::assertTrue($this->client->getResponse()->isRedirection());

        $this->client->request('GET', '/admin/configuration/ai/connections/new?lang=fr');
        $this->client->submit($this->client->getCrawler()->filter('form[name="thelia_ai_connection"]')->form($values));

        $html = $this->html();
        self::assertStringContainsString('Une autre connexion porte déjà ce code.', $html);
        self::assertStringNotContainsString('Another connection already has this code.', $html);

        $this->client->request('GET', '/admin/configuration/ai/connections/new?lang=en');
        $this->client->submit($this->client->getCrawler()->filter('form[name="thelia_ai_connection"]')->form($values));

        self::assertStringContainsString('Another connection already has this code.', $this->html());
    }

    public function testTheErrorOfAConnectionTestIsShownInTheLanguageOfTheAdministrator(): void
    {
        // No model is known for this provider: the test stops before any call.
        $connection = $this->getService(AiSettingsWriter::class)->saveConnection(null, 'no-model-yet', 'No model', 'openai_compatible', 'eu', 'https://models.example.com/v1', null, null, false, true);

        foreach (['fr' => 'Aucun modèle n\'est connu pour cette connexion', 'en' => 'No model is known for this connection'] as $lang => $expected) {
            $this->client->request('POST', '/admin/configuration/ai/connections/'.$connection->getId().'/test?lang='.$lang, ['_token' => $this->getService(TokenProvider::class)->assignToken()]);
            $this->client->followRedirect();

            self::assertStringContainsString($expected, html_entity_decode($this->html()), $lang);
        }

        self::assertSame('No model is known for this connection: add one first.', AiConnectionQuery::create()->findPk($connection->getId())?->getCheckError(), 'the column keeps the English text');
    }
}
