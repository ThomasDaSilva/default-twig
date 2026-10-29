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
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\AiConnection;
use Thelia\Model\AiConnectionQuery;
use Thelia\Model\AiModelQuery;
use Thelia\Model\AiTierAssignmentQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Thelia\Tools\TokenProvider;

/**
 * Configuration > Artificial intelligence > Models and Ranges: a shipped model is edited into a row of the shop,
 * a range is assigned to a model whose row is made on the way, a form without its token writes nothing, and a
 * connection a range uses cannot be deleted.
 */
final class AiModelAndTierScreenTest extends WebIntegrationTestCase
{
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

    public function testTheShippedListIsShownAndEditingAPriceWritesARowOfTheShop(): void
    {
        $this->assertPageRenders('/admin/configuration/ai/models?provider=mistral');
        self::assertStringContainsString('mistral-small-latest', $this->html());
        self::assertSame(0, AiModelQuery::create()->count());

        $this->client->request('GET', '/admin/configuration/ai/models/edit?provider=mistral&name=mistral-small-latest');
        $form = $this->client->getCrawler()->filter('form[name="thelia_ai_model"]')->form(['thelia_ai_model[input_price]' => '0.123']);
        $this->client->submit($form);

        self::assertTrue($this->client->getResponse()->isRedirection());
        $row = AiModelQuery::create()->findOneByName('mistral-small-latest');
        self::assertNotNull($row);
        self::assertSame(0.123, (float) AiModelQuery::create()->findPk($row->getId())?->getInputPrice());
    }

