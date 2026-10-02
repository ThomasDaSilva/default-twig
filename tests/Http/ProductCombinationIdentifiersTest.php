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
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\OrderProduct;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The GTIN, the manufacturer part number and the manufacturer of a combination on the
 * combinations tab: a refused code costs its own row only and says why, a duplicate is
 * saved and pointed out.
 */
final class ProductCombinationIdentifiersTest extends WebIntegrationTestCase
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

        if (!class_exists('Thelia\\Domain\\Catalog\\Product\\Identifier\\Gtin')) {
            self::markTestSkipped('The installed core predates the GTIN check.');
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

    public function testAnInvalidGtinRefusesItsRowOnlyAndSaysWhy(): void
    {
        $this->loginFullAdmin();
        [$product, $refused] = $this->productWithACombination();
        $saved = $this->factory->productSaleElement($product, ['quantity' => 7]);

        $this->postGrid($product, [
            [$refused, ['ean_code' => '4006381333932', 'quantity' => 42]],
            [$saved, ['ean_code' => '4006381333931', 'quantity' => 43]],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $refusals = $this->flashes()['danger'];
        self::assertCount(1, $refusals, implode(' | ', $refusals));
        self::assertStringContainsString($refused->getRef(), $refusals[0]);
        self::assertStringContainsString('check digit', $refusals[0]);

        self::assertSame(7.0, (float) $this->fresh($refused)->getQuantity(), 'The refused row is not saved at all.');
        self::assertSame('', (string) $this->fresh($refused)->getEanCode());
        self::assertSame(43.0, (float) $this->fresh($saved)->getQuantity(), 'The other rows of the grid are saved.');
        self::assertSame('4006381333931', $this->fresh($saved)->getEanCode());
    }

    public function testADuplicateGtinIsSavedAndWarnedAbout(): void
    {
        $this->loginFullAdmin();
        $elsewhere = $this->factory->productSaleElement($this->product(), ['eanCode' => '9780306406157']);
        [$product, $combination] = $this->productWithACombination();

        $this->postGrid($product, [[$combination, ['ean_code' => '978-0306406157']]]);

        self::assertSame('9780306406157', $this->fresh($combination)->getEanCode(), 'A duplicate is saved.');
        $flashes = $this->flashes();
        self::assertSame([], $flashes['danger']);
        $warnings = $flashes['warning'];
        self::assertCount(1, $warnings);
        self::assertStringContainsString($elsewhere->getRef(), $warnings[0]);
    }

    public function testTheGridShowsTheDuplicateNextToTheCode(): void
    {
        $this->loginFullAdmin();
        $elsewhere = $this->factory->productSaleElement($this->product(), ['eanCode' => '96385074']);
        [$product, $combination] = $this->productWithACombination();
        $combination->setEanCode('96385074')->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());

        $notice = $crawler->filter(\sprintf('[data-testid="combinations-gtin-shared-%d"]', $combination->getId()));
        self::assertCount(1, $notice);
        self::assertStringContainsString($elsewhere->getRef(), $notice->text());
        self::assertCount(1, $crawler->filter(\sprintf('[data-testid="combinations-mpn-%d"]', $combination->getId())));
    }

    public function testTheDefaultFormSavesThePartNumberAndTheManufacturerThenClearsThem(): void
    {
        $this->loginFullAdmin();
        $brand = $this->factory->brand();
        [$product, $pse] = $this->productWithoutCombination();

        $this->postDefault($product, $pse, ['mpn' => ' SM-G991B ', 'manufacturer_brand_id' => $brand->getId()]);
        self::assertSame('SM-G991B', $this->fresh($pse)->getMpn());
        self::assertSame($brand->getId(), $this->fresh($pse)->getManufacturerBrandId());

        $this->postDefault($product, $pse, ['mpn' => '', 'manufacturer_brand_id' => 0]);
        self::assertNull($this->fresh($pse)->getMpn());
        self::assertNull($this->fresh($pse)->getManufacturerBrandId(), 'Back to the brand of the product.');
    }

    public function testTheDefaultFormRefusesAnElevenDigitCodeWithTheLengthsAccepted(): void
    {
        $this->loginFullAdmin();
        [$product, $pse] = $this->productWithoutCombination();

        $this->postDefault($product, $pse, ['ean_code' => '03600029145', 'quantity' => 99]);

        $refusals = $this->flashes()['danger'];
        self::assertCount(1, $refusals);
        self::assertStringContainsString('8, 12, 13 or 14', $refusals[0]);
        self::assertSame(7.0, (float) $this->fresh($pse)->getQuantity());
    }

    public function testTheOrderSheetShowsTheCodesFrozenOnTheLine(): void
    {
        $this->loginFullAdmin();
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        (new OrderProduct())
            ->setOrderId($order->getId())
            ->setProductRef('SOLD-REF')
            ->setProductSaleElementsRef('SOLD-PSE-REF')
            ->setTitle('Sold product')
            ->setQuantity(1.0)
            ->setPrice('10.000000')
            ->setWasNew(0)
            ->setWasInPromo(0)
            ->setEanCode('4006381333931')
            ->setMpn('SM-G991B')
            ->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('4006381333931', $crawler->filter('[data-testid="order-item-gtin"]')->text());
        self::assertStringContainsString('SM-G991B', $crawler->filter('[data-testid="order-item-mpn"]')->text());
    }

    /**
     * @param list<array{ProductSaleElements, array<string, mixed>}> $rows
     */
    private function postGrid(Product $product, array $rows): void
    {
        $payload = [
            '_token' => $this->tokenOf($product, 'combinations-form'),
            'product_id' => $product->getId(),
            'tax_rule' => $product->getTaxRuleId(),
        ];

        foreach ($rows as [$pse, $values]) {
            $payload['product_sale_element_id'][] = $pse->getId();
            $payload['reference'][] = $pse->getRef();
            $payload['quantity'][] = $values['quantity'] ?? $pse->getQuantity();
            $payload['ean_code'][] = $values['ean_code'] ?? '';
            $payload['mpn'][] = $values['mpn'] ?? '';
            $payload['manufacturer_brand_id'][] = $values['manufacturer_brand_id'] ?? 0;
        }

        $this->client->request('POST', self::COMBINATIONS_URL, $payload);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function postDefault(Product $product, ProductSaleElements $pse, array $values): void
    {
        $this->client->request('POST', self::DEFAULT_PRICE_URL, $values + [
            '_token' => $this->tokenOf($product, 'default-pse-form'),
            'product_id' => $product->getId(),
            'product_sale_element_id' => $pse->getId(),
            'reference' => $pse->getRef(),
            'tax_rule' => $product->getTaxRuleId(),
            'quantity' => 7,
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The messages the page the save redirects to shows, read the way the merchant
     * reads them.
     *
     * @return array{danger: list<string>, warning: list<string>}
     */
    private function flashes(): array
    {
        self::assertTrue($this->client->getResponse()->isRedirection(), 'The save redirects to the product sheet.');

        $crawler = $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 500));

        $read = static fn (string $type): array => $crawler->filter(\sprintf('[data-testid="bo-flash-%s"]', $type))->each(static fn ($alert): string => trim($alert->text()));

        return ['danger' => $read('danger'), 'warning' => $read('warning')];
    }

    private function loginFullAdmin(): void
    {
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    private function product(): Product
    {
        return $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->defaultCurrency());
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

    private function defaultCurrency(): Currency
    {
        return CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection())
            ?? $this->factory->currency(['byDefault' => 1]);
    }

    private function tokenOf(Product $product, string $formTestId): string
    {
        $crawler = $this->client->request('GET', '/admin/products/combinations/tab?product_id='.$product->getId());
        $token = $crawler->filter(\sprintf('form[data-testid="%s"] input[name="_token"]', $formTestId))->first();

        self::assertGreaterThan(0, $token->count(), \sprintf('The "%s" form renders no token field. %d %s', $formTestId, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 1500)));

        return (string) $token->attr('value');
    }

    private function fresh(ProductSaleElements $pse): ProductSaleElements
    {
        ProductSaleElementsTableMap::clearInstancePool();
        $fresh = ProductSaleElementsQuery::create()->findPk($pse->getId(), $this->getPropelConnection());
        self::assertNotNull($fresh);

        return $fresh;
    }
}
