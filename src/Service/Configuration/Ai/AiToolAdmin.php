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

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Domain\Ai\AiAudience;
use Thelia\Domain\Ai\AiCapability;
use Thelia\Domain\Ai\AiUsageRegistryInterface;
use Thelia\Domain\Ai\Exception\UnknownAiUsageException;
use Thelia\Domain\Ai\Tool\AiToolDescriptor;
use Thelia\Domain\Ai\Tool\AiToolEffect;
use Thelia\Domain\Ai\Tool\AiToolRegistryInterface;
use Thelia\Domain\Ai\Tool\AiToolRisk;
use Thelia\Model\AiToolToken;

/**
 * What the screens show of the tools the modules declared: the list, the tools a usage can be offered, the tools a
 * token reaches, and whether those tools together form the lethal trifecta (private data, untrusted content and a
 * way out of the shop). The trifecta is signalled, never blocked.
 */
final readonly class AiToolAdmin
{
    public function __construct(
        private AiToolRegistryInterface $registry,
        private AiUsageRegistryInterface $usages,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return array_map(fn (AiToolDescriptor $tool): array => [
            'name' => $tool->name,
            'description' => $tool->description,
            'module' => $tool->module,
            'effect' => $tool->effect->value,
            'effect_label' => $this->translator->trans(AiToolEffect::Write === $tool->effect ? 'Proposes a change (needs approval)' : 'Reads'),
            'right' => $this->right($tool),
            'audiences' => implode(', ', array_map($this->audienceLabel(...), $tool->audiences)),
            'mcp' => $tool->mcp,
            'mcp_label' => $this->translator->trans($tool->mcp ? 'Yes' : 'No'),
            'risks' => implode(', ', array_map($this->riskLabel(...), $tool->risks)),
        ], $this->registry->describe());
    }

    /**
     * @return list<AiToolDescriptor> the tools a usage can be offered: none when it does not ask for the tools capability
     */
    public function toolsOfUsage(string $usageId): array
    {
        try {
            $usage = $this->usages->get($usageId);
        } catch (UnknownAiUsageException) {
            return [];
        }

        return $usage->requires(AiCapability::Tools) ? $this->toolsOfAudience($usage->audience->value) : [];
    }

    /**
     * @return list<AiToolDescriptor> the tools a usage of this audience can be offered
     */
    public function toolsOfAudience(string $audience): array
    {
        $audience = AiAudience::tryFrom($audience);

        if (null === $audience || AiAudience::System === $audience) {
            return [];
        }

        return array_values(array_filter($this->registry->describe(), static fn (AiToolDescriptor $tool): bool => \in_array($audience->value, $tool->audiences, true)));
    }

    /**
     * @return list<AiToolDescriptor> the tools over MCP a token reaches by its scopes (every MCP tool for a token with all the rights)
     */
    public function toolsOfScopes(bool $allRights, array $scopes): array
    {
        return array_values(array_filter($this->registry->describe(), static function (AiToolDescriptor $tool) use ($allRights, $scopes): bool {
            if (!$tool->mcp) {
                return false;
            }

            if ($allRights) {
                return true;
            }

            foreach ($scopes as $scope) {
                if (!\is_array($scope) || ($scope['access'] ?? null) !== $tool->access) {
                    continue;
                }

                if ((isset($scope['resource']) && $scope['resource'] === $tool->resource) || (isset($scope['module']) && $scope['module'] === $tool->aclModule)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @return list<AiToolDescriptor>
     */
    public function toolsOfToken(AiToolToken $token): array
    {
        $scopes = json_decode((string) $token->getScopes(), true);

        return $this->toolsOfScopes((bool) $token->getAllRights(), \is_array($scopes) ? $scopes : []);
    }

    /**
     * @param list<AiToolDescriptor> $tools
     */
    public function isLethalTrifecta(array $tools): bool
    {
        $risks = [];

        foreach ($tools as $tool) {
            foreach ($tool->risks as $risk) {
                $risks[$risk->value] = true;
            }
        }

        return \count(AiToolRisk::cases()) === \count($risks);
    }

    private function right(AiToolDescriptor $tool): string
    {
        return $this->accessLabel($tool->access).' '.($tool->resource ?? $this->translator->trans('module %module%', ['%module%' => (string) $tool->aclModule]));
    }

    private function accessLabel(string $access): string
    {
        return match ($access) {
            AccessManager::VIEW => $this->translator->trans('View'),
            AccessManager::CREATE => $this->translator->trans('Create'),
            AccessManager::UPDATE => $this->translator->trans('Update'),
            AccessManager::DELETE => $this->translator->trans('Delete'),
            default => $access,
        };
    }

    private function audienceLabel(string $audience): string
    {
        return $this->translator->trans(match ($audience) {
            AiAudience::Public->value => 'Your visitors',
            AiAudience::Customer->value => 'Your customers',
            AiAudience::Admin->value => 'Your team (back-office)',
            default => 'Thelia itself (internal)',
        });
    }

    private function riskLabel(AiToolRisk $risk): string
    {
        return $this->translator->trans(match ($risk) {
            AiToolRisk::PrivateData => 'Private data',
            AiToolRisk::UntrustedContent => 'Untrusted content',
            AiToolRisk::ExternalCommunication => 'Way out of the shop',
        });
    }
}
