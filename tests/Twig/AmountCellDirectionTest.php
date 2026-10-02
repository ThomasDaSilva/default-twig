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
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\Column;
use Thelia\Test\IntegrationTestCase;
use Twig\Environment;

/**
 * An amount in a table cell of a right-to-left back office.
 *
 * "1 076,40 €" holds no letter: the browser lays its groups out along the direction of
 * the page, and an Arabic administrator reads "€ 40,076 1". A text column marked left to
 * right isolates its value in a left-to-right run; the cell itself keeps the page's
 * direction, so its alignment does not move.
 */
final class AmountCellDirectionTest extends IntegrationTestCase
{
    private const string CELL = '@BackOfficeDefaultTwig/components/DataTable/cells/text.html.twig';

    public function testAnAmountColumnIsolatesItsValueInALeftToRightRun(): void
    {
        $column = (new DataTableExtension())->textColumn('amount', 'Total', 'end', leftToRight: true);

        self::assertSame('<span dir="ltr">1 076,40 €</span>', $this->renderCell($column, '1 076,40 €'));
    }

    public function testAnOrdinaryTextColumnKeepsThePageDirection(): void
    {
        $column = (new DataTableExtension())->textColumn('title', 'Title');

        self::assertSame('Chaise longue', $this->renderCell($column, 'Chaise longue'));
    }

    private function renderCell(Column $column, string $value): string
    {
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return trim($twig->render(self::CELL, ['column' => $column, 'row' => [], 'value' => $value]));
    }
}
