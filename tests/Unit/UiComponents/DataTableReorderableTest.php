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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\UiComponents;

use BackOfficeDefaultTwigBundle\UiComponents\DataTable\DataTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A table is reorderable when its body is driven by the `bo-sortable` controller, alone or among others. */
final class DataTableReorderableTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function bodies(): iterable
    {
        yield 'no attribute' => [[], false];
        yield 'another controller' => [['data-controller' => 'bo-bulk-select'], false];
        yield 'a controller sharing the prefix' => [['data-controller' => 'bo-sortable-extra'], false];
        yield 'the sortable controller' => [['data-controller' => 'bo-sortable'], true];
        yield 'the sortable controller among others' => [['data-controller' => "bo-bulk-select  bo-sortable\tbo-other"], true];
    }

    /**
     * @param array<string, string> $tbodyAttributes
     */
    #[DataProvider('bodies')]
    public function testTheTableIsReorderableWhenItsBodyIsDrivenBySortable(array $tbodyAttributes, bool $reorderable): void
    {
        $table = new DataTable();
        $table->tbodyAttributes = $tbodyAttributes;

        self::assertSame($reorderable, $table->isReorderable());
    }
}
