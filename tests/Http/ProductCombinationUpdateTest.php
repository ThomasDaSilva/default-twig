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

namespace BackOfficeDefaultTwigBundle\Tests\Http;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Map\ProductPriceTableMap;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\Product;
use Thelia\Model\ProductPrice;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The two forms of the combinations tab of a product sheet, over HTTP: the
 * table of combinations and the default pricing of a product without any.
 * Both only save through a POST that carries the token of the form, and a
 * price the form did not send keeps its stored value. A combination without any
 * price is shown with an empty one, and is not saved until one is typed: the
 * update of the core would otherwise store it at 0.
 */
final class ProductCombinationUpdateTest extends WebIntegrationTestCase
{
    private const COMBINATIONS_URL = '/admin/product/combinations/update';
    private const DEFAULT_PRICE_URL = '/admin/product/default-price/update';

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        // Built without createFixtureFactory(), which would push a synthetic
        // main request the SecurityContext then reads its session from.
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        $this->injector->clear();

        parent::tearDown();
    }

    public function testTheCombinationsCannotBeUpdatedThroughAGet(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();

        $this->client->request('GET', self::COMBINATIONS_URL, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => [$pse->getId()],
            'quantity' => [0],
            'price' => [0.01],
        ]);

        $this->assertNotSaved();
        $this->assertCombinationUntouched($pse);
    }

    public function testTheCombinationsCannotBeUpdatedWithoutTheFormToken(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $token = $this->tokenOf($product, 'combinations-form');

        $this->client->request('POST', self::COMBINATIONS_URL, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => [$pse->getId()],
            'quantity' => [0],
            'price' => [0.01],
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', self::COMBINATIONS_URL.'?_token='.$token, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => [$pse->getId()],
            'quantity' => [0],
            'price' => [0.01],
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'The token is read from the form body only.');

        $this->assertCombinationUntouched($pse);
    }

    public function testTheCombinationsFormSavesAndKeepsAPriceItDidNotSend(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();

        $this->client->request('POST', self::COMBINATIONS_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
            'product_sale_element_id' => [$pse->getId()],
            'reference' => [$pse->getRef()],
            'quantity' => [42],
            'weight' => [1.5],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(42.0, (float) $this->freshSaleElement($pse)->getQuantity(), 'The submitted stock is saved.');
        $price = $this->storedPrice($pse);
        self::assertSame(25.0, (float) $price->getPrice(), 'A price the form did not send keeps its stored value.');
        self::assertSame(20.0, (float) $price->getPromoPrice(), 'A sale price the form did not send keeps its stored value.');
    }

    public function testTheDefaultPriceCannotBeUpdatedThroughAGet(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithoutCombination();

        $this->client->request('GET', self::DEFAULT_PRICE_URL, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => $pse->getId(),
            'quantity' => 43,
            'price' => 0.01,
        ]);

        $this->assertNotSaved();
        $this->assertCombinationUntouched($pse);
    }

    public function testTheDefaultPriceCannotBeUpdatedWithoutTheFormToken(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithoutCombination();
        $token = $this->tokenOf($product, 'default-pse-form');

        $this->client->request('POST', self::DEFAULT_PRICE_URL, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => $pse->getId(),
            'quantity' => 43,
            'price' => 0.01,
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', self::DEFAULT_PRICE_URL.'?_token='.$token, [
            'product_id' => $product->getId(),
            'product_sale_element_id' => $pse->getId(),
            'quantity' => 43,
            'price' => 0.01,
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'The token is read from the form body only.');

        $this->assertCombinationUntouched($pse);
    }

    public function testTheDefaultPriceFormSavesAndKeepsAPriceItDidNotSend(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithoutCombination();

        $this->client->request('POST', self::DEFAULT_PRICE_URL, [
            '_token' => $this->tokenOf($product, 'default-pse-form'),
            'product_id' => $product->getId(),
            'product_sale_element_id' => $pse->getId(),
            'reference' => $pse->getRef(),
            'tax_rule' => $product->getTaxRuleId(),
            'quantity' => 43,
            'weight' => 1.5,
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(43.0, (float) $this->freshSaleElement($pse)->getQuantity(), 'The submitted stock is saved.');
        $price = $this->storedPrice($pse);
        self::assertSame(25.0, (float) $price->getPrice(), 'A price the form did not send keeps its stored value.');
        self::assertSame(20.0, (float) $price->getPromoPrice(), 'A sale price the form did not send keeps its stored value.');
    }

    public function testACombinationWithoutPriceShowsAnEmptyPriceThatMustBeFilled(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $unpriced = $this->unpricedCombinationOf($product);

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $unpricedInput = $crawler->filter(\sprintf('tr[data-testid="combinations-row-%d"] input[name="price[]"]', $unpriced->getId()));
        self::assertSame('', $unpricedInput->attr('value'), 'A combination without any price shows an empty price, not 0.');
        self::assertNotNull($unpricedInput->attr('required'), 'The browser asks for the missing price before submitting.');
        $pricedInput = $crawler->filter(\sprintf('tr[data-testid="combinations-row-%d"] input[name="price[]"]', $pse->getId()));
        self::assertSame('25', $pricedInput->attr('value'));
        self::assertNull($pricedInput->attr('required'), 'A priced combination is shown as before.');
    }

    public function testAnUnpricedCombinationSentWithoutAPriceIsLeftAsItIsAndTheOthersAreSaved(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $unpriced = $this->unpricedCombinationOf($product);

        $this->client->request('POST', self::COMBINATIONS_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
            'default_pse' => $pse->getId(),
            'product_sale_element_id' => [$pse->getId(), $unpriced->getId()],
            'reference' => [$pse->getRef(), $unpriced->getRef()],
            'quantity' => [42, 9],
            'price' => ['25', ''],
            'sale_price' => ['20', ''],
            'weight' => [1.5, 0],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->priceOf($unpriced), 'No price of 0 is written for a combination saved without a price.');
        self::assertSame(3.0, (float) $this->freshSaleElement($unpriced)->getQuantity(), 'The unpriced combination is left as it is.');
        self::assertSame(42.0, (float) $this->freshSaleElement($pse)->getQuantity(), 'The other combinations are saved.');
        self::assertSame(25.0, (float) $this->storedPrice($pse)->getPrice());
        $this->client->followRedirect();
        self::assertStringContainsString(
            $unpriced->getRef(),
            $this->client->getCrawler()->filter('[data-testid="bo-flash-danger"]')->text(''),
            'The merchant is told which combination needs a price.',
        );
    }

    public function testAPriceEnteredForAnUnpricedCombinationIsSaved(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithACombination();
        $unpriced = $this->unpricedCombinationOf($product);

        $this->client->request('POST', self::COMBINATIONS_URL, [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
            'default_pse' => $pse->getId(),
            'product_sale_element_id' => [$pse->getId(), $unpriced->getId()],
            'reference' => [$pse->getRef(), $unpriced->getRef()],
            'quantity' => [42, 3],
            'price' => ['25', '12.5'],
            'sale_price' => ['20', ''],
            'weight' => [1.5, 0],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(42.0, (float) $this->freshSaleElement($pse)->getQuantity());
        self::assertSame(25.0, (float) $this->storedPrice($pse)->getPrice());
        $price = $this->priceOf($unpriced);
        self::assertNotNull($price);
        self::assertSame(12.5, (float) $price->getPrice());
    }

    public function testTheDefaultPriceFormWritesNoPriceOfZeroForAnUnpricedProduct(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithoutCombination();
        ProductPriceQuery::create()
            ->filterByProductSaleElementsId($pse->getId())
            ->delete($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        self::assertSame('', $crawler->filter('#default_pse_price')->attr('value'), 'A product without any price shows an empty price, not 0.');

        $this->client->request('POST', self::DEFAULT_PRICE_URL, [
            '_token' => $this->tokenOf($product, 'default-pse-form'),
            'product_id' => $product->getId(),
            'product_sale_element_id' => $pse->getId(),
            'reference' => $pse->getRef(),
            'tax_rule' => $product->getTaxRuleId(),
            'quantity' => 43,
            'weight' => 1.5,
            'price' => '',
            'sale_price' => '',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->priceOf($pse), 'No price of 0 is written for a product saved without a price.');
        self::assertSame(7.0, (float) $this->freshSaleElement($pse)->getQuantity(), 'Nothing is saved.');
    }

    private function loginFullAdmin(): void
    {
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    /**
     * @return array{Product, ProductSaleElements}
     */
    private function productWithoutCombination(): array
    {
        $product = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->defaultCurrency(),
            ['basePrice' => 25.0, 'baseQuantity' => 7],
        );
        $pse = ProductSaleElementsQuery::create()
            ->filterByProductId($product->getId())
            ->findOne($this->getPropelConnection());
        self::assertNotNull($pse);

        $price = ProductPriceQuery::create()
            ->filterByProductSaleElementsId($pse->getId())
            ->filterByCurrencyId($this->defaultCurrency()->getId())
            ->findOne($this->getPropelConnection());
        self::assertNotNull($price);
        $price->setPromoPrice('20.000000')->save($this->getPropelConnection());

        return [$product, $pse];
    }

    /**
     * @return array{Product, ProductSaleElements}
     */
    private function productWithACombination(): array
    {
        [$product, $pse] = $this->productWithoutCombination();

        $attribute = $this->factory->attribute(['title' => 'Size']);
        $this->factory->attributeCombination($pse, $this->factory->attributeAv($attribute, ['title' => 'Large']));

        return [$product, $pse];
    }

    /**
     * A combination written without any price row, the way the API or an import
     * can create one.
     */
    private function unpricedCombinationOf(Product $product): ProductSaleElements
    {
        $pse = $this->factory->productSaleElement($product, ['quantity' => 3]);
        $attribute = $this->factory->attribute(['title' => 'Color']);
        $this->factory->attributeCombination($pse, $this->factory->attributeAv($attribute, ['title' => 'Red']));
        self::assertNull($this->priceOf($pse));

        return $pse;
    }

    private function defaultCurrency(): Currency
    {
        return CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection())
            ?? $this->factory->currency(['byDefault' => 1]);
    }

    /**
     * The token the given form of the combinations tab renders in its body, so
     * the POST is submitted the way the browser submits it.
     */
    private function tokenOf(Product $product, string $formTestId): string
    {
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        $token = $crawler->filter(\sprintf('form[data-testid="%s"] input[name="_token"]', $formTestId))->first();

        self::assertGreaterThan(0, $token->count(), \sprintf('The "%s" form renders no token field.', $formTestId));

        return (string) $token->attr('value');
    }

    /**
     * A GET never reaches the save of the Twig back-office. When the legacy
     * back-office is installed next to it, its route of the same path answers
     * instead, and it only saves a POSTed form: the status then depends on how
     * that page renders, so the check is that nothing was saved.
     */
    private function assertNotSaved(): void
    {
        self::assertFalse(
            $this->client->getResponse()->isRedirection(),
            'A GET must not be answered with the redirection that follows a save.',
        );
    }

    private function assertCombinationUntouched(ProductSaleElements $pse): void
    {
        self::assertSame(7.0, (float) $this->freshSaleElement($pse)->getQuantity(), 'The stock must not change.');
        self::assertSame(25.0, (float) $this->storedPrice($pse)->getPrice(), 'The price must not change.');
    }

    private function freshSaleElement(ProductSaleElements $pse): ProductSaleElements
    {
        ProductSaleElementsTableMap::clearInstancePool();
        $fresh = ProductSaleElementsQuery::create()->findPk($pse->getId(), $this->getPropelConnection());
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function storedPrice(ProductSaleElements $pse): ProductPrice
    {
        $price = $this->priceOf($pse);
        self::assertNotNull($price);

        return $price;
    }

    private function priceOf(ProductSaleElements $pse): ?ProductPrice
    {
        ProductPriceTableMap::clearInstancePool();

        return ProductPriceQuery::create()
            ->filterByProductSaleElementsId($pse->getId())
            ->filterByCurrencyId($this->defaultCurrency()->getId())
            ->findOne($this->getPropelConnection());
    }
}
