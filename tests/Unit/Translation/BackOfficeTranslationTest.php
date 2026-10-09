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
use Symfony\Component\Finder\Finder;

/**
 * Every text the back office translates is in its catalogues: one missing reads in
 * English. English is the text of the source itself. French is complete; the other
 * catalogues are still being translated, so each may only lack fewer texts than it did.
 */
final class BackOfficeTranslationTest extends TestCase
{
    /** The texts each catalogue still lacks, as measured when it was last completed. */
    private const KNOWN_GAPS = [
        'ar_SA' => 804,
        'cs_CZ' => 686,
        'de_DE' => 686,
        'el_GR' => 804,
        'es_ES' => 615,
        'fa_IR' => 804,
        'he_IL' => 804,
        'hu_HU' => 804,
        'id_ID' => 804,
        'it_IT' => 615,
        'nl_NL' => 686,
        'pl_PL' => 804,
        'pt_BR' => 804,
        'pt_PT' => 804,
        'ru_RU' => 686,
        'sk_SK' => 804,
        'tr_TR' => 804,
        'uk_UA' => 804,
        'zh_CN' => 804,
    ];

    #[DataProvider('catalogues')]
    public function testNoCatalogueLacksMoreTextsThanItDid(string $locale): void
    {
        $catalogue = require self::root().'/translations/messages.'.$locale.'.php';

        $missing = array_values(array_filter(self::texts(), static fn (string $text): bool => !isset($catalogue[$text])));

        self::assertLessThanOrEqual(self::KNOWN_GAPS[$locale] ?? 0, \count($missing), 'Missing from messages.'.$locale.'.php:'."\n".implode("\n", $missing));
    }

    /**
     * A source the reading misses would leave every catalogue looking complete.
     */
    public function testTheTextsOfEverySourceAreRead(): void
    {
        $texts = self::texts();

        self::assertContains('Ascending', $texts, 'a template between single quotes');
        self::assertContains("You can't do exports, you don't have any serializer that handles this.", $texts, 'a template between double quotes');
        self::assertContains('Waiting', $texts, 'a label of the job status badge');
        self::assertContains('The file of this export is no longer available. Run the export again.', $texts, 'a class of src/');
        self::assertGreaterThan(2000, \count($texts));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function catalogues(): iterable
    {
        foreach (glob(self::root().'/translations/messages.*.php') ?: [] as $catalogue) {
            $locale = substr(basename($catalogue, '.php'), \strlen('messages.'));

            if ('en_US' !== $locale) {
                yield $locale => [$locale];
            }
        }
    }

    /**
     * @return list<string> the texts the templates pass to |trans, the labels of the job
     *                      status badge, and the literal texts the classes of src/ translate
     */
    private static function texts(): array
    {
        $root = self::root();
        $texts = [];
        $templates = (new Finder())->files()->in($root)->exclude(['vendor', 'node_modules', 'tests', 'var', 'public', 'translations'])->name('*.html.twig');

        foreach ($templates as $template) {
            // '…'|trans and "…"|trans, with or without arguments.
            preg_match_all('/(\'|")((?:(?!\1)[^\\\\]|\\\\.)+)\1\s*\|\s*trans\b/', $template->getContents(), $matches);
            array_push($texts, ...array_map('stripslashes', $matches[2]));
        }

        // The badge translates a label it picks from a map.
        if (1 !== preg_match('/set labels = \{([^}]*)\}/', (string) file_get_contents($root.'/fragments/_job_status.html.twig'), $labels)) {
            throw new \LogicException('The labels of the job status badge were not found.');
        }

        preg_match_all("/:\\s*'([^']+)'/", $labels[1], $matches);
        array_push($texts, ...$matches[1]);

        foreach ((new Finder())->files()->in($root.'/src')->name('*.php') as $class) {
            // A literal text only: one built by concatenation is never in a catalogue.
            preg_match_all('/->trans\(\s*(\'|")((?:(?!\1)[^\\\\]|\\\\.)+)\1\s*[,)]/', $class->getContents(), $matches);
            array_push($texts, ...array_map('stripslashes', $matches[2]));
        }

        return array_values(array_unique($texts));
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
