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
    /** The files of the background jobs, exports and imports screens, under the theme. */
    private const JOB_SCREENS = [
        '#^(export|import|configuration/background-jobs)/[^/]+\\.html\\.twig$#',
        '#^fragments/_job_status\\.html\\.twig$#',
        '#^src/Controller/([^/]*(Export|Import)[^/]*|DataTransfer[^/]*|Configuration/BackgroundJobs[^/]*)\\.php$#',
        '#^src/Service/Admin/([^/]*(Export|Import)[^/]*|DataTransfer[^/]*|BackgroundJobs[^/]*)\\.php$#',
    ];

    /** The texts each catalogue lacks, as measured when it was last changed. */
    private const KNOWN_GAPS = [
        'ar_SA' => 810,
        'cs_CZ' => 692,
        'de_DE' => 692,
        'el_GR' => 810,
        'es_ES' => 621,
        'fa_IR' => 810,
        'he_IL' => 810,
        'hu_HU' => 810,
        'id_ID' => 810,
        'it_IT' => 621,
        'nl_NL' => 692,
        'pl_PL' => 810,
        'pt_BR' => 810,
        'pt_PT' => 810,
        'ru_RU' => 692,
        'sk_SK' => 810,
        'tr_TR' => 810,
        'uk_UA' => 810,
        'zh_CN' => 810,
    ];

    #[DataProvider('catalogues')]
    public function testEachCatalogueLacksTheTextsItIsKnownToLack(string $locale): void
    {
        $missing = self::missingFrom($locale, self::texts());
        $known = self::KNOWN_GAPS[$locale] ?? 0;

        self::assertLessThanOrEqual($known, \count($missing), 'Missing from messages.'.$locale.'.php:'."\n".implode("\n", $missing));
        // A catalogue that got more complete keeps its gain: the count goes down with it.
        self::assertGreaterThanOrEqual($known, \count($missing), \sprintf('messages.%s.php now lacks %d texts: lower KNOWN_GAPS[\'%s\'] to it.', $locale, \count($missing), $locale));
    }

    /**
     * The screens of the background jobs, the exports and the imports are complete in
     * the languages the back office is translated into, whatever the rest lacks.
     */
    #[DataProvider('translatedCatalogues')]
    public function testTheScreensOfTheJobsAreTranslated(string $locale): void
    {
        $texts = self::texts(self::JOB_SCREENS);

        self::assertNotEmpty($texts);
        self::assertSame([], self::missingFrom($locale, $texts), 'Missing from messages.'.$locale.'.php');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function translatedCatalogues(): iterable
    {
        foreach (['cs_CZ', 'de_DE', 'es_ES', 'fr_FR', 'it_IT', 'nl_NL', 'ru_RU'] as $locale) {
            yield $locale => [$locale];
        }
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
        self::assertContains('Top spenders', $texts, 'a label of a filter of src/');
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
     * @param list<string>|null $only patterns of the paths to read, under the theme; all by default
     *
     * @return list<string> the texts the templates pass to |trans, the labels of the job
     *                      status badge, and the literal texts and labels of the classes of src/
     */
    private static function texts(?array $only = null): array
    {
        $root = self::root();
        $texts = [];
        $templates = (new Finder())->files()->in($root)->exclude(['vendor', 'node_modules', 'tests', 'var', 'public', 'translations'])->name('*.html.twig');

        foreach (self::kept($templates, $only) as $template) {
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

        foreach (self::kept((new Finder())->files()->in($root.'/src')->name('*.php'), $only) as $class) {
            // A literal text only: one built by concatenation is never in a catalogue.
            preg_match_all('/->trans\(\s*(\'|")((?:(?!\1)[^\\\\]|\\\\.)+)\1\s*[,)]/', $class->getContents(), $matches);
            array_push($texts, ...array_map('stripslashes', $matches[2]));
            // The labels of the forms and of the filters, translated where they are shown.
            preg_match_all("/'label'\\s*=>\\s*'((?:[^'\\\\]|\\\\.)+)'/", $class->getContents(), $matches);
            array_push($texts, ...array_map('stripslashes', $matches[1]));
        }

        return array_values(array_unique($texts));
    }

    /**
     * @param iterable<\SplFileInfo> $files
     * @param list<string>|null      $only  patterns of the paths to keep, under the theme
     *
     * @return list<\SplFileInfo>
     */
    private static function kept(iterable $files, ?array $only): array
    {
        $kept = [];

        foreach ($files as $file) {
            $path = substr($file->getPathname(), \strlen(self::root()) + 1);

            if (null === $only || [] !== array_filter($only, static fn (string $pattern): bool => 1 === preg_match($pattern, $path))) {
                $kept[] = $file;
            }
        }

        return $kept;
    }

    /**
     * @param list<string> $texts
     *
     * @return list<string>
     */
    private static function missingFrom(string $locale, array $texts): array
    {
        $catalogue = require self::root().'/translations/messages.'.$locale.'.php';

        return array_values(array_filter($texts, static fn (string $text): bool => !isset($catalogue[$text])));
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
