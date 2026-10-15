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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The general form of the product, category, folder and content sheets carries
 * the Save / Save and close / Close toolbar at its top and its bottom. Save
 * brings the user back to the sheet, Save and close to the list the sheet
 * belongs to, the one the Close link points at.
 */
final class EditPageSaveModeTest extends WebIntegrationTestCase
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

    /**
     * @return iterable<string, array{string, callable(FixtureFactory): array{string, string}}>
     */
    public static function sheets(): iterable
    {
        yield 'product' => ['product', static function (FixtureFactory $factory): array {
            $currency = CurrencyQuery::create()->findOneByByDefault(1);
            $product = $factory->product($factory->category(), $factory->taxRule(), $currency ?? $factory->currency());

            return ['/admin/products/update?product_id='.$product->getId(), '/admin/products'];
        }];

        yield 'category' => ['category', static function (FixtureFactory $factory): array {
            $parent = $factory->category();
            $category = $factory->category(['parent' => $parent->getId()]);

            return ['/admin/categories/update?category_id='.$category->getId(), '/admin/categories?category_id='.$parent->getId()];
        }];

        yield 'folder' => ['folder', static function (FixtureFactory $factory): array {
            $parent = $factory->folder();
            $folder = $factory->folder((int) $parent->getId());

            return ['/admin/folders/update/'.$folder->getId(), '/admin/folders?folder_id='.$parent->getId()];
        }];

        yield 'content' => ['content', static function (FixtureFactory $factory): array {
            $folder = $factory->folder();
            $content = $factory->content($folder);

            return ['/admin/content/update/'.$content->getId(), '/admin/folders?folder_id='.$folder->getId()];
        }];
    }

    /**
     * @param callable(FixtureFactory): array{string, string} $sheet
     */
    #[DataProvider('sheets')]
    public function testTheToolbarFramesTheGeneralForm(string $name, callable $sheet): void
    {
        [$editUrl, $listUrl] = $sheet($this->factory);

        $crawler = $this->client->request('GET', $editUrl);
        self::assertResponseIsSuccessful();

        foreach ([$name.'-edit-top', $name.'-edit'] as $toolbar) {
            $buttons = $crawler->filter('[data-testid="'.$toolbar.'-save-stay"]')->ancestors()->first()->filter('.btn');
            self::assertSame(['stay', 'close', null], $buttons->each(static fn ($button): ?string => $button->attr('value')), $toolbar.': Save, Save and close, then Close.');
            self::assertSame($listUrl, $crawler->filter('[data-testid="'.$toolbar.'-cancel"]')->attr('href'), $toolbar.': Close leads to the list of the sheet.');
        }
    }

    /**
     * @param callable(FixtureFactory): array{string, string} $sheet
     */
    #[DataProvider('sheets')]
    public function testSaveAndCloseLeadsToTheListOfTheSheet(string $name, callable $sheet): void
    {
        [$editUrl, $listUrl] = $sheet($this->factory);

        $crawler = $this->client->request('GET', $editUrl);
        $this->client->submit($crawler->filter('[data-testid="'.$name.'-edit-save-close"]')->form());

        self::assertResponseRedirects($listUrl);
    }

    /**
     * @param callable(FixtureFactory): array{string, string} $sheet
     */
    #[DataProvider('sheets')]
    public function testSaveBringsTheUserBackToTheSheet(string $name, callable $sheet): void
    {
        [$editUrl] = $sheet($this->factory);

        $crawler = $this->client->request('GET', $editUrl);
        $this->client->submit($crawler->filter('[data-testid="'.$name.'-edit-top-save-stay"]')->form());

        self::assertResponseRedirects();
        self::assertStringStartsWith(strtok($editUrl, '?') ?: $editUrl, (string) $this->client->getResponse()->headers->get('Location'));
    }
}
