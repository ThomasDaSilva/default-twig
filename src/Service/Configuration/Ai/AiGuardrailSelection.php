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

use Thelia\Domain\Ai\Guardrail\GuardrailMode;

/**
 * How the optional guardrails of a usage are shown and written. The core reads the map the merchant stored as a
 * whole: when it is not empty it replaces the guardrails the module declared, and a name missing from it is not
 * applied. The screen hides that rule behind one choice per guardrail.
 */
final class AiGuardrailSelection
{
    /** The module decides: the mode it declared, or not applied when it declared nothing. */
    public const string FOLLOW = 'follow';
    public const string OFF = 'off';

    private function __construct()
    {
    }

    /**
     * The guardrails the screen lists: the ones the merchant already set, the ones the module declared and the ones
     * installed, in the order they run. `current` is what the usage does now with each.
     *
     * @param array<string, string> $declared name => mode the module declared
     * @param array<string, string> $chosen   name => mode the merchant stored, empty when the usage follows its module
     * @param list<string>          $installed
     *
     * @return list<array{name: string, declared: string|null, current: string, installed: bool}>
     */
    public static function rows(array $declared, array $chosen, array $installed): array
    {
        $rows = [];

        foreach ([...array_keys($chosen), ...array_keys($declared), ...$installed] as $name) {
            $name = (string) $name;

            if (isset($rows[$name])) {
                continue;
            }

            $rows[$name] = [
                'name' => $name,
                'declared' => $declared[$name] ?? null,
                'current' => [] === $chosen ? self::FOLLOW : (null !== GuardrailMode::tryFrom((string) ($chosen[$name] ?? '')) ? $chosen[$name] : self::OFF),
                'installed' => \in_array($name, $installed, true),
            ];
        }

        return array_values($rows);
    }

    /**
     * The map to store. Every choice on "follow" gives an empty map (the module decides); otherwise a guardrail on
     * "follow" is written with the mode the module declared, so choosing one does not drop the others. A name the
     * form did not send keeps what it has now.
     *
     * @param array<string, string> $declared
     * @param array<string, string> $chosen
     * @param list<string>          $installed
     * @param array<string, string> $choices  name => follow, off, block or annotate
     *
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException when nothing would stay applied while the module declared guardrails: the core would read an empty map as "follow the module"
     */
    public static function map(array $declared, array $chosen, array $installed, array $choices): array
    {
        if ([] === array_filter($choices, static fn (string $choice): bool => self::FOLLOW !== $choice)) {
            return [];
        }

        $map = [];

        foreach (self::rows($declared, $chosen, $installed) as $row) {
            $choice = $choices[$row['name']] ?? $row['current'];
            $mode = match ($choice) {
                self::FOLLOW => $row['declared'],
                self::OFF => null,
                default => $choice,
            };

            if (null !== $mode) {
                $map[$row['name']] = $mode;
            }
        }

        if ([] === $map && [] !== $declared) {
            throw new \InvalidArgumentException('At least one guardrail must stay applied: choose "follow the module" for all of them to use its own.');
        }

        return $map;
    }
}
