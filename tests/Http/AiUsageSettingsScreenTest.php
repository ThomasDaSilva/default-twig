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
use Thelia\Domain\Ai\Catalog\AiModelSpecification;
use Thelia\Domain\Ai\Service\AiSettingsWriter;
use Thelia\Model\AiModel;
use Thelia\Model\AiUsageSettingQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Thelia\Tools\TokenProvider;

/**
 * The screen of a usage shows every setting of the row (safety checks, limits, privacy) and writes the whole row
 * through the writer of the core: a form that does not carry a setting never erases it.
 */
final class AiUsageSettingsScreenTest extends WebIntegrationTestCase
{
    private const USAGE = 'core.guardrail_topic';
    private const URL = '/admin/configuration/ai/usages/'.self::USAGE;

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

    public function testAUsageWithEverySettingSetComesBackIdenticalWhenSavedUntouched(): void
    {
        $this->everySettingSet();
        $before = $this->row();

        $this->client->request('GET', self::URL);
        $this->client->submit($this->client->getCrawler()->filter('form[data-testid="ai-usage-form"]')->form());

        self::assertTrue($this->client->getResponse()->isRedirection());
        self::assertSame($before, $this->row());
    }

    public function testAFormThatDoesNotCarryASettingNeverErasesIt(): void
    {
        $this->everySettingSet();
        $before = $this->row();
        $token = $this->getService(TokenProvider::class)->assignToken();

        $this->client->request('POST', self::URL, ['_token' => $token]);
        self::assertSame($before, $this->row(), 'a form with no section changes nothing');

        $this->client->request('POST', self::URL, ['_token' => $token, 'sections' => ['limits'], 'requests_per_minute' => '9']);
        self::assertSame(['requests_per_minute' => 9], array_diff_assoc($this->row(), $before), 'one field of one section changes, the rest of the row stays');

        $this->client->request('POST', self::URL, ['_token' => $token, 'sections' => ['general'], 'enabled' => '1', 'notice' => '1']);
        $after = array_diff_assoc($this->row(), $before);
        self::assertSame(['requests_per_minute', 'transparency_notice'], array_keys($after), 'tier, budget and pinned model were not carried: they stay');
    }

    public function testAFieldLeftEmptyGoesBackToTheDefaultAndTheScreenSaysWhich(): void
    {
        $this->everySettingSet();

        $this->client->request('GET', self::URL);
        $form = $this->client->getCrawler()->filter('form[data-testid="ai-usage-form"]')->form([
            'requests_per_minute' => '',
            'requests_per_day' => '',
            'max_output_tokens' => '',
            'max_input_characters' => '',
            'prompt_retention_days' => '',
        ]);
        $this->client->submit($form);

        $after = $this->row();
        self::assertNull($after['requests_per_minute']);
        self::assertNull($after['requests_per_day']);
        self::assertNull($after['max_output_tokens']);
        self::assertNull($after['max_input_characters']);
        self::assertNull($after['prompt_retention_days']);
        self::assertSame('1', (string) $after['store_prompt'], 'what was not emptied stays');

        $this->client->request('GET', self::URL);
        $text = $this->client->getCrawler()->filter('form[data-testid="ai-usage-form"]')->text();
        self::assertStringContainsString('16000', $text, 'the default length of the answer is written');
        self::assertStringContainsString('30', $text, 'the default retention is written');
        self::assertStringContainsString('no limit', $text, 'a system usage has no rate limit by default');
    }

    public function testTheSectionsAreOnTheScreenWithTheirGuardrails(): void
    {
        $this->assertPageRenders(self::URL);
        $crawler = $this->client->getCrawler();

        foreach (['ai-usage-section-guardrails', 'ai-usage-section-limits', 'ai-usage-section-privacy', 'ai-usage-guardrail-topic', 'ai-usage-rpm', 'ai-usage-store-prompt'] as $testId) {
            self::assertCount(1, $crawler->filter('[data-testid="'.$testId.'"]'), $testId);
        }

        self::assertSame(['follow', 'block', 'annotate', 'off'], $crawler->filter('select[name="guardrail[topic]"] option')->each(static fn ($option): string => (string) $option->attr('value')));
    }

    public function testAGuardrailChoiceIsWrittenAndTheOthersAreKept(): void
    {
        $this->client->request('GET', self::URL);
        $form = $this->client->getCrawler()->filter('form[data-testid="ai-usage-form"]')->form(['guardrail[topic]' => 'annotate']);
        $this->client->submit($form);

        self::assertSame('{"topic":"annotate"}', $this->row()['guardrails']);

        $this->client->request('GET', self::URL);
        self::assertSame('annotate', $this->client->getCrawler()->filter('select[name="guardrail[topic]"] option[selected]')->attr('value'));

        $form = $this->client->getCrawler()->filter('form[data-testid="ai-usage-form"]')->form(['guardrail[topic]' => 'follow']);
        $this->client->submit($form);

        self::assertNull($this->row()['guardrails'], 'every check on "follow the module" gives the checks of the module back');
    }

    public function testALimitThatIsNotAWholeNumberIsRefusedAndNothingIsWritten(): void
    {
        $this->everySettingSet();
        $before = $this->row();

        foreach (['abc', '0', '-3', '1.5'] as $value) {
            $this->client->request('POST', self::URL, ['enabled' => '1', 'sections' => ['limits'], 'requests_per_minute' => $value, '_token' => $this->getService(TokenProvider::class)->assignToken()]);

            self::assertSame($before, $this->row(), $value);
        }

        $this->client->request('GET', self::URL);
        self::assertStringContainsString('whole number', $this->client->getCrawler()->filter('[data-testid="flash-danger"], .alert-danger')->text(''));
    }

    public function testAChoiceThatIsNotProposedIsRefused(): void
    {
        $this->client->request('POST', self::URL, ['enabled' => '1', 'sections' => ['guardrails'], 'guardrail' => ['topic' => 'ignore'], '_token' => $this->getService(TokenProvider::class)->assignToken()]);

        self::assertSame(0, AiUsageSettingQuery::create()->count());
    }

    /**
     * @return array<string, mixed> the row as the database holds it, without its id and dates
     */
    private function row(): array
    {
        $statement = $this->getPropelConnection()->prepare('SELECT * FROM ai_usage_setting WHERE usage_id = ?');
        $statement->execute([self::USAGE]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        \assert(\is_array($row), 'The usage has no row.');
        unset($row['id'], $row['created_at'], $row['updated_at']);

        return $row;
    }

    private function everySettingSet(): void
    {
        $writer = $this->getService(AiSettingsWriter::class);
        $connection = $writer->saveConnection(null, 'screen-settings', 'Screen', 'mistral', null, null, 'sk-screen-settings-0123', null, false, true);
        $model = (new AiModel())->setProvider('mistral')->setName('screen-model')->setConnectionId($connection->getId())->setInputPrice('1.000000')->setOutputPrice('2.000000')->setActive(true)->setSource(AiModelSpecification::SOURCE_MANUAL);
        $model->save();

        $writer->saveUsageSetting(
            null,
            self::USAGE,
            enabled: true,
            tier: 'advanced',
            monthlyBudget: '12.50',
            currency: 'EUR',
            requestsPerMinute: 5,
            requestsPerDay: 50,
            maxOutputTokens: 2000,
            maxInputCharacters: 300,
            pinnedConnectionId: $connection->getId(),
            pinnedModelId: $model->getId(),
            guardrails: ['openai_moderation' => 'annotate', 'topic' => 'block'],
            transparencyNotice: false,
            provenanceMark: false,
            storePrompt: true,
            promptRetentionDays: 7,
        );
    }
}
