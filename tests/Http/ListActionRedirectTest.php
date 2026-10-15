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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\Category;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Folder;
use Thelia\Model\ProductQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The online toggles and the deletions of the product, category and folder
 * lists bring the user back to the list they were on: same folder or
 * category, same page, same search, filters and sort.
 */
final class ListActionRedirectTest extends WebIntegrationTestCase
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

    public function testTheProductToggleBringsTheUserBackToThePageOfTheProductList(): void
    {
        $list = $this->productListOnItsSecondPage();
        $crawler = $this->client->request('GET', '/admin/products?'.http_build_query($list));

        $href = $this->toggleHref($crawler, '/admin/products/toggle-online');
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $toggleQuery);
        $this->postToggle($crawler, $href);

        $this->assertRedirectsTo('/admin/products', $list);
        self::assertSame(0, (int) ProductQuery::create()->findPk((int) $toggleQuery['product_id'])?->getVisible(), 'The product was put offline.');
    }

    public function testTheProductDeletionBringsTheUserBackToThePageOfTheProductList(): void
    {
        $list = $this->productListOnItsSecondPage();
        $crawler = $this->client->request('GET', '/admin/products?'.http_build_query($list));

        $this->submitDeletion($crawler, 'product-delete', 'product_id', $this->firstRowId($crawler, 'product-id'));

        $this->assertRedirectsTo('/admin/products', $list);
    }

    public function testTheCategoryActionsBringTheUserBackToThePageOfTheCategoryList(): void
    {
        $list = $this->categoryListOnItsSecondPage();
        $listUrl = '/admin/categories?'.http_build_query($list);

        $crawler = $this->client->request('GET', $listUrl);
        $this->postToggle($crawler, $this->toggleHref($crawler, '/admin/categories/toggle-online'));
        $this->assertRedirectsTo('/admin/categories', $list, 'category toggle');

        $crawler = $this->client->request('GET', $listUrl);
        $this->submitDeletion($crawler, 'category-delete', 'category_id', $this->firstRowId($crawler, 'category-id'));
        $this->assertRedirectsTo('/admin/categories', $list, 'category deletion');
    }

    public function testTheProductActionsOfACategoryListBringTheUserBackToThatList(): void
    {
        $list = $this->categoryListOnItsSecondPage();
        $listUrl = '/admin/categories?'.http_build_query($list);

        $crawler = $this->client->request('GET', $listUrl);
        $this->postToggle($crawler, $this->toggleHref($crawler, '/admin/products/toggle-online'));
        $this->assertRedirectsTo('/admin/categories', $list, 'product toggle');

        $crawler = $this->client->request('GET', $listUrl);
        $this->submitDeletion($crawler, 'category-product-delete', 'product_id', $this->firstRowId($crawler, 'product-id'));
        $this->assertRedirectsTo('/admin/categories', $list, 'product deletion');
    }

    public function testTheFolderAndContentActionsBringTheUserBackToThePageOfTheFolderList(): void
    {
        $list = $this->folderListOnItsSecondContentPage();
        $listUrl = '/admin/folders?'.http_build_query($list);

        $crawler = $this->client->request('GET', $listUrl);
        $this->postToggle($crawler, $this->toggleHref($crawler, '/admin/folders/toggle-online'));
        $this->assertRedirectsTo('/admin/folders', $list, 'folder toggle');

        $crawler = $this->client->request('GET', $listUrl);
        $this->postToggle($crawler, $this->toggleHref($crawler, '/admin/content/toggle-online'));
        $this->assertRedirectsTo('/admin/folders', $list, 'content toggle');

        $crawler = $this->client->request('GET', $listUrl);
        $this->submitDeletion($crawler, 'content-delete', 'content_id', $this->firstRowId($crawler, 'content-id'));
        $this->assertRedirectsTo('/admin/folders', $list, 'content deletion');

        $crawler = $this->client->request('GET', $listUrl);
        $this->submitDeletion($crawler, 'folder-delete', 'folder_id', $this->firstRowId($crawler, 'folder-id'));
        $this->assertRedirectsTo('/admin/folders', $list, 'folder deletion');
    }

    /**
     * A category holding one more product than a page of the product list.
     *
     * @return array<string, int|string>
     */
    private function productListOnItsSecondPage(): array
    {
        $category = $this->factory->category();
        $this->productsIn($category, 26);

        return ['category_id' => (int) $category->getId(), 'direction' => 'desc', 'page' => 2, 'product_order' => 'ref'];
    }

    /**
     * A category with a subcategory and one more product than a page of its
     * product section.
     *
     * @return array<string, int>
     */
    private function categoryListOnItsSecondPage(): array
    {
        $parent = $this->factory->category();
        $this->factory->category(['parent' => (int) $parent->getId()]);
        $this->productsIn($parent, 21);

        return ['category_id' => (int) $parent->getId(), 'page' => 2];
    }

    /**
     * A folder with a subfolder and one more content than a page of its
     * content section, listed with a sort that is not the default one.
     *
     * @return array<string, int|string>
     */
    private function folderListOnItsSecondContentPage(): array
    {
        $folder = $this->factory->folder();
        $this->factory->folder((int) $folder->getId());
        $this->contentsIn($folder, 21);

        return ['content_page' => 2, 'direction' => 'desc', 'folder_id' => (int) $folder->getId(), 'order' => 'title'];
    }

    private function productsIn(Category $category, int $count): void
    {
        $taxRule = $this->factory->taxRule();
        $currency = CurrencyQuery::create()->findOneByByDefault(1) ?? $this->factory->currency();
        for ($i = 0; $i < $count; ++$i) {
            $this->factory->product($category, $taxRule, $currency, ['visible' => 1]);
        }
    }

    private function contentsIn(Folder $folder, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->factory->content($folder);
        }
    }

    private function toggleHref(Crawler $crawler, string $path): string
    {
        $toggle = $crawler->filter('a.bo-toggle[href*="'.$path.'"]')->first();
        self::assertCount(1, $toggle, 'The list renders a toggle posting to '.$path.'.');

        return (string) $toggle->attr('href');
    }

    private function postToggle(Crawler $crawler, string $href): void
    {
        $this->client->request('POST', $href, ['_token' => $crawler->filter('meta[name="bo-token"]')->attr('content')]);
    }

    private function firstRowId(Crawler $crawler, string $attribute): string
    {
        $trigger = $crawler->filter('[data-'.$attribute.']')->first();
        self::assertCount(1, $trigger, 'The list renders a deletion trigger carrying data-'.$attribute.'.');

        return (string) $trigger->attr('data-'.$attribute);
    }

    private function submitDeletion(Crawler $crawler, string $testid, string $input, string $id): void
    {
        $form = $crawler->filter('[data-testid="'.$testid.'-form"]')->form();
        $form[$input] = $id;
        $this->client->submit($form);
    }

    /**
     * @param array<string, int|string> $expectedQuery
     */
    private function assertRedirectsTo(string $expectedPath, array $expectedQuery, string $action = ''): void
    {
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        ksort($query);
        ksort($expectedQuery);

        self::assertSame($expectedPath, parse_url($location, \PHP_URL_PATH), $action.': the redirect lands on the list.');
        self::assertEquals($expectedQuery, $query, $action.': the redirect keeps the state of the list.');
    }
}
