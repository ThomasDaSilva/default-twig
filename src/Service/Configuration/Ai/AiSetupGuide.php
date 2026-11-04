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

use Thelia\Domain\Ai\AiJurisdiction;
use Thelia\Domain\Ai\AiProvider;
use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\Service\AiFailureExplainer;
use Thelia\Domain\Ai\Service\AiSettings;
use Thelia\Model\AiConnection;

/**
 * Where the AI setup of the shop stands, in three steps a merchant can follow without knowing how the AI layer is
 * built: a connection to a provider, a model for each range the features use, and the features that can run. The
 * screen only draws what this service computes; every write goes through the admin services the other tabs use.
 *
 * The AI features of Thelia itself (audience "system", such as the topic check a guardrail asks for) are not
 * listed: they are not something a merchant turns on, and the range they need would be asked for nothing.
 */
final readonly class AiSetupGuide
{
    /** The providers the first step offers: the ones whose address and place of hosting Thelia knows. */
    public const array CONNECTABLE_PROVIDERS = [AiProvider::Mistral, AiProvider::Anthropic, AiProvider::OpenAi];

    /**
     * The model suggested for each range, by provider. A name is only proposed when the models of the connection
     * list it (AiTierAdmin::choices() reads the shipped catalogue), and the merchant always confirms.
     */
    public const array SUGGESTED_MODELS = [
        'mistral' => [
            'fast' => 'mistral-small-latest',
            'standard' => 'mistral-medium-latest',
            'advanced' => 'mistral-large-latest',
        ],
        'anthropic' => [
            'fast' => 'claude-haiku-5-5',
            'standard' => 'claude-sonnet-5-5',
            'advanced' => 'claude-opus-5-5',
        ],
        'openai' => [
            'fast' => 'gpt-5-nano',
            'standard' => 'gpt-5.4-mini',
            'advanced' => 'gpt-5.5',
        ],
    ];

    /** What stops a feature from running for good, as opposed to a switch the merchant turned off on purpose. */
    private const array BLOCKING_REASONS = [AiUsageAdmin::REASON_GLOBAL_OFF, AiUsageAdmin::REASON_NO_MODEL, AiUsageAdmin::REASON_NO_BUDGET];

    private const string SYSTEM_AUDIENCE = 'system';

    public function __construct(
        private AiUsageAdmin $usages,
        private AiTierAdmin $tiers,
        private AiConnectionAdmin $connections,
        private AiSettings $settings,
        private AiLabels $labels,
        private AiFailureExplainer $failures,
    ) {
    }

    /**
     * The id of the row of a feature in the page, which a module links to.
     */
    public static function rowId(string $usageId): string
    {
        return 'ai-setup-usage-'.str_replace('.', '-', $usageId);
    }

    /**
     * How many features cannot run until the merchant does something: the master switch is off, no model serves
     * them, or they talk to visitors without a budget. A feature the merchant switched off is not counted.
     */
    public function pendingCount(?string $locale = null): int
    {
        return \count(array_filter($this->features($locale), static fn (array $row): bool => \in_array($row['inactive_reason'], self::BLOCKING_REASONS, true)));
    }

    /**
     * @param string|null $focusedUsage the id of the feature to point at, as a module links to it
     *
     * @return array{
     *     done: int, total: int, complete: bool,
     *     connection: array{done: bool, connections: list<array<string, mixed>>, providers: list<array<string, mixed>>},
     *     models: array{done: bool, available: bool, required: list<array<string, mixed>>, optional: list<array<string, mixed>>, choices: array<string, array<string, string>>},
     *     features: array{done: bool, rows: list<array<string, mixed>>, ai_enabled: bool}
     * }
     */
    public function overview(?string $locale = null, ?string $focusedUsage = null): array
    {
        $features = $this->features($locale);
        $connections = $this->connectionViews($locale);
        $choices = $this->tiers->choices();
        [$required, $optional] = $this->tierViews($features, $choices);

        $connectionDone = [] !== array_filter($connections, static fn (array $connection): bool => $connection['active'] && 'failed' !== $connection['status']);
        $modelsDone = [] !== $features && [] === array_filter($required, static fn (array $tier): bool => !$tier['done']);
        $rows = array_map(fn (array $row): array => $this->featureView($row, $focusedUsage), $features);
        $featuresDone = [] !== array_filter($rows, static fn (array $row): bool => $row['ready'])
            && [] === array_filter($rows, static fn (array $row): bool => $row['blocked']);
        $done = (int) $connectionDone + (int) $modelsDone + (int) $featuresDone;

        return [
            'done' => $done,
            'total' => 3,
            'complete' => 3 === $done,
            'connection' => [
                'done' => $connectionDone,
                'connections' => $connections,
                'providers' => $this->providerViews(),
            ],
            'models' => [
                'done' => $modelsDone,
                'available' => [] !== $choices,
                'required' => $required,
                'optional' => $optional,
                'choices' => $choices,
            ],
            'features' => [
                'done' => $featuresDone,
                'rows' => $rows,
                'ai_enabled' => $this->settings->isEnabled(),
            ],
        ];
    }

    /**
     * The rows of the features of the modules, without the ones Thelia keeps for itself.
     *
     * @return list<array<string, mixed>>
     */
    private function features(?string $locale): array
    {
        return array_values(array_filter($this->usages->rows($locale), static fn (array $row): bool => self::SYSTEM_AUDIENCE !== $row['audience']));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function connectionViews(?string $locale): array
    {
        return array_map(function (AiConnection $connection) use ($locale): array {
            $checkError = (string) $connection->getCheckError();
            $checkedAt = $connection->getCheckedAt();

            return [
                'id' => (int) $connection->getId(),
                'title' => (string) $connection->getTitle(),
                'provider' => $this->labels->provider($connection->getProvider()),
                'active' => (bool) $connection->getActive(),
                'status' => null === $checkedAt ? 'never' : ('' === $checkError ? 'worked' : 'failed'),
                'checked_at' => $checkedAt?->format('Y-m-d H:i'),
                'error' => '' === $checkError ? null : $this->failures->explainCheckError($checkError, $locale),
            ];
        }, $this->connections->all());
    }

    /**
     * @return list<array{value: string, label: string, european_union: bool, disabled: bool}>
     */
    private function providerViews(): array
    {
        $europeanUnionOnly = $this->connections->isEuropeanUnionOnly();

        return array_map(function (AiProvider $provider) use ($europeanUnionOnly): array {
            $europeanUnion = AiJurisdiction::EuropeanUnion === $provider->defaultJurisdiction();

            return [
                'value' => $provider->value,
                'label' => $this->labels->provider($provider),
                'european_union' => $europeanUnion,
                'disabled' => $europeanUnionOnly && !$europeanUnion,
            ];
        }, self::CONNECTABLE_PROVIDERS);
    }

    /**
     * The three ranges, split in the ones the features need now and the others.
     *
     * @param list<array<string, mixed>>           $features
     * @param array<string, array<string, string>> $choices
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function tierViews(array $features, array $choices): array
    {
        $served = array_merge([], ...array_values($choices));
        $required = [];
        $optional = [];

        foreach (AiTier::cases() as $tier) {
            $users = array_values(array_filter($features, fn (array $row): bool => $tier->value === $row['tier'] && $this->needsARange($row)));
            $choice = $this->tiers->choiceOf($tier);
            $current = null !== $choice && \in_array($choice, $served, true) ? $choice : null;
            $suggestion = null === $current ? $this->suggestionFor($tier) : null;
            $view = [
                'tier' => $tier->value,
                'label' => $this->labels->tier($tier),
                'help' => $this->labels->tierHelp($tier),
                'used_by' => array_map(static fn (array $row): string => (string) $row['purpose'], $users),
                'current' => $current,
                'current_label' => null === $current ? null : (string) array_search($current, $served, true),
                'selected' => $current ?? $suggestion,
                'suggested' => null !== $suggestion,
                'done' => null !== $current,
            ];

            if ([] === $users) {
                $optional[] = $view;
            } else {
                $required[] = $view;
            }
        }

        return [$required, $optional];
    }

    /**
     * Whether a feature runs on the model of its range: not when the merchant switched it off, nor when a model
     * is pinned to it.
     *
     * @param array<string, mixed> $row
     */
    private function needsARange(array $row): bool
    {
        return AiUsageAdmin::REASON_SWITCHED_OFF !== $row['inactive_reason'] && null === $this->usages->pinnedChoiceOf((string) $row['id']);
    }

    /**
     * The model to propose for a range: the one of the table for the first active connection of a provider it
     * knows, when that connection lists it.
     */
    private function suggestionFor(AiTier $tier): ?string
    {
        $served = array_merge([], ...array_values($this->tiers->choices()));

        foreach ($this->connections->all() as $connection) {
            $name = self::SUGGESTED_MODELS[(string) $connection->getProvider()][$tier->value] ?? null;
            $choice = null === $name ? null : $connection->getId().'|'.$name;

            if ($connection->getActive() && null !== $choice && \in_array($choice, $served, true)) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function featureView(array $row, ?string $focusedUsage): array
    {
        $reason = $row['inactive_reason'];

        return [
            'id' => (string) $row['id'],
            'html_id' => self::rowId((string) $row['id']),
            'purpose' => (string) $row['purpose'],
            'module' => (string) $row['module'],
            'audience' => $this->labels->audience((string) $row['audience']),
            'tier' => $this->labels->tierName((string) $row['tier']),
            'reason' => $reason,
            'ready' => null === $reason,
            'blocked' => \in_array($reason, self::BLOCKING_REASONS, true),
            'budget' => $row['budget'],
            'focused' => $focusedUsage === $row['id'],
        ];
    }
}
