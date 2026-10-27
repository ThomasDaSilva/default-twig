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

namespace BackOfficeDefaultTwigBundle\Tests\Twig;

use BackOfficeDefaultTwigBundle\Twig\DataTableExtension;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use PHPUnit\Framework\Attributes\DataProvider;
use Thelia\Test\IntegrationTestCase;
use Twig\Environment;

/**
 * The glyph of a row action comes from its kind. Sending a document and switching a row on or off
 * are actions of many lists: without a kind of their own they fell back to the three dots of a menu.
 */
final class RowActionKindTest extends IntegrationTestCase
{
    private const string CELL = '@BackOfficeDefaultTwig/components/DataTable/cells/actions.html.twig';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function kinds(): iterable
    {
        yield 'send by email' => ['send', 'bi-envelope'];
        yield 'activate a row that is off' => ['activate', 'bi-toggle-off'];
        yield 'deactivate a row that is on' => ['deactivate', 'bi-toggle-on'];
    }

    #[DataProvider('kinds')]
    public function testAKindHasItsOwnGlyph(string $kind, string $icon): void
    {
        $html = $this->renderActions([new RowAction(kind: $kind, label: 'Label')]);

        self::assertStringContainsString('bo-action--'.$kind, $html);
        self::assertStringContainsString($icon, $html);
        self::assertStringNotContainsString('bi-three-dots', $html);
    }

    public function testAnUnknownKindStillFallsBackToTheCustomGlyph(): void
    {
        self::assertStringContainsString('bi-three-dots', $this->renderActions([new RowAction(kind: 'unknown', label: 'Label')]));
    }

    /**
     * @param list<RowAction> $actions
     */
    private function renderActions(array $actions): string
    {
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render(self::CELL, [
            'column' => (new DataTableExtension())->actionsColumn(),
            'row' => [],
            'value' => $actions,
        ]);
    }
}
