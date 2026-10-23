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
 * The flag emoji of a country, written before a customer name as the order list does.
 */
final class CountryFlag
{
    /**
     * The flag of an ISO 3166-1 alpha-2 code followed by a space, or an empty string for
     * anything else.
     */
    public static function prefix(?string $iso): string
    {
        if ($iso === null || \strlen($iso) !== 2 || !ctype_alpha($iso)) {
            return '';
        }

        $upper = strtoupper($iso);
        $offset = 0x1F1E6 - \ord('A');

        return mb_chr(\ord($upper[0]) + $offset).mb_chr(\ord($upper[1]) + $offset).' ';
    }
}