    public function testAnAdministratorWhoMayOnlyUpdateCannotCreateAModelByPaddingTheProvider(): void
    {
        $this->client->request('GET', '/admin/configuration/ai/models/edit?provider=mistral&name=mistral-small-latest');
        $form = $this->client->getCrawler()->filter('form[name="thelia_ai_model"]')->form(['thelia_ai_model[input_price]' => '0.5']);
        $this->client->submit($form);
        self::assertSame(1, AiModelQuery::create()->count());

        $this->loginAs([AccessManager::VIEW, AccessManager::UPDATE]);
        $this->client->request('GET', '/admin/configuration/ai/models/edit?provider=mistral&name=mistral-small-latest');
        $form = $this->client->getCrawler()->filter('form[name="thelia_ai_model"]')->form();
        $form['thelia_ai_model[provider]']->setValue(' openai');
        $this->client->submit($form);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, AiModelQuery::create()->filterByProvider('openai')->count());
    }

    public function testAnAdministratorWhoMayOnlyCreateCannotEditAModelByPaddingItsName(): void
    {
        $this->client->request('GET', '/admin/configuration/ai/models/edit?provider=mistral&name=mistral-small-latest');
        $form = $this->client->getCrawler()->filter('form[name="thelia_ai_model"]')->form(['thelia_ai_model[input_price]' => '0.5']);
        $this->client->submit($form);

        $this->loginAs([AccessManager::VIEW, AccessManager::CREATE]);
        $this->client->request('GET', '/admin/configuration/ai/models/new?provider=mistral');
        $form = $this->client->getCrawler()->filter('form[name="thelia_ai_model"]')->form(['thelia_ai_model[input_price]' => '9', 'thelia_ai_model[output_price]' => '9']);
        $form['thelia_ai_model[name]']->setValue(' mistral-small-latest');
        $this->client->submit($form);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(0.5, (float) AiModelQuery::create()->findOneByName('mistral-small-latest')?->getInputPrice());
    }

    public function testAnAdministratorWhoMayCreateAndUpdateStillSavesAModel(): void
    {
        $this->loginAs([AccessManager::VIEW, AccessManager::CREATE, AccessManager::UPDATE]);
        $this->client->request('GET', '/admin/configuration/ai/models/edit?provider=mistral&name=mistral-small-latest');
        $this->client->submit($this->client->getCrawler()->filter('form[name="thelia_ai_model"]')->form(['thelia_ai_model[input_price]' => '0.7']));

        self::assertTrue($this->client->getResponse()->isRedirection());
        self::assertSame(0.7, (float) AiModelQuery::create()->findOneByName('mistral-small-latest')?->getInputPrice());
    }

    public function testAModelFormWithoutItsTokenWritesNothing(): void
    {
        $this->client->request('POST', '/admin/configuration/ai/models/save', ['thelia_ai_model' => [
            'provider' => 'mistral', 'name' => 'forged-model', 'input_price' => '1', 'output_price' => '1', 'currency' => 'USD',
        ]]);

        self::assertSame(0, AiModelQuery::create()->count());
    }

    public function testARangeIsAssignedToAShippedModelWhoseRowIsMadeOnTheWay(): void
    {
        $connection = $this->connection('range-conn');

        $this->client->request('GET', '/admin/configuration/ai/tiers');
        $form = $this->client->getCrawler()->filter('form[name="thelia_ai_tiers"]')->form(['thelia_ai_tiers[fast]' => $connection->getId().'|mistral-small-latest']);
        $this->client->submit($form);

        self::assertTrue($this->client->getResponse()->isRedirection());
        $assignment = AiTierAssignmentQuery::create()->findOneByTier('fast');
        self::assertNotNull($assignment);
        self::assertSame($connection->getId(), $assignment->getConnectionId());
        self::assertSame('mistral-small-latest', AiModelQuery::create()->findPk($assignment->getModelId())?->getName());
    }

    public function testARangeFormWithoutItsTokenWritesNothing(): void
    {
        $connection = $this->connection('range-forged');

        $this->client->request('POST', '/admin/configuration/ai/tiers/save', ['thelia_ai_tiers' => ['fast' => $connection->getId().'|mistral-small-latest']]);

        self::assertSame(0, AiTierAssignmentQuery::create()->count());
    }

    public function testAConnectionUsedByARangeIsNotDeleted(): void
    {
        $connection = $this->connection('range-used');
        $this->client->request('GET', '/admin/configuration/ai/tiers');
        $this->client->submit($this->client->getCrawler()->filter('form[name="thelia_ai_tiers"]')->form(['thelia_ai_tiers[fast]' => $connection->getId().'|mistral-small-latest']));

        $this->client->request('POST', '/admin/configuration/ai/connections/delete', [
            'connection_id' => $connection->getId(),
            '_token' => $this->getService(TokenProvider::class)->assignToken(),
        ]);
        $this->client->followRedirect();

        self::assertNotNull(AiConnectionQuery::create()->findPk($connection->getId()));
        self::assertStringContainsString('still used by a range', (string) $this->client->getResponse()->getContent());
    }

    public function testAModelUsedByARangeIsNotDeleted(): void
    {
        $connection = $this->connection('range-model-used');
        $this->client->request('GET', '/admin/configuration/ai/tiers');
        $this->client->submit($this->client->getCrawler()->filter('form[name="thelia_ai_tiers"]')->form(['thelia_ai_tiers[fast]' => $connection->getId().'|mistral-small-latest']));
        $modelId = AiTierAssignmentQuery::create()->findOneByTier('fast')?->getModelId();
        self::assertNotNull($modelId);

        $this->client->request('POST', '/admin/configuration/ai/models/delete', [
            'model_id' => $modelId,
            '_token' => $this->getService(TokenProvider::class)->assignToken(),
        ]);
        $this->client->followRedirect();

        self::assertNotNull(AiModelQuery::create()->findPk($modelId));
        self::assertStringContainsString('still used by a range', $this->html());
    }

    /**
     * @param list<string> $accesses
     */
    private function loginAs(array $accesses): void
    {
        $admin = (new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([AdminResources::AI => $accesses]);
        $admin->eraseCredentials();
        $this->injector?->setAdmin($admin);
    }

    private function connection(string $code): AiConnection
    {
        $connection = (new AiConnection())->setCode($code)->setTitle($code)->setProvider('mistral')->setJurisdiction('eu');
        $connection->save();

        return $connection;
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
