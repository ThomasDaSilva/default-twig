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

use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiConnectionAdmin;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiSetupGuide;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiTierAdmin;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiUsageAdmin;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\Ai\AiAudience;
use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\AiTransparency;
use Thelia\Domain\Ai\AsAiUsage;
use Thelia\Domain\Ai\Catalog\AiModelCatalog;
use Thelia\Domain\Ai\Service\AiSettings;
use Thelia\Domain\Ai\Service\AiSettingsWriter;
use Thelia\Domain\Ai\Service\AiUsageRegistry;
use Thelia\Model\AiConnection;
use Thelia\Model\AiConnectionQuery;
use Thelia\Model\AiTierAssignmentQuery;
use Thelia\Model\AiUsageSettingQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Thelia\Tools\TokenProvider;

/**
 * Configuration > Artificial intelligence > Getting started: the three steps say what is done and what is missing,
 * each missing thing is fixed on the page, a module can point at its feature, and the other AI tabs warn while a
 * feature cannot run. The usage `setupprobe.chat` stands for a module that talks to visitors.
 */
final class AiSetupScreenTest extends WebIntegrationTestCase
{
    private const PAGE = '/admin/configuration/ai/setup';
    private const USAGE = 'setupprobe.chat';
    private const ROW = '#ai-setup-usage-setupprobe-chat';

    private ?AdminSessionInjector $injector = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        // A module that talks to visitors: the registry the kernel built is given its usage, since it was read before the test.
        $registry = static::getContainer()->get(AiUsageRegistry::class);
        $declared = [];
        foreach ($registry->all() as $usage) {
            $declared[$usage->id] = $usage;
        }
        $declared[self::USAGE] = new AsAiUsage(self::USAGE, 'Answers the visitors of the probe shop.', AiAudience::Public, AiTier::Fast, [], AiTransparency::Interactive);
        (new \ReflectionProperty(AiUsageRegistry::class, 'usages'))->setValue($registry, $declared);

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();
        ConfigQuery::write('ai_enabled', '1');

