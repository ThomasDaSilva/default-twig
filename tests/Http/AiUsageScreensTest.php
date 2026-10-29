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
use Thelia\Domain\Ai\Log\AiConsumptionReport;
use Thelia\Domain\Ai\Service\AiUsageRegister;
use Thelia\Domain\Ai\Service\AiUsageRegisterExport;
use Thelia\Model\AiUsageLog;
use Thelia\Model\AiUsageSettingQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Thelia\Tools\TokenProvider;

/**
 * Configuration > Artificial intelligence > Usages, Register and Consumption: a form without its token writes
 * nothing, a read-only administrator sees no button and is refused on the server, the exports give the lines of
 * the register, and a usage can be allowed with a budget.
 */
final class AiUsageScreensTest extends WebIntegrationTestCase
{
    private const USAGE = 'core.guardrail_topic';

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
        ConfigQuery::write('ai_enabled', '1');
        ConfigQuery::write('ai_monthly_cap', '0');

        parent::tearDown();
    }

    public function testTheThreeScreensRender(): void
    {
        $this->assertPageRenders('/admin/configuration/ai/usages');
        self::assertStringContainsString(self::USAGE, $this->html());
        $this->assertPageRenders('/admin/configuration/ai/usages/'.self::USAGE);
        $this->assertPageRenders('/admin/configuration/ai/register');
        $this->assertPageRenders('/admin/configuration/ai/consumption');
        self::assertStringContainsString('data-testid="ai-global-form"', $this->html());
    }

    public function testTheUsageAndRegisterScreensAreInTheLanguageOfTheBackOffice(): void
    {
        foreach (['/admin/configuration/ai/usages', '/admin/configuration/ai/register', '/admin/configuration/ai/usages/'.self::USAGE] as $path) {
            $this->client->request('GET', $path.'?lang=fr');
            $html = (string) $this->client->getResponse()->getContent();

            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);
            self::assertStringContainsString("Juge si le message d'un visiteur", html_entity_decode($html, \ENT_QUOTES), $path);
            self::assertStringNotContainsString('Judges whether', $html, $path);
        }
    }

    public function testTheRegisterTableWrapsItsCellsInsteadOfScrollingOffTheScreen(): void
    {
        $this->client->request('GET', '/admin/configuration/ai/register');

        self::assertCount(1, $this->client->getCrawler()->filter('table.bo-table-wrap[data-testid="ai-register-table"]'));
    }

    public function testTheUsageListWrapsItsTextCellsAndKeepsTheStatusInSight(): void
    {
        $this->client->request('GET', '/admin/configuration/ai/usages');
        $table = $this->client->getCrawler()->filter('table[data-testid="datatable-ai-usages-table"]');

        self::assertCount(1, $table);
        self::assertGreaterThanOrEqual(4, $table->filter('tbody tr:first-child td div.bo-datatable__wrap')->count());
        self::assertCount(1, $table->filter('tbody tr:first-child td[data-testid="datatable-ai-usages-cell-status"]'));
    }

    public function testTheReasonAUsageDoesNotRunIsUnderItsStatusInTheList(): void
    {
        $cells = 'table[data-testid="datatable-ai-usages-table"] tbody td[data-testid="datatable-ai-usages-cell-status"]';

        ConfigQuery::write('ai_enabled', '0');
        $this->client->request('GET', '/admin/configuration/ai/usages');
        $crawler = $this->client->getCrawler();

        self::assertGreaterThanOrEqual(1, $crawler->filter($cells)->count());
        self::assertCount($crawler->filter($cells)->count(), $crawler->filter($cells.' [data-testid="datatable-badge-note"]'), 'every usage is stopped by the switch, each says why');

        ConfigQuery::write('ai_enabled', '1');
        $this->client->request('GET', '/admin/configuration/ai/usages');
        self::assertLessThan($crawler->filter($cells)->count(), $this->client->getCrawler()->filter($cells.' [data-testid="datatable-badge-note"]')->count(), 'a usage that runs says nothing');
    }

    public function testTheModelListAndTheToolListWrapTheirCells(): void
    {
        $this->client->request('GET', '/admin/configuration/ai/models?provider=mistral');
        self::assertGreaterThanOrEqual(1, $this->client->getCrawler()->filter('table[data-testid="datatable-ai-models-table"] td div.bo-datatable__wrap')->count());

        $this->client->request('GET', '/admin/configuration/ai/tools');
        self::assertCount(1, $this->client->getCrawler()->filter('table.bo-table-wrap[data-testid="ai-tools-table"]'));
    }

    public function testAUsageIsSavedWithItsBudget(): void
    {
        $this->client->request('GET', '/admin/configuration/ai/usages/'.self::USAGE);
        $form = $this->client->getCrawler()->filter('form[data-testid="ai-usage-form"]')->form(['monthly_budget' => '7.5']);
        $this->client->submit($form);

        self::assertTrue($this->client->getResponse()->isRedirection());
        $setting = AiUsageSettingQuery::create()->findOneByUsageId(self::USAGE);
        self::assertNotNull($setting);
        self::assertSame(7.5, (float) $setting->getMonthlyBudget());
        self::assertTrue((bool) $setting->getEnabled());
    }

    public function testTheRangeOfAUsageIsChosenAndGivenBackToTheModule(): void
    {
        $this->client->request('GET', '/admin/configuration/ai/usages/'.self::USAGE);
        $crawler = $this->client->getCrawler();
        self::assertSame(['', 'fast', 'standard', 'advanced'], $crawler->filter('select[data-testid="ai-usage-tier"] option')->each(static fn ($option): string => (string) $option->attr('value')));
        self::assertNotSame('', trim($crawler->filter('[data-testid="ai-usage-declared-tier"]')->text()), 'the screen says which range the module declared');

        $form = $crawler->filter('form[data-testid="ai-usage-form"]')->form(['tier' => 'advanced']);
        $this->client->submit($form);

        self::assertSame('advanced', AiUsageSettingQuery::create()->findOneByUsageId(self::USAGE)?->getTier());
        $this->client->request('GET', '/admin/configuration/ai/usages/'.self::USAGE);
        self::assertSame('advanced', $this->client->getCrawler()->filter('select[data-testid="ai-usage-tier"] option[selected]')->attr('value'));

        $form = $this->client->getCrawler()->filter('form[data-testid="ai-usage-form"]')->form(['tier' => '']);
        $this->client->submit($form);

        self::assertNull(AiUsageSettingQuery::create()->findOneByUsageId(self::USAGE)?->getTier());
    }

    public function testARangeThatIsNotOneIsRefusedAndNothingIsWritten(): void
    {
        $token = $this->getService(TokenProvider::class)->assignToken();
        $this->client->request('POST', '/admin/configuration/ai/usages/'.self::USAGE, ['enabled' => '1', 'sections' => ['general'], 'tier' => 'turbo', '_token' => $token]);

        self::assertSame(0, AiUsageSettingQuery::create()->count());
    }

    public function testAUsageFormWithoutItsTokenWritesNothing(): void
    {
        $this->client->request('POST', '/admin/configuration/ai/usages/'.self::USAGE, ['enabled' => '1', 'monthly_budget' => '99']);

        self::assertSame(0, AiUsageSettingQuery::create()->count());
    }

    public function testTheGlobalSettingsAreWrittenWithTheirTokenOnly(): void
    {
        $this->client->request('POST', '/admin/configuration/ai/consumption/settings', ['monthly_cap' => '12']);
        self::assertSame('0', ConfigQuery::read('ai_monthly_cap', '0'));

        $this->client->request('POST', '/admin/configuration/ai/consumption/settings', ['monthly_cap' => '12.50', '_token' => $this->getService(TokenProvider::class)->assignToken()]);
        self::assertSame('12.50', ConfigQuery::read('ai_monthly_cap', '0'));
        self::assertSame('0', ConfigQuery::read('ai_enabled', '1'), 'a switch left unchecked turns the AI off');
    }

    public function testAnUnreadableCapIsRefusedAndKeepsTheSetting(): void
    {
        $this->client->request('POST', '/admin/configuration/ai/consumption/settings', ['monthly_cap' => 'lots', 'ai_enabled' => '1', '_token' => $this->getService(TokenProvider::class)->assignToken()]);

        self::assertSame('0', ConfigQuery::read('ai_monthly_cap', '0'));
    }

    public function testTheExportsGiveTheLinesOfTheRegister(): void
    {
        $rows = $this->getService(AiUsageRegister::class)->rows();
        $export = new AiUsageRegisterExport();

        $this->client->request('GET', '/admin/configuration/ai/register/export.csv');
        self::assertSame($export->csv($rows), $this->html());
        self::assertStringContainsString('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $this->client->request('GET', '/admin/configuration/ai/register/export.json');
        self::assertSame($rows, json_decode($this->html(), true, 512, \JSON_THROW_ON_ERROR));
        self::assertNotSame([], $rows);
    }

    public function testTheConsumptionIsReadOverTheChosenPeriod(): void
    {
        $log = (new AiUsageLog())
            ->setUsageId('screen.chat')->setModule('screen')->setModel('screen-model')->setKind('completion')->setCost('1.25000000')
            ->setActorType('system')->setActorHash(str_repeat('a', 64))->setStatus('succeeded')
            ->setCreatedAt(new \DateTimeImmutable('2031-05-10 12:00:00'));
        $log->save();

        $this->client->request('GET', '/admin/configuration/ai/consumption?from=2031-05-01&to=2031-05-31');
        $html = $this->html();
        self::assertStringContainsString('screen.chat', $html);
        self::assertStringContainsString('screen-model', $html);
        self::assertStringContainsString('2031-05-10', $html);
        self::assertStringContainsString('1.25000000', $html);

        $this->client->request('GET', '/admin/configuration/ai/consumption?from=2031-06-01&to=2031-06-30');
        self::assertStringNotContainsString('screen.chat', $this->html());
        self::assertGreaterThan(0, \count((new AiConsumptionReport())->by('usage', new \DateTimeImmutable('2031-05-01'), new \DateTimeImmutable('2031-05-31'))));
    }

    public function testCostsInTwoCurrenciesAreShownApartAndNeverAddedUp(): void
    {
        foreach ([['1.25000000', 'USD'], ['0.50000000', 'EUR']] as [$cost, $currency]) {
            (new AiUsageLog())
                ->setUsageId('screen.chat')->setModule('screen')->setModel('screen-model')->setKind('completion')->setCost($cost)->setCurrency($currency)
                ->setActorType('system')->setActorHash(str_repeat('a', 64))->setStatus('succeeded')
                ->setCreatedAt(new \DateTimeImmutable('2031-05-10 12:00:00'))
                ->save();
        }

        $this->client->request('GET', '/admin/configuration/ai/consumption?from=2031-05-01&to=2031-05-31');
        $html = $this->html();

        self::assertStringContainsString('1.25000000 USD', $html);
        self::assertStringContainsString('0.50000000 EUR', $html);
        self::assertStringContainsString('0.5000 EUR, 1.2500 USD', $html);
        self::assertStringNotContainsString('1.7500', $html);
        self::assertStringNotContainsString('Cost (US dollars)', $html);
    }

    public function testAReadOnlyAdministratorSeesNoButtonAndCannotWrite(): void
    {
        $reader = (new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([AdminResources::AI => [AccessManager::VIEW]]);
        $reader->eraseCredentials();
        $this->injector?->setAdmin($reader);

        $this->client->request('GET', '/admin/configuration/ai/usages/'.self::USAGE);
        self::assertStringNotContainsString('data-testid="ai-usage-save"', $this->html());
        self::assertStringContainsString('<fieldset disabled', $this->html());

        $this->client->request('GET', '/admin/configuration/ai/consumption');
        self::assertStringNotContainsString('data-testid="ai-global-save"', $this->html());

        $token = $this->getService(TokenProvider::class)->assignToken();
        $this->client->request('POST', '/admin/configuration/ai/usages/'.self::USAGE, ['enabled' => '1', 'tier' => 'advanced', 'monthly_budget' => '5', '_token' => $token]);
        self::assertSame(0, AiUsageSettingQuery::create()->count());

        $this->client->request('POST', '/admin/configuration/ai/consumption/settings', ['monthly_cap' => '5', 'ai_enabled' => '1', '_token' => $token]);
        self::assertSame('0', ConfigQuery::read('ai_monthly_cap', '0'));
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
