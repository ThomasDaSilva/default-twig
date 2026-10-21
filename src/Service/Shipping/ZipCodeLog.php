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

namespace BackOfficeDefaultTwigBundle\Service\Shipping;

/**
 * The merchant's text as the administration log carries it, for the shipping zones and their
 * postal code rules: a zone name, a pattern. Escaped as the core escapes a postal code pattern
 * for its own log (ZipCodePattern::forLog()) when it knows how, control characters and the
 * backslash escaped otherwise.
 */
final class ZipCodeLog
{
    private const ZIP_CODE_PATTERN = 'Thelia\\Domain\\Shipping\\ZipCode\\ZipCodePattern';

    public static function escape(string $text): string
    {
        $zipCodePattern = self::zipCodePatternClass();

        if (method_exists($zipCodePattern, 'forLog')) {
            $escaped = \call_user_func([$zipCodePattern, 'forLog'], $text);

            if (\is_string($escaped)) {
                return $escaped;
            }
        }

        return addcslashes($text, "\0..\37\177\\");
    }

    /**
     * The class of the core, by a name the static analysis does not narrow: a core older
     * than ZipCodePattern::forLog() has the class without it.
     */
    private static function zipCodePatternClass(): string
    {
        return self::ZIP_CODE_PATTERN;
    }
}