        parent::tearDown();
    }

    public function testWithoutAConnectionTheFirstStepIsToDoAndAsksForAKey(): void
    {
        $this->assertPageRenders(self::PAGE);
        $page = $this->client->getCrawler();

        self::assertSame('todo', $page->filter('[data-testid="ai-setup-step-1-state"]')->attr('data-state'));
        self::assertCount(1, $page->filter('form[data-testid="ai-setup-connection-form"] input[type="password"][name="api_key"]'));
        self::assertSame('mistral', $page->filter('select[data-testid="ai-setup-provider"] option')->first()->attr('value'), 'Mistral comes first');
        self::assertStringContainsString('European Union', $page->filter('select[data-testid="ai-setup-provider"] option')->first()->text());
        self::assertStringContainsString('0 of 3 steps done', $page->filter('[data-testid="ai-setup-progress"]')->text());
        self::assertCount(1, $page->filter('[data-testid="ai-setup-models-blocked"]'), 'no model to choose before a provider is connected');
        self::assertSame('ai-tab-setup', $page->filter('[data-testid="ai-tabs"] li')->first()->filter('a')->attr('data-testid'), 'the first tab of the AI screens');
    }

    public function testAConnectionARangeAndAVisitorFeatureWithoutBudgetAsksForTheBudget(): void
    {
        $this->connectAndAssignEveryRange();

        $this->assertPageRenders(self::PAGE);
        $page = $this->client->getCrawler();

        self::assertSame('done', $page->filter('[data-testid="ai-setup-step-1-state"]')->attr('data-state'));
        self::assertSame('done', $page->filter('[data-testid="ai-setup-step-2-state"]')->attr('data-state'));
        self::assertSame('todo', $page->filter('[data-testid="ai-setup-step-3-state"]')->attr('data-state'));
        $row = $page->filter(self::ROW);
        self::assertSame('no_budget', $row->attr('data-state'));
        self::assertStringContainsString('monthly budget', $row->text());
        self::assertCount(1, $row->filter('input[name="monthly_budget"]'));
    }

    public function testTheBudgetIsSavedOnTheSpotAndWhatElseWasSetOnTheUsageStays(): void
    {
        $connection = $this->connectAndAssignEveryRange();
        $admin = $this->getService(AiUsageAdmin::class);
        $pinned = $connection->getId().'|mistral-large-latest';
        $admin->save(self::USAGE, true, 'standard', null, $pinned, false, true, ['requests_per_minute' => 7, 'max_output_tokens' => 900]);
        $before = AiUsageSettingQuery::create()->findOneByUsageId(self::USAGE);
        self::assertNotNull($before);
        // Propel hands back the same object after the request: what the usage held is read now.
        $pinnedModelId = $before->getPinnedModelId();
        $pinnedConnectionId = $before->getPinnedConnectionId();
        self::assertNotNull($pinnedModelId, 'the usage is pinned to a model before the budget is set');

        $this->client->request('POST', self::PAGE.'/budget', ['usage_id' => self::USAGE, 'monthly_budget' => '12,5', '_token' => $this->token()]);

        self::assertTrue($this->client->getResponse()->isRedirection());
        self::assertStringContainsString('usage=setupprobe.chat', (string) $this->client->getResponse()->headers->get('Location'));
        $after = AiUsageSettingQuery::create()->findOneByUsageId(self::USAGE);
        self::assertNotNull($after);
        self::assertSame(12.5, (float) $after->getMonthlyBudget());
        self::assertTrue((bool) $after->getEnabled());
        self::assertSame('standard', $after->getTier());
        self::assertSame($pinnedModelId, $after->getPinnedModelId());
        self::assertSame($pinnedConnectionId, $after->getPinnedConnectionId());
        self::assertFalse((bool) $after->getTransparencyNotice(), 'the notice the merchant turned off stays off');
        self::assertTrue((bool) $after->getProvenanceMark());
        self::assertSame(7, $after->getRequestsPerMinute());
        self::assertSame(900, $after->getMaxOutputTokens());

        $this->client->request('GET', self::PAGE);
        self::assertSame('ready', $this->client->getCrawler()->filter(self::ROW)->attr('data-state'), 'the feature is ready once it has its budget');
    }

    public function testABudgetThatIsNotAnAmountAboveZeroIsRefusedAndNothingIsWritten(): void
    {
        $this->connectAndAssignEveryRange();

        foreach (['0', '0.00', 'abc', '-5', ''] as $budget) {
            $this->client->request('POST', self::PAGE.'/budget', ['usage_id' => self::USAGE, 'monthly_budget' => $budget, '_token' => $this->token()]);
            self::assertSame(0, AiUsageSettingQuery::create()->count(), 'refused: '.$budget);
        }

        $this->client->request('POST', self::PAGE.'/budget', ['usage_id' => self::USAGE, 'monthly_budget' => '10']);
        self::assertSame(0, AiUsageSettingQuery::create()->count(), 'a form without its token writes nothing');
    }

    public function testEverythingReadyGivesThreeStepsAndNoWarningOnTheOtherTabs(): void
    {
        $this->connectAndAssignEveryRange();
        $this->getService(AiUsageAdmin::class)->save(self::USAGE, true, null, '10', null, true, true);

        $this->assertPageRenders(self::PAGE);
        $page = $this->client->getCrawler();

        self::assertStringContainsString('3 of 3 steps done', $page->filter('[data-testid="ai-setup-progress"]')->text());
        self::assertCount(1, $page->filter('[data-testid="ai-setup-complete"]'));
        $this->assertPageRenders('/admin/configuration/ai/tiers');
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid="ai-setup-notice"]'));
    }

    public function testAFeatureNoModelServesSaysSoAndTheOtherTabsWarn(): void
    {
        $this->assertPageRenders(self::PAGE);

        self::assertSame('no_model', $this->client->getCrawler()->filter(self::ROW)->attr('data-state'));
        self::assertStringContainsString('No model serves this feature yet', $this->client->getCrawler()->filter(self::ROW)->text());

        foreach (['/admin/configuration/ai', '/admin/configuration/ai/tiers', '/admin/configuration/ai/usages', '/admin/configuration/ai/consumption'] as $path) {
            $this->assertPageRenders($path);
            $notice = $this->client->getCrawler()->filter('[data-testid="ai-setup-notice"]');
            self::assertCount(1, $notice, $path);
            self::assertStringContainsString('The AI setup is not finished', $notice->text(), $path);
            self::assertSame(self::PAGE, $notice->filter('a')->attr('href'), $path);
        }

        $this->assertPageRenders(self::PAGE);
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid="ai-setup-notice"]'), 'the page itself does not repeat the warning');
    }

    public function testAnAiSwitchedOffForTheWholeShopCanBeTurnedBackOnFromTheStep(): void
    {
        $this->connectAndAssignEveryRange();
        ConfigQuery::write('ai_enabled', '0');

        $this->assertPageRenders(self::PAGE);
        self::assertSame('global_off', $this->client->getCrawler()->filter(self::ROW)->attr('data-state'));
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="ai-setup-enable"]'), 'one button for the whole shop, not one per feature');

        $this->client->request('POST', self::PAGE.'/enable', ['_token' => $this->token()]);

        self::assertTrue((bool) $this->getService(AiSettings::class)->isEnabled());
    }

    public function testAFeatureTheMerchantSwitchedOffLinksToItsEditionAndDoesNotCountAsPending(): void
    {
        $this->connectAndAssignEveryRange();
        $this->getService(AiUsageAdmin::class)->save(self::USAGE, false, null, '10', null, true, true);

        $this->assertPageRenders(self::PAGE);
        $row = $this->client->getCrawler()->filter(self::ROW);

        self::assertSame('switched_off', $row->attr('data-state'));
        self::assertSame('/admin/configuration/ai/usages/'.self::USAGE, $row->filter('a')->attr('href'));
        self::assertSame(0, $this->getService(AiSetupGuide::class)->pendingCount());
    }

    public function testAFeatureServedByAConnectionThatFailedItsTestIsNotReadyAndPointsAtTheFirstStep(): void
    {
        $connection = $this->connectAndAssignEveryRange();
        $this->getService(AiUsageAdmin::class)->save(self::USAGE, true, null, '10', null, true, true);
        $unused = $this->getService(AiSettingsWriter::class)->saveConnection(null, 'openai-test', 'OpenAI', 'openai', null, null, null, null, false, true);
        $unused->setCheckedAt(new \DateTimeImmutable())->setCheckError('Unauthorized')->save();

        $this->assertPageRenders(self::PAGE);
        self::assertSame('ready', $this->client->getCrawler()->filter(self::ROW)->attr('data-state'), 'a connection no range uses does not matter, and one never tested is fine');

        $connection->setCheckedAt(new \DateTimeImmutable())->setCheckError('Unauthorized')->save();

        $this->assertPageRenders(self::PAGE);
        $row = $this->client->getCrawler()->filter(self::ROW);
        self::assertSame('connection_failed', $row->attr('data-state'));
        self::assertStringContainsString('The connection "Mistral" failed its last test', $row->text());
        self::assertSame('#ai-setup-step-1', $row->filter('a')->attr('href'));
        self::assertSame('todo', $this->client->getCrawler()->filter('[data-testid="ai-setup-step-3-state"]')->attr('data-state'));
        self::assertGreaterThanOrEqual(1, $this->getService(AiSetupGuide::class)->pendingCount());

        $this->assertPageRenders('/admin/configuration/ai/tiers');
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="ai-setup-notice"]'));

        $usages = $this->getService(AiUsageAdmin::class)->row(self::USAGE);
        self::assertNotNull($usages);
        self::assertNull($usages['inactive_reason'], 'the Usages tab keeps the reasons of the registry and of the model');

        $connection->setCheckError(null)->save();
        $this->assertPageRenders(self::PAGE);
        self::assertSame('ready', $this->client->getCrawler()->filter(self::ROW)->attr('data-state'), 'a test that worked clears it');
    }

    public function testTheFailedConnectionComesBeforeTheMissingBudget(): void
    {
        $connection = $this->connectAndAssignEveryRange();
        $connection->setCheckedAt(new \DateTimeImmutable())->setCheckError('Unauthorized')->save();

        $this->assertPageRenders(self::PAGE);

        self::assertSame('connection_failed', $this->client->getCrawler()->filter(self::ROW)->attr('data-state'));
    }

    public function testTheUsageParameterPointsAtTheRowOfThatFeature(): void
    {
        $this->assertPageRenders(self::PAGE.'?usage='.self::USAGE);
        $row = $this->client->getCrawler()->filter(self::ROW);

        self::assertCount(1, $row);
        self::assertStringContainsString('list-group-item-warning', (string) $row->attr('class'));
        self::assertSame('bo-scroll-into-view', $row->attr('data-controller'));

        $this->assertPageRenders(self::PAGE);
        self::assertStringNotContainsString('list-group-item-warning', (string) $this->client->getCrawler()->filter(self::ROW)->attr('class'));
        self::assertNull($this->client->getCrawler()->filter(self::ROW)->attr('data-controller'));

        $this->assertPageRenders(self::PAGE.'?usage='.rawurlencode('<script>alert(1)</script>'));
        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $this->client->getResponse()->getContent());
    }

    public function testTheModelsAreSuggestedButNothingIsWrittenUntilTheMerchantConfirms(): void
    {
        $connection = $this->connect();

        $this->assertPageRenders(self::PAGE);
        $page = $this->client->getCrawler();
        $selected = static fn (Crawler $crawler, string $tier): string => (string) $crawler->filter('select[data-testid="ai-setup-tier-'.$tier.'"] option[selected]')->attr('value');

        self::assertSame($connection->getId().'|mistral-small-latest', $selected($page, 'fast'));
        self::assertSame($connection->getId().'|mistral-medium-latest', $selected($page, 'standard'));
        self::assertSame($connection->getId().'|mistral-large-latest', $selected($page, 'advanced'));
        self::assertCount(1, $page->filter('[data-testid="ai-setup-suggestion"]')->first());
        self::assertSame(0, AiTierAssignmentQuery::create()->count(), 'a suggestion is not a choice');
        self::assertSame('todo', $page->filter('[data-testid="ai-setup-step-2-state"]')->attr('data-state'));

        $this->client->submit($page->filter('form[data-testid="ai-setup-models-form"]')->form());

        self::assertSame(3, AiTierAssignmentQuery::create()->count());
        $this->client->request('GET', self::PAGE);
        self::assertSame('done', $this->client->getCrawler()->filter('[data-testid="ai-setup-step-2-state"]')->attr('data-state'));
    }

    public function testOnlyTheRangesTheFeaturesUseAreRequired(): void
    {
        $this->connect();

        $overview = $this->getService(AiSetupGuide::class)->overview('en_US');
        $required = array_column($overview['models']['required'], 'tier');

        self::assertContains('fast', $required, 'the probe feature asks for the fast range');
        self::assertSame(3, \count($overview['models']['required']) + \count($overview['models']['optional']));
    }

    public function testEverySuggestedModelIsInTheShippedCatalogue(): void
    {
        $catalog = new AiModelCatalog();

        foreach (AiSetupGuide::SUGGESTED_MODELS as $provider => $models) {
            self::assertSame(['fast', 'standard', 'advanced'], array_keys($models), $provider);

            foreach ($models as $tier => $name) {
                $specification = $catalog->find($provider, $name);
                self::assertNotNull($specification, \sprintf('%s / %s: "%s" is not in the model catalogue of the core', $provider, $tier, $name));
            }
        }
    }

    public function testTheFormsOfTheStepsWriteNothingWithoutTheirTokenOrWithAnUnknownProvider(): void
    {
        $this->client->request('POST', self::PAGE.'/connection', ['provider' => 'mistral', 'api_key' => 'sk-test']);
        self::assertSame(0, AiConnectionQuery::create()->count());

        $this->client->request('POST', self::PAGE.'/connection', ['provider' => 'ollama', 'api_key' => 'sk-test', '_token' => $this->token()]);
        self::assertSame(0, AiConnectionQuery::create()->count(), 'a provider the step does not offer');

        $this->client->request('POST', self::PAGE.'/connection', ['provider' => 'mistral', 'api_key' => '  ', '_token' => $this->token()]);
        self::assertSame(0, AiConnectionQuery::create()->count(), 'no key');

        $this->client->request('POST', self::PAGE.'/models', ['tier' => ['fast' => '1|mistral-small-latest']]);
        self::assertSame(0, AiTierAssignmentQuery::create()->count());
    }

    public function testTheKeyOfAProviderIsSavedOnceAndReplacedNotDuplicated(): void
    {
        $admin = $this->getService(AiConnectionAdmin::class);

        $first = $admin->connect('mistral', 'Mistral AI (France)', 'sk-first');
        $second = $admin->connect('mistral', 'Mistral AI (France)', 'sk-second');

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(1, AiConnectionQuery::create()->count());
        self::assertTrue((bool) $second->getHasKey());
        self::assertTrue((bool) $second->getActive());

        $other = (new AiConnection())->setCode('anthropic')->setTitle('x')->setProvider('openai')->setJurisdiction('non_eu')->setActive(1);
        $other->save();
        $third = $admin->connect('anthropic', 'Anthropic (Claude)', 'sk-third');

        self::assertSame('anthropic-2', $third->getCode(), 'a code taken by another provider is not reused');
        self::assertSame('anthropic', $third->getProvider());
    }

    private function token(): string
    {
        return $this->getService(TokenProvider::class)->assignToken();
    }

    /**
     * A connection of Mistral, without a key: the guide only reads what is stored.
     */
    private function connect(): AiConnection
    {
        return $this->getService(AiSettingsWriter::class)->saveConnection(null, 'mistral-test', 'Mistral', 'mistral', null, null, null, null, false, true);
    }

    private function connectAndAssignEveryRange(): AiConnection
    {
        $connection = $this->connect();
        $tiers = $this->getService(AiTierAdmin::class);

        foreach (['fast' => 'mistral-small-latest', 'standard' => 'mistral-medium-latest', 'advanced' => 'mistral-large-latest'] as $tier => $model) {
            $tiers->assign(AiTier::from($tier), $connection->getId().'|'.$model);
        }

        return $connection;
    }
}
