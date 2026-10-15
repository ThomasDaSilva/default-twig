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

use BackOfficeDefaultTwigBundle\BackOfficeDefaultTwigBundle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\ProductQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The online toggle of the product list brings the user back to the page of
 * the list they clicked on, with the same search, filters and sort, instead
 * of the first page of the whole catalog.
 */
final class ProductListToggleTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== BackOfficeDefaultTwigBundle::ACTIVE_TEMPLATE_NAME) {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());

        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        $this->injector->clear();

        parent::tearDown();
    }

    public function testTheToggleBringsTheUserBackToThePageOfTheListTheyWereOn(): void
    {
        $category = $this->factory->category();
        $taxRule = $this->factory->taxRule();
        $currency = CurrencyQuery::create()->findOneByByDefault(1) ?? $this->factory->currency();
        // One more product than a page holds, so the list has a second page.
        for ($i = 0; $i < 26; ++$i) {
            $this->factory->product($category, $taxRule, $currency, ['visible' => 1]);
        }

        $listQuery = [
            'category_id' => (int) $category->getId(),
            'product_order' => 'ref',
            'direction' => 'desc',
            'page' => 2,
        ];
        $crawler = $this->client->request('GET', '/admin/products?'.http_build_query($listQuery));
        self::assertResponseIsSuccessful();

        $toggle = $crawler->filter('a.bo-toggle[href*="/admin/products/toggle-online"]')->first();
        self::assertCount(1, $toggle, 'The second page of the list renders an online toggle.');
        $href = (string) $toggle->attr('href');
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $toggleQuery);
        $productId = (int) ($toggleQuery['product_id'] ?? 0);

        $token = $crawler->filter('meta[name="bo-token"]')->attr('content');
        $this->client->request('POST', $href, ['_token' => $token]);

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame('/admin/products', parse_url($location, \PHP_URL_PATH));
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $redirectQuery);
        ksort($listQuery);
        ksort($redirectQuery);
        self::assertEquals($listQuery, $redirectQuery, 'The redirect keeps the page, the filters and the sort of the list.');

        self::assertSame(0, (int) ProductQuery::create()->findPk($productId)?->getVisible(), 'The product was put offline.');
    }
}
