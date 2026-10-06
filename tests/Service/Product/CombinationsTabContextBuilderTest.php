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

namespace BackOfficeDefaultTwigBundle\Tests\Service\Product;

use BackOfficeDefaultTwigBundle\Service\Product\CombinationsTabContextBuilder;
use Thelia\Model\Attribute;
use Thelia\Model\AttributeAv;
use Thelia\Model\AttributeTemplate;
use Thelia\Model\Currency;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The combinations grid of the product page. A shop migrated from Thelia 2 has
 * every combination at position 0, so the grid read them in the order of their
 * ids. Thelia 2 read them by attribute position, then attribute value position.
 */
final class CombinationsTabContextBuilderTest extends IntegrationTestCase
{
    private FixtureFactory $factory;

    private Currency $currency;

    private Product $product;

    private Attribute $size;

    private Attribute $color;

    /** @var array<string, AttributeAv> */
    private array $values = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
        $this->currency = $this->factory->currency();
        $this->product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->currency,
        );

        // Size comes before color in the template, but color was created first.
        $this->color = $this->factory->attribute(['title' => 'Color']);
        $this->size = $this->factory->attribute(['title' => 'Size']);
        $this->color->setPosition(1)->save();
        $this->size->setPosition(2)->save();
        $this->givenTemplateOrder($this->size, $this->color);

        // Values are created in the reverse of their position.
        $this->values['red'] = $this->givenValue($this->color, 'Red', 2);
        $this->values['blue'] = $this->givenValue($this->color, 'Blue', 1);
        $this->values['xl'] = $this->givenValue($this->size, 'XL', 3);
        $this->values['m'] = $this->givenValue($this->size, 'M', 2);
        $this->values['s'] = $this->givenValue($this->size, 'S', 1);
    }

    public function testTiedCombinationsFollowTheAttributePositionThenTheValuePosition(): void
    {
        $xlRed = $this->givenCombination(0, 'xl', 'red');
        $mBlue = $this->givenCombination(0, 'm', 'blue');
        $sRed = $this->givenCombination(0, 's', 'red');
        $sBlue = $this->givenCombination(0, 's', 'blue');

        self::assertSame(
            [$sBlue, $sRed, $mBlue, $xlRed],
            $this->orderedIds([$xlRed, $mBlue, $sRed, $sBlue]),
        );
    }

    public function testTheOrderDoesNotDependOnTheOrderOfTheCombinationRows(): void
    {
        // The attribute values are linked color first in this combination.
        $xlRed = $this->givenCombination(0, 'red', 'xl');
        $sRed = $this->givenCombination(0, 'red', 's');

        self::assertSame([$sRed, $xlRed], $this->orderedIds([$xlRed, $sRed]));
    }

    public function testTheCombinationPositionStillComesFirst(): void
    {
        $sBlue = $this->givenCombination(3, 's', 'blue');
        $xlRed = $this->givenCombination(1, 'xl', 'red');
        $mBlue = $this->givenCombination(2, 'm', 'blue');

        self::assertSame([$xlRed, $mBlue, $sBlue], $this->orderedIds([$sBlue, $xlRed, $mBlue]));
    }

    public function testCombinationsTiedOnEverythingKeepTheirIdOrder(): void
    {
        $first = $this->givenCombination(0, 's', 'blue');
        $second = $this->givenCombination(0, 's', 'blue');

        self::assertSame([$first, $second], $this->orderedIds([$second, $first]));
    }

    public function testAnAttributeOutsideTheTemplateFallsBackToItsOwnPosition(): void
    {
        $this->product->setTemplateId(null)->save();

        // Color has the lowest own position, so it now leads.
        $sBlue = $this->givenCombination(0, 's', 'blue');
        $sRed = $this->givenCombination(0, 's', 'red');
        $mBlue = $this->givenCombination(0, 'm', 'blue');

        self::assertSame([$sBlue, $mBlue, $sRed], $this->orderedIds([$sRed, $sBlue, $mBlue]));
    }

    public function testACombinationWithoutPriceIsReadUnpricedRatherThanAtZero(): void
    {
        $priced = $this->givenCombination(0, 's', 'blue');
        $unpricedSaleElement = $this->factory->productSaleElement($this->product);
        $this->factory->attributeCombination($unpricedSaleElement, $this->values['m']);
        $unpriced = (int) $unpricedSaleElement->getId();

        $context = (new CombinationsTabContextBuilder())->build($this->product, (int) $this->currency->getId());
        $rows = array_column($context['pse_rows'], null, 'id');

        self::assertNull($rows[$unpriced]['price'], 'A combination without any price has no price to show, not a price of 0.');
        self::assertNull($rows[$unpriced]['sale_price']);
        self::assertSame(10.0, $rows[$priced]['price']);
        self::assertSame(10.0, $rows[$priced]['sale_price']);
    }

    /**
     * @param list<int> $createdIds
     *
     * @return list<int>
     */
    private function orderedIds(array $createdIds): array
    {
        $context = (new CombinationsTabContextBuilder())->build($this->product);

        return array_values(array_filter(
            array_map(static fn (array $row): int => $row['id'], $context['pse_rows']),
            static fn (int $id): bool => \in_array($id, $createdIds, true),
        ));
    }

    private function givenTemplateOrder(Attribute $first, Attribute $second): void
    {
        $template = $this->factory->template();
        foreach ([$first, $second] as $index => $attribute) {
            $entry = new AttributeTemplate();
            $entry->setTemplateId($template->getId());
            $entry->setAttributeId($attribute->getId());
            $entry->setPosition($index + 1);
            $entry->save();
        }
        $this->product->setTemplateId($template->getId())->save();
    }

    private function givenValue(Attribute $attribute, string $title, int $position): AttributeAv
    {
        $value = $this->factory->attributeAv($attribute, ['title' => $title]);
        $value->setPosition($position)->save();

        return $value;
    }

    /**
     * @return int the id of the combination's sale element
     */
    private function givenCombination(int $position, string ...$valueKeys): int
    {
        $pse = $this->factory->productSaleElement($this->product);
        $pse->setPosition($position)->save();
        $this->factory->productPrice($pse, $this->currency);
        foreach ($valueKeys as $key) {
            $this->factory->attributeCombination($pse, $this->values[$key]);
        }

        return (int) $pse->getId();
    }
}
