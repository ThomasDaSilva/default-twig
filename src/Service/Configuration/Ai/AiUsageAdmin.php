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

use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\AiUsageRegistryInterface;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;
use Thelia\Domain\Ai\Gateway\AiGateway;
use Thelia\Domain\Ai\Guardrail\AiGuardrailRunner;
use Thelia\Domain\Ai\Guardrail\GuardrailMode;
use Thelia\Domain\Ai\Provider\AbstractPlatformAdapter;
use Thelia\Domain\Ai\Provider\AiTargetResolver;
use Thelia\Domain\Ai\Service\AiRateLimiter;
use Thelia\Domain\Ai\Service\AiSettings;
use Thelia\Domain\Ai\Service\AiSettingsWriter;
use Thelia\Domain\Ai\Service\AiUsageRegister;
use Thelia\Model\AiModelQuery;
use Thelia\Model\AiUsageSettingQuery;

/**
 * What the screens show of the AI usages the modules declared, and what they write: the switch, the range, the
 * monthly budget, the pinned model, the two Article 50 switches, the optional guardrails, the limits and the
 * privacy of the prompts of a usage. Writes go through the writer of the
 * core, so the screen cannot differ from the API; a refusal comes back as an AI exception.
 */
final readonly class AiUsageAdmin
{
    public const string REASON_GLOBAL_OFF = 'global_off';
    public const string REASON_SWITCHED_OFF = 'switched_off';
    public const string REASON_NO_BUDGET = 'no_budget';

    public const string SECTION_GENERAL = 'general';
    public const string SECTION_GUARDRAILS = 'guardrails';
    public const string SECTION_LIMITS = 'limits';
    public const string SECTION_PRIVACY = 'privacy';

    public function __construct(
        private AiUsageRegister $register,
        private AiSettings $settings,
        private AiTargetResolver $targets,
        private AiSettingsWriter $writer,
        private AiTierAdmin $tiers,
        private AiUsageRegistryInterface $registry,
        private AiGuardrailRunner $guardrails,
    ) {
    }

    /**
     * The rows of the register, as the export gives them, with what the merchant decided for each usage.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(?string $locale = null): array
    {
        return array_map($this->enrich(...), $this->register->rows($locale));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function row(string $usageId, ?string $locale = null): ?array
    {
        foreach ($this->rows($locale) as $row) {
            if ($row['id'] === $usageId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>> the rows of the register, exactly as the export gives them
     */
    public function register(?string $locale = null): array
    {
        return $this->register->rows($locale);
    }

    /**
     * @param array<string, mixed> $row a row of the register
     *
     * @return array<string, mixed> the same row and, for the screen, where the usage runs now (provider and model, null when nothing serves it)
     */
    public function withTarget(array $row): array
    {
        try {
            $target = $this->targets->resolve((string) $row['id']);
            $row['provider'] = $target->provider->value;
            $row['model'] = $target->model->name;
        } catch (AiExceptionInterface) {
            $row['provider'] = null;
            $row['model'] = null;
        }

        return $row;
    }

    public function isSwitchedOn(string $usageId): bool
    {
        return (bool) (AiUsageSettingQuery::create()->findOneByUsageId($usageId)?->getEnabled() ?? true);
    }

    /**
     * @return string|null the range the merchant chose for the usage, null when it follows the one the module declared
     */
    public function chosenTierOf(string $usageId): ?string
    {
        $tier = AiUsageSettingQuery::create()->findOneByUsageId($usageId)?->getTier();

        return null === $tier || null === AiTier::tryFrom($tier) ? null : $tier;
    }

    public function budgetOf(string $usageId): ?string
    {
        $budget = AiUsageSettingQuery::create()->findOneByUsageId($usageId)?->getMonthlyBudget();

        return null === $budget ? null : rtrim(rtrim((string) $budget, '0'), '.');
    }

    public function pinnedChoiceOf(string $usageId): ?string
    {
        $setting = AiUsageSettingQuery::create()->findOneByUsageId($usageId);
        $model = null === $setting?->getPinnedModelId() ? null : AiModelQuery::create()->findPk($setting->getPinnedModelId());

        return null === $setting || null === $model || null === $setting->getPinnedConnectionId() ? null : $setting->getPinnedConnectionId().'|'.$model->getName();
    }

    /**
     * @return array<string, array<string, string>> the models a usage can be pinned to
     */
    public function modelChoices(): array
    {
        return $this->tiers->choices();
    }

    /**
     * @return array<string, int|bool|string|null> what the merchant set (null: nothing, the default applies) and, for the screen, the default of each limit
     */
    public function limitsOf(string $usageId): array
    {
        $setting = AiUsageSettingQuery::create()->findOneByUsageId($usageId);
        $usage = $this->registry->get($usageId);
        [$perMinute, $perDay] = AiRateLimiter::DEFAULTS[$usage->audience->value];

        return [
            'requests_per_minute' => $setting?->getRequestsPerMinute(),
            'requests_per_day' => $setting?->getRequestsPerDay(),
            'max_output_tokens' => $setting?->getMaxOutputTokens(),
            'max_input_characters' => $setting?->getMaxInputCharacters(),
            'store_prompt' => (bool) $setting?->getStorePrompt(),
            'prompt_retention_days' => $setting?->getPromptRetentionDays(),
            'default_requests_per_minute' => $perMinute,
            'default_requests_per_day' => $perDay,
            'default_max_output_tokens' => AbstractPlatformAdapter::DEFAULT_OUTPUT_TOKENS,
            'default_max_input_characters' => $usage->maxInputCharacters,
            'default_prompt_retention_days' => AiGateway::DEFAULT_PROMPT_RETENTION_DAYS,
        ];
    }

    /**
     * The optional guardrails the screen lists, with what the usage does now with each.
     *
     * @return list<array{name: string, declared: string|null, current: string, installed: bool}>
     */
    public function guardrailRows(string $usageId): array
    {
        return AiGuardrailSelection::rows($this->declaredGuardrails($usageId), $this->chosenGuardrails($usageId), $this->installedGuardrails());
    }

    /**
     * Writes what the merchant decided for a usage. A section the form did not send (null) keeps what is stored, and
     * so does a field missing from a section: only a field sent empty goes back to the default of the core.
     *
     * @param string|null                                  $tier             the range the merchant chose, null or empty to follow the one the module declared
     * @param string|null                                  $budget           a decimal in US dollars, null or empty for none
     * @param string|null                                  $pinnedChoice     "connection id|model name" of modelChoices(), null to follow the range
     * @param array<string, int|null>|null                 $limits           requests_per_minute, requests_per_day, max_output_tokens, max_input_characters
     * @param array<string, string>|null                   $guardrailChoices guardrail name => follow, block, annotate or off
     * @param array{store_prompt: bool, prompt_retention_days?: int|null}|null $privacy
     *
     * @throws AiExceptionInterface      when the core refuses a value
     * @throws \InvalidArgumentException when no guardrail would stay applied
     */
    public function save(string $usageId, bool $enabled, ?string $tier, ?string $budget, ?string $pinnedChoice, bool $notice, bool $mark, ?array $limits = null, ?array $guardrailChoices = null, ?array $privacy = null): void
    {
        $existing = AiUsageSettingQuery::create()->findOneByUsageId($usageId);
        $pinnedModel = null === $pinnedChoice || '' === $pinnedChoice ? null : $this->tiers->resolve($pinnedChoice);
        $limits ??= [];
        $pick = static fn (string $key, ?int $stored): ?int => \array_key_exists($key, $limits) ? $limits[$key] : $stored;

        // The writer takes the whole row: what the screen does not edit is read back so it survives.
        $this->writer->saveUsageSetting(
            $existing,
            $usageId,
            $enabled,
            $tier,
            $budget,
            $existing?->getCurrency() ?? 'USD',
            $pick('requests_per_minute', $existing?->getRequestsPerMinute()),
            $pick('requests_per_day', $existing?->getRequestsPerDay()),
            $pick('max_output_tokens', $existing?->getMaxOutputTokens()),
            $pick('max_input_characters', $existing?->getMaxInputCharacters()),
            null === $pinnedModel ? null : (int) explode('|', (string) $pinnedChoice, 2)[0],
            $pinnedModel?->getId(),
            null === $guardrailChoices ? ($existing?->getGuardrailMap() ?? []) : AiGuardrailSelection::map($this->declaredGuardrails($usageId), $this->chosenGuardrails($usageId), $this->installedGuardrails(), $guardrailChoices),
            $notice,
            $mark,
            null === $privacy ? (bool) $existing?->getStorePrompt() : $privacy['store_prompt'],
            null === $privacy || !\array_key_exists('prompt_retention_days', $privacy) ? $existing?->getPromptRetentionDays() : $privacy['prompt_retention_days'],
        );
    }

    /**
     * @return array<string, string> the guardrails the merchant set on the usage, name => mode; empty when the usage follows its module
     */
    private function chosenGuardrails(string $usageId): array
    {
        return AiUsageSettingQuery::create()->findOneByUsageId($usageId)?->getGuardrailMap() ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function declaredGuardrails(string $usageId): array
    {
        return array_map(static fn (GuardrailMode $mode): string => $mode->value, $this->registry->get($usageId)->guardrails);
    }

    /**
     * @return list<string>
     */
    private function installedGuardrails(): array
    {
        return array_map(strval(...), array_keys($this->guardrails->availability()));
    }

    /**
     * The reason a usage does not run, as a code the screen words; null when it runs.
     *
     * @param array<string, mixed> $row
     */
    public function inactiveReason(array $row): ?string
    {
        if (true === $row['active']) {
            return null;
        }

        if (!$this->settings->isEnabled()) {
            return self::REASON_GLOBAL_OFF;
        }

        $setting = AiUsageSettingQuery::create()->findOneByUsageId((string) $row['id']);

        return null !== $setting && !$setting->getEnabled() ? self::REASON_SWITCHED_OFF : self::REASON_NO_BUDGET;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function enrich(array $row): array
    {
        $row['inactive_reason'] = $this->inactiveReason($row);
        $row['budget'] = $this->budgetOf((string) $row['id']);

        return $row;
    }
}
