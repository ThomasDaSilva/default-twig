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
use Thelia\Model\Admin;
use Thelia\Model\AiToolToken;
use Thelia\Model\AiToolTokenQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Thelia\Tools\TokenProvider;

/**
 * Configuration > Artificial intelligence > MCP tokens: the administrator creates his own token, sees it once, and
 * only its fingerprint is kept. A token has scopes unless it is explicitly given every right.
 */
final class AiTokenScreenTest extends WebIntegrationTestCase
{
    private ?AdminSessionInjector $injector = null;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $this->admin->eraseCredentials();
        $this->injector->setAdmin($this->admin);
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();
        ConfigQuery::write('ai_mcp_enabled', '0');

        parent::tearDown();
    }

    public function testTheTokenIsShownOnceAndOnlyItsFingerprintIsStored(): void
    {
        $this->client->request('POST', '/admin/configuration/ai/tokens/create', $this->form());
        $html = $this->html();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, preg_match('/data-testid="ai-token-value"[^>]*value="(thelia_mcp_[A-Za-z0-9]{43})"|value="(thelia_mcp_[A-Za-z0-9]{43})"[^>]*data-testid="ai-token-value"/', $html, $found));
        $plain = $found[1] ?: $found[2];

        $row = AiToolTokenQuery::create()->filterByAdminId($this->admin->getId())->findOne();
        self::assertInstanceOf(AiToolToken::class, $row);
        self::assertNotSame($plain, $row->getTokenHash());
        self::assertStringNotContainsString($plain, (string) $row->getTokenHash().(string) $row->getTokenPrefix().(string) $row->getScopes());
        self::assertStringContainsString('Copy your token now', $html);

        // Seen once: the list afterwards shows the prefix, never the token.
        $this->client->request('GET', '/admin/configuration/ai/tokens');
        self::assertStringNotContainsString($plain, $this->html());
        self::assertStringContainsString($row->getTokenPrefix(), $this->html());
        self::assertStringNotContainsString('data-testid="ai-token-created"', $this->html());
    }

    public function testATokenWithoutScopesIsRefusedUnlessItTakesAllTheRights(): void
    {
        $this->client->request('POST', '/admin/configuration/ai/tokens/create', ['label' => 'No scope', '_token' => $this->token(), 'creation_nonce' => $this->nonce()]);
        self::assertSame(0, AiToolTokenQuery::create()->count());

        $this->client->request('POST', '/admin/configuration/ai/tokens/create', ['label' => 'Everything', 'all_rights' => '1', '_token' => $this->token(), 'creation_nonce' => $this->nonce()]);
        $row = AiToolTokenQuery::create()->findOne();
        self::assertNotNull($row);
        self::assertTrue((bool) $row->getAllRights());
    }

    public function testReplayingTheCreationPostCreatesNoSecondToken(): void
    {
        $post = $this->form();

        $this->client->request('POST', '/admin/configuration/ai/tokens/create', $post);
        self::assertSame(1, AiToolTokenQuery::create()->count());

        $this->client->request('POST', '/admin/configuration/ai/tokens/create', $post);
        self::assertSame(1, AiToolTokenQuery::create()->count(), 'the same post must be read once');

        $this->client->request('POST', '/admin/configuration/ai/tokens/create', array_replace($post, ['creation_nonce' => 'forged']));
        self::assertSame(1, AiToolTokenQuery::create()->count());
    }

    public function testATokenCannotTakeAllTheRightsAndScopesAtOnce(): void
    {
        $this->client->request('POST', '/admin/configuration/ai/tokens/create', $this->form(['all_rights' => '1']));
        $this->client->followRedirect();

        self::assertSame(0, AiToolTokenQuery::create()->count(), 'the scopes must not be dropped in silence');
        self::assertStringContainsString('Choose either all your rights or some scopes, not both.', $this->html());
    }

    public function testASuperAdministratorIsWarnedMoreStronglyAboutAllRights(): void
    {
        $this->client->request('GET', '/admin/configuration/ai/tokens');
        self::assertStringContainsString('data-testid="ai-token-super-administrator-warning"', $this->html());

        $this->client->request('POST', '/admin/configuration/ai/tokens/create', ['label' => 'Everything', 'all_rights' => '1', '_token' => $this->token(), 'creation_nonce' => $this->nonce()]);
        self::assertStringContainsString('data-testid="ai-token-super-administrator-created"', $this->html());

        $this->client->request('POST', '/admin/configuration/ai/tokens/create', $this->form());
        self::assertStringNotContainsString('data-testid="ai-token-super-administrator-created"', $this->html());
    }

    public function testAnAdministratorWithAProfileGetsTheOrdinaryWarning(): void
    {
        $limited = (new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([AdminResources::AI_MCP => [AccessManager::VIEW, AccessManager::CREATE]]);
        $limited->eraseCredentials();
        $this->injector?->setAdmin($limited);

        $this->client->request('GET', '/admin/configuration/ai/tokens');

        self::assertStringContainsString('data-testid="ai-token-all-rights"', $this->html());
        self::assertStringNotContainsString('data-testid="ai-token-super-administrator-warning"', $this->html());

        $this->client->request('POST', '/admin/configuration/ai/tokens/create', ['label' => 'Everything', 'all_rights' => '1', '_token' => $this->token(), 'creation_nonce' => $this->nonce()]);
        self::assertStringNotContainsString('data-testid="ai-token-super-administrator-created"', $this->html());
    }

    public function testRevokingATokenThatIsNotYoursAnswersLikeOneThatDoesNotExist(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $other = $factory->admin();
        $foreign = (new AiToolToken())->setAdminId($other->getId())->setLabel('Foreign')->setTokenHash(str_repeat('a', 64))->setTokenPrefix('thelia_mcp_abcd')->setAllRights(1)->setExpiresAt(new \DateTimeImmutable('+30 days'));
        $foreign->save();

        $limited = $factory->restrictedAdmin([AdminResources::AI_MCP => [AccessManager::VIEW]]);
        $limited->eraseCredentials();
        $this->injector?->setAdmin($limited);
        $answers = [];

        foreach ([2000000000, $foreign->getId()] as $id) {
            $this->client->request('POST', '/admin/configuration/ai/tokens/'.$id.'/revoke', ['_token' => $this->token()]);
            $this->client->followRedirect();
            self::assertSame(1, preg_match('/This token does not exist or cannot be revoked\./', $this->html()), 'token '.$id);
            self::assertStringNotContainsString('does not hold the right', $this->html());
            $answers[] = true;
        }

        \Thelia\Model\Map\AiToolTokenTableMap::clearInstancePool();
        self::assertNull(AiToolTokenQuery::create()->findPk($foreign->getId())?->getRevokedAt());
        self::assertCount(2, $answers);
    }

    public function testTheMcpServerIsSwitchedOnAndOffFromTheScreen(): void
    {
        $settings = $this->getService(\Thelia\Domain\Ai\Service\AiSettings::class);
        self::assertFalse($settings->mcpEnabled());

        $this->client->request('GET', '/admin/configuration/ai/tokens');
        self::assertStringContainsString('data-testid="ai-mcp-switch"', $this->html());

        $this->client->request('POST', '/admin/configuration/ai/tokens/mcp', ['enabled' => '1', '_token' => $this->token()]);
        ConfigQuery::resetCache();
        self::assertTrue($settings->mcpEnabled());
        $this->client->request('GET', '/admin/configuration/ai/tokens');
        self::assertStringNotContainsString('data-testid="ai-tokens-mcp-off"', $this->html());

        $this->client->request('POST', '/admin/configuration/ai/tokens/mcp', ['enabled' => '0', '_token' => $this->token()]);
        ConfigQuery::resetCache();
        self::assertFalse($settings->mcpEnabled());
    }

    public function testTheMcpSwitchNeedsItsTokenAndTheRightToUpdateTheAiSettings(): void
    {
        $this->client->request('POST', '/admin/configuration/ai/tokens/mcp', ['enabled' => '1']);
        ConfigQuery::resetCache();
        self::assertFalse($this->getService(\Thelia\Domain\Ai\Service\AiSettings::class)->mcpEnabled(), 'without its token nothing is switched');

        $limited = (new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([AdminResources::AI_MCP => [AccessManager::VIEW, AccessManager::CREATE], AdminResources::AI => [AccessManager::VIEW]]);
        $limited->eraseCredentials();
        $this->injector?->setAdmin($limited);

        $this->client->request('GET', '/admin/configuration/ai/tokens');
        self::assertStringNotContainsString('data-testid="ai-mcp-switch"', $this->html());

        $this->client->request('POST', '/admin/configuration/ai/tokens/mcp', ['enabled' => '1', '_token' => $this->token()]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        ConfigQuery::resetCache();
        self::assertFalse($this->getService(\Thelia\Domain\Ai\Service\AiSettings::class)->mcpEnabled());
    }

    public function testACreationWithoutItsTokenWritesNothing(): void
    {
        $this->client->request('POST', '/admin/configuration/ai/tokens/create', ['label' => 'Forged', 'resources' => [AdminResources::PRODUCT], 'accesses' => [AccessManager::VIEW]]);

        self::assertSame(0, AiToolTokenQuery::create()->count());
    }

    public function testAnAdministratorWithoutTheMcpRightCannotSeeNorCreate(): void
    {
        $form = $this->form();
        $other = (new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([AdminResources::AI => [AccessManager::VIEW, AccessManager::UPDATE]]);
        $other->eraseCredentials();
        $this->injector?->setAdmin($other);

        $this->client->request('GET', '/admin/configuration/ai/tokens');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/admin/configuration/ai/tokens/create', $form);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, AiToolTokenQuery::create()->count());
    }

    public function testARevocationNeedsItsTokenAndStopsTheToken(): void
    {
        $this->client->request('POST', '/admin/configuration/ai/tokens/create', $this->form());
        $row = AiToolTokenQuery::create()->findOne();
        self::assertNotNull($row);

        $this->client->request('POST', '/admin/configuration/ai/tokens/'.$row->getId().'/revoke');
        self::assertNull(AiToolTokenQuery::create()->findPk($row->getId())?->getRevokedAt(), 'without its token nothing is revoked');

        $this->client->request('POST', '/admin/configuration/ai/tokens/'.$row->getId().'/revoke', ['_token' => $this->token()]);
        \Thelia\Model\Map\AiToolTokenTableMap::clearInstancePool();
        self::assertNotNull(AiToolTokenQuery::create()->findPk($row->getId())?->getRevokedAt());
    }

    public function testTheScreenWarnsWhenTheHostIsNotAllowedAndWhenMcpIsOff(): void
    {
        // The test client talks to "localhost", which the server always accepts.
        $this->assertPageRenders('/admin/configuration/ai/tokens');
        self::assertStringContainsString('data-testid="ai-tokens-mcp-off"', $this->html());
        self::assertStringNotContainsString('data-testid="ai-tokens-host-warning"', $this->html());

        $this->client->request('GET', 'http://shop.invalid/admin/configuration/ai/tokens');
        self::assertStringContainsString('data-testid="ai-tokens-host-warning"', $this->html());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + ['label' => 'Desktop assistant', 'resources' => [AdminResources::PRODUCT], 'accesses' => [AccessManager::VIEW], '_token' => $this->token(), 'creation_nonce' => $this->nonce()];
    }

    /**
     * The one-time value the form carries: read from the form, as a browser does.
     */
    private function nonce(): string
    {
        $this->client->request('GET', '/admin/configuration/ai/tokens');
        self::assertSame(1, preg_match('/name="creation_nonce" value="([0-9a-f]{32})"/', $this->html(), $found));

        return $found[1];
    }

    private function token(): string
    {
        return $this->getService(TokenProvider::class)->assignToken();
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
