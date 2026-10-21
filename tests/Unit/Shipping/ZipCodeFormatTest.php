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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Shipping;

use BackOfficeDefaultTwigBundle\Service\Shipping\ZipCodeFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Country::getZipCodeRE() reads N as a digit, L as a letter, C as any characters, and copies
 * every other character unescaped into a regular expression.
 */
final class ZipCodeFormatTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, bool, bool}>
     */
    public static function formats(): iterable
    {
        yield 'no format' => [null, true, true];
        yield 'a blank format' => ['  ', true, true];
        yield 'a format the forms do not check' => ['NNNNN', false, true];
        yield 'any characters then digits' => ['CNNN', true, true];
        yield 'two free parts' => ['CC NNN', true, true];
        yield 'a dot, any character to a regular expression' => ['NN.NNN', true, true];
        yield 'a quantifier' => ['N+', true, true];
        yield 'digits only' => ['NNNNN', true, false];
        yield 'letters, digits and a space' => ['LNL NLN', true, false];
        yield 'a hyphen, copied as itself' => ['NNNN-NNN', true, false];
    }

    #[DataProvider('formats')]
    public function testAFormatLeavesCodesUncheckedWhenItLeavesFreeText(?string $format, bool $required, bool $unchecked): void
    {
        self::assertSame($unchecked, ZipCodeFormat::leavesCodesUnchecked($format, $required));
    }
}
