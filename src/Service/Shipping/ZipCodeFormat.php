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
 * What the postal code format of a country leaves unchecked, as the address forms read it.
 *
 * Country::getZipCodeRE() turns the format into a regular expression: N is a digit, L a
 * letter, C any characters (".+"), and every other character is copied as it is, without
 * being escaped. A format therefore checks nothing when there is none, when the postal code
 * is not required (the forms then skip the check), and lets free text through where it
 * holds C or a character a regular expression reads as more than itself.
 */
final class ZipCodeFormat
{
    /**
     * The characters a regular expression reads as more than themselves.
     */
    public const string FREE_TEXT_CHARACTERS = '.*?+|[](){}\\';

    public static function leavesCodesUnchecked(?string $format, bool $required): bool
    {
        $format = trim((string) $format);

        if ('' === $format || !$required) {
            return true;
        }

        return str_contains($format, 'C') || false !== strpbrk($format, self::FREE_TEXT_CHARACTERS);
    }
}
