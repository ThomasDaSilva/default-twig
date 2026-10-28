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

/**
 * Text written by an AI, made safe to read before a decision. The controls of the direction of the text and the
 * characters of no width change what a line looks like without changing what it says ("Trojan Source"): each one is
 * shown as its code point, such as ⟨U+202E⟩. Letters with accents and every other character stay as they are.
 */
final class AiVisibleText
{
    private const HIDDEN = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';

    public static function reveal(string $text): string
    {
        return preg_replace_callback(self::HIDDEN, static fn (array $found): string => \sprintf('⟨U+%04X⟩', mb_ord($found[0])), $text) ?? $text;
    }
}
