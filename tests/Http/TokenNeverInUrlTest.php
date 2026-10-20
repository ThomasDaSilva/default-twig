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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Thelia\Model\AreaQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The back-office token never travels in a URL: a URL ends up in the browser
 * history, in the access logs of the server and of every proxy on the way.
 * Forms carry it in a hidden field, scripts in the request body.
 *
 * The sources of the theme are scanned, then the pages of a small catalog are
 * rendered and scanned too: no URL holds the token, and no plain link points
 * at an action that only answers a POST.
 */
final class TokenNeverInUrlTest extends WebIntegrationTestCase
{
    private const THEME_DIR = __DIR__.'/../..';

    private AdminSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        $this->injector = new AdminSessionInjector();
        $this->dispatcher()->addSubscriber($this->injector);

        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        $this->dispatcher()->removeSubscriber($this->injector);
        $this->injector->clear();

        parent::tearDown();
    }

    public function testNoSourceOfTheThemePutsTheTokenInAUrl(): void
    {
        $patterns = [
            '/_token=/' => 'a query string holding the token',
            '/[{,]\s*_token\s*:/' => 'the token passed as a route parameter',
            '/[\'"]_token[\'"]\s*=>\s*\$this->tokens/' => 'the token passed as a route parameter',
            '/searchParams\.(set|append)\(\s*[\'"]_token/' => 'the token added to a URL by a script',
            '/token_url\(/' => 'the removed token_url() function',
        ];

        $finder = (new Finder())
            ->files()
            ->in(self::THEME_DIR)
            ->exclude(['tests', 'vendor', 'node_modules', 'assets/vendor'])
            ->name(['*.twig', '*.js', '*.php']);

        $offending = [];
        foreach ($finder as $file) {
            foreach (file($file->getPathname()) ?: [] as $index => $line) {
                foreach ($patterns as $pattern => $meaning) {
                    if (preg_match($pattern, $line) === 1) {
                        $offending[] = \sprintf('%s:%d (%s)', $file->getRelativePathname(), $index + 1, $meaning);
                    }
                }
            }
        }

        self::assertGreaterThan(100, $finder->count(), 'The sources of the theme are found.');
        self::assertSame([], $offending, 'These lines put the back-office token in a URL.');
    }

    public function testNoRenderedPageHoldsTheTokenInAUrlNorLinksToAPostOnlyAction(): void
    {
        $matcher = new UrlMatcher($this->themeRoutes(), new RequestContext('', 'GET'));

        $offending = [];
        $pages = $this->pages();
        foreach ($pages as $url) {
            $crawler = $this->client->request('GET', $url);
            $status = $this->client->getResponse()->getStatusCode();
            self::assertSame(200, $status, \sprintf('%s answers %d.', $url, $status));

            $html = (string) $this->client->getResponse()->getContent();
            if (preg_match_all('/[^\s"\'<>]*[?&](amp;)?_token=[^\s"\'<>]*/', $html, $matches) > 0) {
                foreach (array_unique($matches[0]) as $match) {
                    $offending[] = \sprintf('%s: %s', $url, $match);
                }
            }

            foreach ($this->plainLinks($crawler) as $href) {
                if ($this->answersPostOnly($matcher, $href)) {
                    $offending[] = \sprintf('%s: plain link to the POST-only %s', $url, $href);
                }
            }
        }

        self::assertGreaterThan(20, \count($pages), 'The pages are listed.');
        self::assertSame([], $offending, 'These pages put the token in a URL, or link to a POST-only action with a GET.');
    }

    /**
     * The pages that render write actions, over a small catalog built for the
     * test: lists with their toggles, deletions and positions, and edit pages
     * with their tabs.
     *
     * @return list<string>
     */
    private function pages(): array
    {
        $factory = new FixtureFactory($this->getPropelConnection());

        $brand = $factory->brand();
        $category = $factory->category();
        $product = $factory->product($category, $factory->taxRule(), $factory->currency());
        $pse = ProductSaleElementsQuery::create()->findOneByProductId($product->getId(), $this->getPropelConnection());
        $attribute = $factory->attribute();
        $attributeAv = $factory->attributeAv($attribute);
        if ($pse !== null) {
            $factory->attributeCombination($pse, $attributeAv);
        }
        $feature = $factory->feature();
        $factory->featureAv($feature);
        $template = $factory->template();
        $folder = $factory->folder();
        $content = $factory->content($folder);
        $customer = $factory->customer($factory->customerTitle());
        $order = $factory->order($customer);
        $factory->cartItem($factory->cart($customer), $product, $pse);
        $coupon = $factory->coupon();
        $factory->sale();
        $factory->catalogPriceRule();
        $profile = $factory->profile();
        $factory->tag();
        $area = AreaQuery::create()->findOne($this->getPropelConnection());
        $orderStatus = OrderStatusQuery::create()->findOne($this->getPropelConnection());

        return [
            '/admin/brand',
            '/admin/brand/update/'.$brand->getId(),
            '/admin/categories',
            '/admin/categories?category_id='.$category->getId(),
            '/admin/categories/update?category_id='.$category->getId(),
            '/admin/products/update?product_id='.$product->getId(),
            '/admin/products/combinations/tab?product_id='.$product->getId(),
            '/admin/products/attributes/tab?product_id='.$product->getId(),
            '/admin/catalog-price-rule',
            '/admin/configuration/advanced',
            '/admin/configuration/administrators',
            '/admin/account/two-factor',
            '/admin/configuration/shipping_configuration',
            '/admin/configuration/shipping_configuration/update/'.(int) $area?->getId(),
            '/admin/configuration/attributes',
            '/admin/configuration/attributes/update?attribute_id='.$attribute->getId(),
            '/admin/configuration/checkout-step',
            '/admin/configuration/consent',
            '/admin/configuration/countries',
            '/admin/configuration/currencies',
            '/admin/configuration/features',
            '/admin/configuration/features/update?feature_id='.$feature->getId(),
            '/admin/hooks',
            '/admin/configuration/languages',
            '/admin/configuration/order-status',
            '/admin/configuration/order-status/update/'.(int) $orderStatus?->getId(),
            '/admin/configuration/product-association-type',
            '/admin/configuration/profiles/update/'.$profile->getId(),
            '/admin/configuration/states',
            '/admin/configuration/webhooks',
            '/admin/configuration/webhooks/deliveries',
            '/admin/configuration/tags',
            '/admin/configuration/taxes_rules',
            '/admin/configuration/templates',
            '/admin/configuration/templates/update?template_id='.$template->getId(),
            '/admin/coupon',
            '/admin/coupon/update/'.$coupon->getId(),
            '/admin/customers',
            '/admin/customer/update?customer_id='.$customer->getId(),
            '/admin/customer/carts?customer_id='.$customer->getId(),
            '/admin/folders',
            '/admin/folders?folder_id='.$folder->getId(),
            '/admin/content/update/'.$content->getId(),
            '/admin/modules',
            '/admin/module-hooks',
            '/admin/newsletter',
            '/admin/orders',
            '/admin/order/update/'.$order->getId(),
            '/admin/sales',
            '/admin/export',
            '/admin/import',
        ];
    }

    /**
     * @return list<string>
     */
    private function plainLinks(Crawler $crawler): array
    {
        $links = [];
        foreach ($crawler->filter('a[href]:not([data-bo-post])') as $node) {
            \assert($node instanceof \DOMElement);
            $links[] = $node->getAttribute('href');
        }

        return $links;
    }

    private function answersPostOnly(UrlMatcher $matcher, string $href): bool
    {
        $path = parse_url($href, \PHP_URL_PATH);
        if (!\is_string($path) || !str_starts_with($path, '/admin')) {
            return false;
        }

        try {
            $matcher->match($path);
        } catch (MethodNotAllowedException $exception) {
            return $exception->getAllowedMethods() === ['POST'];
        } catch (ResourceNotFoundException) {
            return false;
        }

        return false;
    }

    private function themeRoutes(): RouteCollection
    {
        $router = $this->getService('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = new RouteCollection();
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (\is_string($controller) && str_starts_with($controller, 'BackOfficeDefaultTwigBundle\\Controller\\')) {
                $routes->add($name, $route);
            }
        }

        return $routes;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->getService(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
