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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\Service\Catalog\ProductFilters;
use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\Product;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The search of the product list finds a product by the GTIN of one of its combinations,
 * typed as printed under the barcode, and by the start of a manufacturer part number.
 */
final class ProductListIdentifierSearchTest extends IntegrationTestCase
{
    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
    }

    public function testAProductIsFoundByTheGtinOfOneOfItsCombinationsTypedWithSpaces(): void
    {
        $wanted = $this->productWithACombination(['eanCode' => '4006381333931']);
        $other = $this->productWithACombination(['eanCode' => '036000291452']);

        $found = $this->search('4 006381 333931');

        self::assertContains($wanted->getId(), $found);
        self::assertNotContains($other->getId(), $found);
    }

    /**
     * A code stored before the check, as a shop migrated from Thelia 2 holds it, keeps
     * the spaces it was typed with.
     */
    public function testAProductIsFoundByAGtinStoredWithSpacesBeforeTheCheck(): void
    {
        $wanted = $this->productWithACombination([]);
        $combination = ProductSaleElementsQuery::create()->filterByProductId($wanted->getId())->findOne()
            ?? throw new \RuntimeException('The product has no combination.');
        Propel::getWriteConnection(ProductSaleElementsTableMap::DATABASE_NAME)
            ->prepare('UPDATE product_sale_elements SET ean_code = :code WHERE id = :id')
            ->execute(['code' => '4006381 333-931', 'id' => $combination->getId()]);
        ProductSaleElementsTableMap::clearInstancePool();

        foreach (['4006381 333-931', '4006381333931'] as $typed) {
            self::assertContains($wanted->getId(), $this->search($typed), $typed);
        }
    }

    public function testAProductIsFoundByTheStartOfAManufacturerPartNumber(): void
    {
        $marker = 'MPN'.strtoupper(bin2hex(random_bytes(4)));
        $wanted = $this->productWithACombination(['mpn' => $marker.'-B']);
        $other = $this->productWithACombination(['mpn' => 'X-'.$marker]);

        $found = $this->search($marker);

        self::assertContains($wanted->getId(), $found);
        self::assertNotContains($other->getId(), $found, 'The part number is matched from its start.');
    }

    public function testAWildcardTypedInTheSearchMatchesItselfOnly(): void
    {
        $this->productWithACombination(['mpn' => 'MPN-WILDCARD']);

        self::assertSame([], $this->search('%_%'));
    }

    /**
     * The default combination of a product is created with an empty code: a term that
     * normalizes to nothing must not be looked up as an empty GTIN.
     */
    public function testATermLeftEmptyOnceNormalizedDoesNotMatchTheCombinationsWithoutACode(): void
    {
        $withoutCode = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->factory->currency(),
            ['ref' => 'NOCODE'.strtoupper(bin2hex(random_bytes(4)))],
        );

        self::assertNotContains($withoutCode->getId(), $this->search('-'));
    }

    /**
     * @param array<string, mixed> $combination
     */
    private function productWithACombination(array $combination): Product
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency());
        $this->factory->productSaleElement($product, $combination);

        return $product;
    }

    /**
     * @return list<int>
     */
    private function search(string $term): array
    {
        $filters = ProductFilters::fromRequest(new Request(['q' => $term]));

        return array_map('intval', $filters->applyTo(ProductQuery::create(), 'en_US')->select(['Id'])->find()->toArray());
    }
}
