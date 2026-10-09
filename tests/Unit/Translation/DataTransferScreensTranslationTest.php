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
    private const TEMPLATES = [
        'export/*.html.twig',
        'import/*.html.twig',
        'configuration/background-jobs/*.html.twig',
        'fragments/_job_status.html.twig',
    ];

    private const PHP = [
        'src/Controller/*Export*.php',
        'src/Controller/*Import*.php',
        'src/Controller/DataTransfer*.php',
        'src/Controller/Configuration/BackgroundJobs*.php',
        'src/Service/Admin/*Export*.php',
        'src/Service/Admin/*Import*.php',
        'src/Service/Admin/DataTransfer*.php',
        'src/Service/Admin/BackgroundJobs*.php',
    ];

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
     * @return list<string> the texts the screens translate: in their templates, the
     *                      labels of the job status badge, and in the PHP behind them
     */
    private static function texts(): array
    {
        $root = \dirname(__DIR__, 3);
        $texts = [];

        foreach (self::files($root, self::TEMPLATES) as $template) {
            $source = (string) file_get_contents($template);
            // '…'|trans and "…"|trans, with or without arguments.
            preg_match_all('/(\'|")((?:(?!\1)[^\\\\]|\\\\.)+)\1\s*\|\s*trans\b/', $source, $matches);
            array_push($texts, ...array_map('stripslashes', $matches[2]));
        }

        // The badge translates a label it picks from a map.
        preg_match('/set labels = \{([^}]*)\}/', (string) file_get_contents($root.'/fragments/_job_status.html.twig'), $labels);
        preg_match_all("/:\s*'([^']+)'/", $labels[1] ?? '', $matches);
        array_push($texts, ...$matches[1]);

        foreach (self::files($root, self::PHP) as $class) {
            preg_match_all("/->trans\(\s*'((?:[^'\\\\]|\\\\.)+)'/", (string) file_get_contents($class), $matches);
            array_push($texts, ...array_map('stripslashes', $matches[1]));
        }

        return array_values(array_unique($texts));
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private static function files(string $root, array $patterns): array
    {
        $files = [];

        foreach ($patterns as $pattern) {
            array_push($files, ...(glob($root.'/'.$pattern) ?: []));
        }

        return $files;
    }
}
