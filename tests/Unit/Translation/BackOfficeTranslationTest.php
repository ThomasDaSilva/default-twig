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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminFailureMessage;
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
        '#^(export|import|configuration/background-jobs)/.+\\.html\\.twig$#',
        '#^fragments/_job_status\\.html\\.twig$#',
        '#^src/(Controller|Service/Admin)/(.+/)?[^/]*(Export|Import|DataTransfer|BackgroundJobs)[^/]*\\.php$#',
        '#^src/(Controller|Service/Admin)/(.+/)?(DataTransfer|BackgroundJobs)/.+\\.php$#',
    ];

    /** The texts each catalogue lacks, as measured when it was last changed. */
    private const KNOWN_GAPS = [
        'ar_SA' => 812,
        'cs_CZ' => 692,
        'de_DE' => 692,
        'el_GR' => 812,
        'es_ES' => 621,
        'fa_IR' => 812,
        'he_IL' => 812,
        'hu_HU' => 812,
        'id_ID' => 812,
        'it_IT' => 621,
        'nl_NL' => 692,
        'pl_PL' => 812,
        'pt_BR' => 812,
        'pt_PT' => 812,
        'ru_RU' => 692,
        'sk_SK' => 812,
        'tr_TR' => 812,
        'uk_UA' => 812,
        'zh_CN' => 812,
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
     * The message a server error reads as, named by a constant the reading of the
     * sources does not see.
     */
    #[DataProvider('translatedCatalogues')]
    public function testTheServerErrorIsTranslated(string $locale): void
    {
        self::assertSame([], self::missingFrom($locale, [AdminFailureMessage::SERVER_ERROR]));
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
        $templates = self::files($only)->name('*.html.twig');

        foreach ($templates as $template) {
            // '…'|trans and "…"|trans, with or without arguments.
            preg_match_all('/(\'|")((?:(?!\1)[^\\\\]|\\\\.)+)\1\s*\|\s*trans\b/', $template->getContents(), $matches);
            array_push($texts, ...array_map('stripslashes', $matches[2]));

            // The badge translates a label it picks from a map.
            if ('fragments/_job_status.html.twig' === $template->getRelativePathname()) {
                array_push($texts, ...self::badgeLabels($template->getContents()));
            }
        }

        // The patterns of a Finder add up: the classes are those of src/ unless given.
        foreach (self::files($only ?? ['#^src/#'])->name('*.php') as $class) {
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
     * The files of the theme the reading covers, matched on their path under the theme.
     *
     * @param list<string>|null $only patterns of the paths to keep; all by default
     */
    private static function files(?array $only): Finder
    {
        $files = (new Finder())->files()->in(self::root())->exclude(['vendor', 'node_modules', 'tests', 'var', 'public', 'translations']);

        foreach ($only ?? [] as $pattern) {
            $files->path($pattern);
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function badgeLabels(string $template): array
    {
        if (1 !== preg_match('/set labels = \{([^}]*)\}/', $template, $labels)) {
            throw new \LogicException('The labels of the job status badge were not found.');
        }

        preg_match_all("/:\\s*'([^']+)'/", $labels[1], $matches);

        return $matches[1];
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
