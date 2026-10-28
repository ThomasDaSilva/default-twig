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

namespace BackOfficeDefaultTwigBundle\Tests\Service;

use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiToolAdmin;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Ai\AiAudience;
use Thelia\Domain\Ai\AiCapability;
use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\AiUsageRegistryInterface;
use Thelia\Domain\Ai\AsAiUsage;
use Thelia\Domain\Ai\Tool\AiToolDescriptor;
use Thelia\Domain\Ai\Tool\AiToolEffect;
use Thelia\Domain\Ai\Tool\AiToolRegistryInterface;
use Thelia\Domain\Ai\Tool\AiToolRisk;

/**
 * Which tools a usage or a token reaches, and when together they form the lethal trifecta.
 */
final class AiToolAdminTest extends TestCase
{
    public function testTheTrifectaNeedsTheThreeLegsTogether(): void
    {
        $admin = $this->admin([
            self::tool('catalog', ['public'], [AiToolRisk::PrivateData]),
            self::tool('reviews', ['public'], [AiToolRisk::UntrustedContent]),
        ]);
        self::assertFalse($admin->isLethalTrifecta($admin->toolsOfAudience('public')));

        $admin = $this->admin([
            self::tool('catalog', ['public'], [AiToolRisk::PrivateData]),
            self::tool('reviews', ['public'], [AiToolRisk::UntrustedContent]),
            self::tool('notify', ['public'], [AiToolRisk::ExternalCommunication]),
        ]);
        self::assertTrue($admin->isLethalTrifecta($admin->toolsOfAudience('public')));
    }

    public function testAUsageIsOfferedTheToolsOfItsAudienceWhenItAsksForTools(): void
    {
        $admin = $this->admin([
            self::tool('visitors', ['public'], []),
            self::tool('team', ['admin'], []),
        ], [
            'shop.chat' => new AsAiUsage('shop.chat', 'Chat', AiAudience::Public, AiTier::Fast, [AiCapability::Tools]),
            'shop.plain' => new AsAiUsage('shop.plain', 'Plain', AiAudience::Public, AiTier::Fast),
        ]);

        self::assertSame(['visitors'], array_map(static fn (AiToolDescriptor $tool): string => $tool->name, $admin->toolsOfUsage('shop.chat')));
        self::assertSame([], $admin->toolsOfUsage('shop.plain'));
        self::assertSame([], $admin->toolsOfUsage('shop.unknown'));
    }

    public function testATokenReachesTheMcpToolsItsScopesCover(): void
    {
        $admin = $this->admin([
            self::tool('products', ['admin'], [], true, AdminResources::PRODUCT),
            self::tool('orders', ['admin'], [], true, AdminResources::ORDER),
            self::tool('local', ['admin'], [], false, AdminResources::PRODUCT),
        ]);
        $names = static fn (array $tools): array => array_map(static fn (AiToolDescriptor $tool): string => $tool->name, $tools);

        self::assertSame(['products'], $names($admin->toolsOfScopes(false, [['resource' => AdminResources::PRODUCT, 'access' => AccessManager::VIEW]])));
        self::assertSame([], $names($admin->toolsOfScopes(false, [['resource' => AdminResources::PRODUCT, 'access' => AccessManager::UPDATE]])));
        self::assertSame(['products', 'orders'], $names($admin->toolsOfScopes(true, [])));
    }

    /**
     * @param list<AiToolDescriptor>      $tools
     * @param array<string, AsAiUsage>    $usages
     */
    private function admin(array $tools, array $usages = []): AiToolAdmin
    {
        $registry = $this->createStub(AiToolRegistryInterface::class);
        $registry->method('describe')->willReturn($tools);
        $usageRegistry = $this->createStub(AiUsageRegistryInterface::class);
        $usageRegistry->method('get')->willReturnCallback(static fn (string $id): AsAiUsage => $usages[$id] ?? throw new \Thelia\Domain\Ai\Exception\UnknownAiUsageException($id));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new AiToolAdmin($registry, $usageRegistry, $translator);
    }

    /**
     * @param list<string>        $audiences
     * @param list<AiToolRisk>    $risks
     */
    private static function tool(string $name, array $audiences, array $risks, bool $mcp = true, string $resource = AdminResources::PRODUCT): AiToolDescriptor
    {
        return new AiToolDescriptor($name, $name, 'shop', AiToolEffect::Read, $resource, null, AccessManager::VIEW, $audiences, $risks, $mcp);
    }
}
