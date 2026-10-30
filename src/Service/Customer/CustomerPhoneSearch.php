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

namespace BackOfficeDefaultTwigBundle\Service\Customer;

use Thelia\Model\Map\CustomerTableMap;

/**
 * Finds a customer by a phone number of one of their addresses, however it was typed.
 *
 * Numbers are stored the way they were entered, most often in the international form
 * (+33 6 12 34 56 78), and searched the way they are read (06 12 34 56 78): both sides
 * are compared on their digits only. The leading zeros of the searched number are the
 * national or the international prefix, so they are dropped and the rest is looked up
 * anywhere in the stored digits, which also lets a partial number match.
 */
final class CustomerPhoneSearch
{
    /** Below this many digits, a search box term would match most of the customers. */
    public const SEARCH_BOX_MINIMUM_DIGITS = 4;

    /** What a phone number is typed with besides its digits. */
    private const SEPARATORS = [' ', '.', '-', '+', '(', ')', '/'];

    /**
     * The digits to look for, or null when the term is not a phone number: a letter
     * or any other character, or fewer than $minimumDigits digits once the leading
     * zeros are dropped.
     */
    public static function digits(string $term, int $minimumDigits = 1): ?string
    {
        $digits = str_replace(self::SEPARATORS, '', $term);
        if (preg_match('/^\d+$/', $digits) !== 1) {
            return null;
        }

        $digits = ltrim($digits, '0');

        return \strlen($digits) >= $minimumDigits ? $digits : null;
    }

    /**
     * The pattern bound to the single parameter of clause().
     */
    public static function pattern(string $digits): string
    {
        return '%'.$digits.'%';
    }

    /**
     * A condition on the customer table, true when the landline or the mobile of one of
     * the customer's addresses holds the digits bound to its single parameter. The two
     * numbers are joined on a separator no digit run can cross.
     */
    public static function clause(): string
    {
        return 'EXISTS (SELECT 1 FROM address phone_address WHERE phone_address.customer_id = '.CustomerTableMap::COL_ID
            .' AND CONCAT_WS(\'|\', '.self::storedDigits('phone_address.phone').', '.self::storedDigits('phone_address.cellphone').') LIKE ?)';
    }

    private static function storedDigits(string $column): string
    {
        $expression = $column;
        foreach (self::SEPARATORS as $separator) {
            $expression = 'REPLACE('.$expression.', \''.$separator.'\', \'\')';
        }

        return $expression;
    }
}
