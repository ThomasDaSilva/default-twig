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

use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiTierAdmin;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiUsageAdmin;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Api\State\Processor\AiTierAssignmentWriteProcessor;
use Thelia\Domain\Ai\AiAudience;
use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\AiTransparency;
use Thelia\Domain\Ai\AsAiUsage;
use Thelia\Domain\Ai\Catalog\AiModelCatalog;
use Thelia\Domain\Ai\Guardrail\AiGuardrailRunner;
use Thelia\Domain\Ai\Provider\AiBaseUrlValidator;
use Thelia\Domain\Ai\Provider\AiTargetResolver;
use Thelia\Domain\Ai\Repository\AiRoutingRepository;
use Thelia\Domain\Ai\Repository\AiUsageSettingRepository;
use Thelia\Domain\Ai\Service\AiSettings;
use Thelia\Domain\Ai\Service\AiSettingsWriter;
use Thelia\Domain\Ai\Service\AiTextTranslator;
use Thelia\Domain\Ai\Service\AiUsageRegister;
use Thelia\Domain\Ai\Service\AiUsageRegistry;
use Thelia\Domain\Ai\Vault\AiKeyCipher;
use Thelia\Domain\Ai\Vault\AiKeyVault;
use Thelia\Domain\Webhook\WebhookUrlValidator;
use Thelia\Model\AiUsageSettingQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Twig\Environment;

#[AsAiUsage(id: 'screenprobe.chat', purpose: 'Chats with visitors.', audience: AiAudience::Public, tier: AiTier::Fast, transparency: AiTransparency::Interactive)]
final class ScreenProbeUsage
{
}

/**
 * The screen of a usage says, in plain words, why a usage does not run and, when the notice of Article 50(1) is
 * turned off, that the usage does not respect Article 50 and why; it still lets the choice be saved.
 */
final class AiUsageComplianceScreenTest extends WebIntegrationTestCase
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

    public function testAPublicUsageWithoutBudgetIsOffAndSaysWhyThenTurnsNonCompliantWhenItsNoticeIsOff(): void
    {
        $admin = $this->admin();

        $row = $admin->row('screenprobe.chat');
        self::assertNotNull($row);
        self::assertFalse($row['active']);
        self::assertSame(AiUsageAdmin::REASON_NO_BUDGET, $row['inactive_reason']);
        self::assertSame('met', $row['compliance']);

        $admin->save('screenprobe.chat', true, null, '5', null, false, true);

        $row = $admin->row('screenprobe.chat');
        self::assertNotNull($row);
        self::assertTrue($row['active'], 'the budget switches the public usage on');
        self::assertNull($row['inactive_reason']);
        self::assertSame('not_met', $row['compliance']);
        self::assertFalse($row['notice_enabled']);
        self::assertNotNull(AiUsageSettingQuery::create()->findOneByUsageId('screenprobe.chat'), 'a non compliant usage is saved all the same');

        $this->pushRequest();
        $html = $this->twig()->load('@BackOfficeDefaultTwig/configuration/ai/usage/edit.html.twig')->renderBlock('content', [
            'row' => $row,
            'audience_label' => 'Your visitors',
            'tier_label' => 'Fast',
            'declared_tier_label' => 'Fast',
            'tier_choices' => [],
            'chosen_tier' => null,
            'inactive_reason' => null,
            'budget' => '5',
            'pinned' => null,
            'model_choices' => [],
            'switched_on' => true,
        ]);

        self::assertStringContainsString('data-testid="ai-usage-not-compliant"', $html);
        self::assertStringContainsString('does not respect Article 50', $html);
        self::assertStringContainsString((string) $row['compliance_details'][0]['message'], htmlspecialchars_decode($html));
    }

    public function testTheTextOfARefusalIsEscaped(): void
    {
        $row = ['id' => 'x.y', 'purpose' => 'p', 'module' => 'x', 'compliance' => 'not_met', 'notice_enabled' => false, 'provenance_mark_enabled' => true,
            'compliance_details' => [['obligation' => 'article_50_1', 'state' => 'not_met', 'reason' => 'notice_turned_off', 'message' => '<script>alert(1)</script>']]];

        $this->pushRequest();
        $html = $this->twig()->load('@BackOfficeDefaultTwig/configuration/ai/usage/edit.html.twig')->renderBlock('content', [
            'row' => $row, 'audience_label' => 'a', 'tier_label' => 't', 'declared_tier_label' => 't', 'tier_choices' => [], 'chosen_tier' => null, 'inactive_reason' => null, 'budget' => null, 'pinned' => null, 'model_choices' => [], 'switched_on' => true,
        ]);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    private function pushRequest(): void
    {
        $request = \Symfony\Component\HttpFoundation\Request::create('/admin/configuration/ai/usages');
        $request->setSession($this->getService(\Thelia\Core\HttpFoundation\Session\Session::class));
        $this->getService(RequestStack::class)->push($request);
    }

    private function twig(): Environment
    {
        return $this->getService(Environment::class);
    }

    private function admin(): AiUsageAdmin
    {
        $settings = new AiUsageSettingRepository();
        $translator = new AiTextTranslator(new RequestStack());
        $registry = new AiUsageRegistry(['screenprobe.chat' => ScreenProbeUsage::class], $settings, new AiSettings(), $translator);
        $resolver = new AiTargetResolver($registry, $settings, new AiRoutingRepository(), new AiModelCatalog(), new AiKeyVault(new AiKeyCipher('screen-test-secret')));
        $catalog = new AiModelCatalog();

        return new AiUsageAdmin(
            new AiUsageRegister($registry, $settings, $resolver, $translator),
            new AiSettings(),
            $resolver,
            new AiSettingsWriter(new AiKeyVault(new AiKeyCipher('screen-test-secret')), new AiBaseUrlValidator(new WebhookUrlValidator()), $registry, new AiSettings()),
            new AiTierAdmin($catalog, $this->getService(AiSettingsWriter::class), $this->getService(AiTierAssignmentWriteProcessor::class)),
            $registry,
            new AiGuardrailRunner([]),
        );
    }
}
