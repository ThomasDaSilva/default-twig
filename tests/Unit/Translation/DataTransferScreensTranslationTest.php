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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The exports, the imports and the background jobs screens speak every language the
 * back office is translated into: a text missing from a catalogue reads in English
 * there. English is the text of the template itself.
 */
final class DataTransferScreensTranslationTest extends TestCase
{
    private const SCREENS = ['export', 'import', 'configuration/background-jobs'];

    #[DataProvider('translatedLocales')]
    public function testEveryTextOfTheScreensIsTranslated(string $locale): void
    {
        $catalogue = require \dirname(__DIR__, 3).'/translations/messages.'.$locale.'.php';

        $missing = array_values(array_filter(self::texts(), static fn (string $text): bool => !isset($catalogue[$text])));

        self::assertSame([], $missing, 'Missing from messages.'.$locale.'.php');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function translatedLocales(): iterable
    {
        foreach (['cs_CZ', 'de_DE', 'es_ES', 'fr_FR', 'it_IT', 'nl_NL', 'ru_RU'] as $locale) {
            yield $locale => [$locale];
        }
    }

    /**
     * @return list<string> the texts the templates of the screens pass to |trans
     */
    private static function texts(): array
    {
        $texts = [];

        foreach (self::SCREENS as $screen) {
            foreach (glob(\dirname(__DIR__, 3).'/'.$screen.'/*.html.twig') ?: [] as $template) {
                preg_match_all("/'((?:[^'\\\\]|\\\\.)+)'\\s*\\|\\s*trans\\b/", (string) file_get_contents($template), $matches);
                array_push($texts, ...array_map('stripslashes', $matches[1]));
            }
        }

        return array_values(array_unique($texts));
    }
}
