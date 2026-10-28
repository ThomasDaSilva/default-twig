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

namespace BackOfficeDefaultTwigBundle\Service\Configuration\Ai;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Ai\Service\AiSettings;
use Thelia\Domain\Ai\Tool\AiCreatedToolToken;
use Thelia\Domain\Ai\Tool\Exception\AiToolTokenException;
use Thelia\Domain\Ai\Tool\Mcp\McpAllowedHosts;
use Thelia\Domain\Ai\Tool\Service\AiToolTokenService;
use Thelia\Model\Admin;
use Thelia\Model\AdminQuery;
use Thelia\Model\AiToolToken;
use Thelia\Model\AiToolTokenQuery;

/**
 * What the token screen shows and writes: the tokens of the administrator, the creation (the token comes back once,
 * only its fingerprint is stored) and the revocation. The rules are those of the service of the core; the scopes are
 * built here from the choices of the form.
 */
final readonly class AiTokenAdmin
{
    public function __construct(
        private AiToolTokenService $tokens,
        private AiToolAdmin $tools,
        private AiSettings $settings,
        private McpAllowedHosts $hosts,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array<string, mixed>> the tokens of this administrator, newest first
     */
    public function rows(int $adminId): array
    {
        $now = new \DateTimeImmutable();
        $rows = [];

        foreach (AiToolTokenQuery::create()->filterByAdminId($adminId)->orderById(Criteria::DESC)->find() as $token) {
            $revoked = null !== $token->getRevokedAt();
            $expired = $token->getExpiresAt() <= $now;
            $rows[] = [
                'id' => (int) $token->getId(),
                'label' => (string) $token->getLabel(),
                'prefix' => (string) $token->getTokenPrefix(),
                'reach' => $token->getAllRights() ? $this->translator->trans('All the rights of its administrator') : $this->scopesLabel($token),
                'expires_at' => $token->getExpiresAt()?->format('Y-m-d'),
                'last_used_at' => $token->getLastUsedAt()?->format('Y-m-d H:i') ?? $this->translator->trans('Never used'),
                'state' => $revoked ? 'revoked' : ($expired ? 'expired' : 'active'),
                'state_label' => $this->translator->trans($revoked ? 'Revoked' : ($expired ? 'Expired' : 'Active')),
                'trifecta' => !$revoked && !$expired && $this->tools->isLethalTrifecta($this->tools->toolsOfToken($token)),
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $resources
     * @param list<string> $accesses
     * @param list<string> $modules
     *
     * @throws AiToolTokenException
     */
    public function create(int $adminId, string $label, bool $allRights, array $resources, array $accesses, array $modules): AiCreatedToolToken
    {
        // Both are handed over as chosen: the service of the core refuses all the rights together with scopes.
        return $this->tokens->create($adminId, $label, self::scopes($resources, $accesses, $modules), $allRights);
    }

    /**
     * @param list<string> $resources
     * @param list<string> $accesses
     * @param list<string> $modules
     */
    public function createsTrifecta(bool $allRights, array $resources, array $accesses, array $modules): bool
    {
        return $this->tools->isLethalTrifecta($this->tools->toolsOfScopes($allRights, self::scopes($resources, $accesses, $modules)));
    }

    /**
     * A superadministrator has no profile: no right of his is ever refused, so a token with all his rights can propose
     * anything in the shop.
     */
    public function isSuperAdministrator(int $adminId): bool
    {
        $admin = AdminQuery::create()->findPk($adminId);

        return $admin instanceof Admin && (null === $admin->getProfileId() || 0 === (int) $admin->getProfileId());
    }

    public function revoke(int $tokenId, int $adminId): void
    {
        $this->tokens->revoke($tokenId, $adminId);
    }

    public function mcpEnabled(): bool
    {
        return $this->settings->isEnabled() && $this->settings->mcpEnabled();
    }

    /**
     * @return bool false when the host the administrator uses is not among the hosts the MCP server accepts
     */
    public function hostIsAllowed(string $host): bool
    {
        return \in_array(strtolower($host), array_map('strtolower', $this->hosts->hosts()), true);
    }

    /**
     * @return list<string> the resources of the back office a scope can name
     */
    public static function resourceChoices(): array
    {
        $values = [];

        foreach ((new \ReflectionClass(AdminResources::class))->getConstants() as $value) {
            if (\is_string($value) && str_starts_with($value, 'admin.')) {
                $values[] = $value;
            }
        }

        sort($values);

        return $values;
    }

    /**
     * @return list<string>
     */
    public static function accessChoices(): array
    {
        return [AccessManager::VIEW, AccessManager::CREATE, AccessManager::UPDATE, AccessManager::DELETE];
    }

    /**
     * Every chosen resource or module with every chosen access.
     *
     * @param list<string> $resources
     * @param list<string> $accesses
     * @param list<string> $modules
     *
     * @return list<array{resource?: string, module?: string, access: string}>
     */
    private static function scopes(array $resources, array $accesses, array $modules): array
    {
        $scopes = [];

        foreach ($accesses as $access) {
            foreach ($resources as $resource) {
                $scopes[] = ['resource' => $resource, 'access' => $access];
            }

            foreach ($modules as $module) {
                $scopes[] = ['module' => $module, 'access' => $access];
            }
        }

        return $scopes;
    }

    private function scopesLabel(AiToolToken $token): string
    {
        $scopes = json_decode((string) $token->getScopes(), true);

        if (!\is_array($scopes)) {
            return '';
        }

        return implode(', ', array_map(static fn (mixed $scope): string => \is_array($scope) ? (string) ($scope['access'] ?? '').' '.(string) ($scope['resource'] ?? $scope['module'] ?? '') : '', $scopes));
    }
}
