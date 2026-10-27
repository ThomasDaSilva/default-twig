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

namespace BackOfficeDefaultTwigBundle\Service\I18n;

/**
 * The flag emoji of a country, as the lists and the filters of the back office show it.
 */
final class CountryFlag
{
    /**
     * The flag of an ISO 3166-1 alpha-2 code, or an empty string for anything else.
     */
    public static function of(?string $iso): string
    {
        if ($iso === null || preg_match('/^[A-Za-z]{2}$/', $iso) !== 1) {
            return '';
        }

        $upper = strtoupper($iso);
        $offset = 0x1F1E6 - \ord('A');

        return mb_chr(\ord($upper[0]) + $offset).mb_chr(\ord($upper[1]) + $offset);
    }

    /**
     * The flag followed by a space, to write before a name; an empty string when there is no flag.
     */
    public static function prefix(?string $iso): string
    {
        $flag = self::of($iso);

        return $flag === '' ? '' : $flag.' ';
    }
}
