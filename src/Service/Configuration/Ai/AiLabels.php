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
use Thelia\Domain\Ai\AiJurisdiction;
use Thelia\Domain\Ai\AiProvider;
use Thelia\Domain\Ai\AiTier;

/**
 * The words the AI screens use for the providers, the ranges and the jurisdictions, in plain language.
 */
final readonly class AiLabels
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function provider(AiProvider|string|null $provider): string
    {
        $provider = \is_string($provider) ? AiProvider::tryFrom($provider) : $provider;

        return null === $provider ? '' : $this->translator->trans(match ($provider) {
            AiProvider::Mistral => 'Mistral AI (France)',
            AiProvider::Anthropic => 'Anthropic (Claude)',
            AiProvider::OpenAi => 'OpenAI',
            AiProvider::OpenAiCompatible => 'Compatible service (OpenAI format)',
            AiProvider::Scaleway => 'Scaleway (France)',
            AiProvider::OvhCloud => 'OVHcloud (France)',
            AiProvider::Albert => 'Albert (French State)',
            AiProvider::Ollama => 'Ollama (your own server)',
            AiProvider::TheliaAi => 'Thelia AI',
        });
    }

    public function tier(AiTier $tier): string
    {
        return $this->translator->trans(match ($tier) {
            AiTier::Fast => 'Fast and economical range',
            AiTier::Standard => 'Standard range',
            AiTier::Advanced => 'Advanced range',
        });
    }

    public function tierHelp(AiTier $tier): string
    {
        return $this->translator->trans(match ($tier) {
            AiTier::Fast => 'For quick, simple tasks: chat, sorting, short answers. Pick a small, cheap model.',
            AiTier::Standard => 'For everyday writing and analysis. A good balance between quality and price.',
            AiTier::Advanced => 'For long texts and complex tasks. Pick the strongest model you accept to pay for.',
        });
    }

    public function jurisdiction(?string $jurisdiction): string
    {
        return $this->translator->trans(AiJurisdiction::EuropeanUnion->value === $jurisdiction ? 'In the European Union' : 'Outside the European Union');
    }

    public function tierName(string $tier): string
    {
        $case = AiTier::tryFrom($tier);

        return null === $case ? $tier : $this->tier($case);
    }

    public function audience(string $audience): string
    {
        return $this->translator->trans(match ($audience) {
            'public' => 'Your visitors',
            'customer' => 'Your customers',
            'admin' => 'Your team (back-office)',
            default => 'Thelia itself (internal)',
        });
    }

    public function compliance(string $state): string
    {
        return $this->translator->trans(match ($state) {
            'met' => 'Respects Article 50',
            'not_met' => 'Does not respect Article 50',
            'to_review' => 'To review',
            default => 'No duty',
        });
    }

    public function inactiveReason(string $reason): string
    {
        return $this->translator->trans(match ($reason) {
            AiUsageAdmin::REASON_GLOBAL_OFF => 'AI is switched off for the whole shop (Consumption tab).',
            AiUsageAdmin::REASON_SWITCHED_OFF => 'You switched this usage off.',
            default => 'This usage talks to your visitors, so it stays off until you give it a monthly budget: that protects you from a surprise bill.',
        });
    }
}
