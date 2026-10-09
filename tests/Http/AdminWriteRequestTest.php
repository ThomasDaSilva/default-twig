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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminFailureMessage;
use Thelia\Model\OrderStatusQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\EventDispatcher\Event;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\AreaQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CountryQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\ConfigTableMap;
use Thelia\Model\ModuleQuery;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductSaleElementsProductImageQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The actions of the back-office that change something (toggles, deletions,
 * positions, cache flushes, access rights...) only run on a POST that carries
 * the back-office token in its body. A GET, a POST without the token or a POST
 * with the token in its query string changes nothing.
 *
 * Each action is observed through the event it dispatches: a listener records
 * it and stops it, so no test changes the shop, even when the check fails.
 */
final class AdminWriteRequestTest extends WebIntegrationTestCase
{
    /**
     * Paths of the write actions of the theme. Every route whose path matches
     * must accept POST only.
     */
    private const WRITE_PATH = '#(delete|remove|toggle|position|/move$|flush|update-rates|set-default|set_default|set-visible|/domain/|/add$|/build$|duplicate|recompute|convert-sale|reset-status|check-activation|cancel|/status$|delivery-ref|/\{order_id\}/address$|set-product-template|visibility|add-to-all|rem-from-all|product_sale_elements/\{pseId\}|personal-data|saveResourceAccess|saveModuleAccess|two-factor-reset|/two-factor/disable$|/backup-codes$)#';

    /**
     * Routes whose path looks like a write but which only read, or which show
     * a confirmation page on GET before the POST that does the work.
     */
    private const NOT_WRITES = [
        'admin.configuration.tags.merge',
        'admin.categories.related-picture.add',
        'admin.product.add-attribute-value-to-combination',
    ];

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    /** @var list<string> */
    private array $dispatched = [];

    /** @var list<array{string, callable}> */
    private array $spies = [];

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
        $this->factory = new FixtureFactory($this->getPropelConnection());

        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        foreach ($this->spies as [$eventName, $listener]) {
            $this->dispatcher()->removeListener($eventName, $listener);
        }
        $this->spies = [];
        $this->dispatcher()->removeSubscriber($this->injector);
        $this->injector->clear();

        parent::tearDown();
    }

    public function testEveryWriteRouteOfTheThemeAcceptsPostOnly(): void
    {
        $router = $this->getService('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $checked = 0;
        $acceptingOtherMethods = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (!\is_string($controller) || !str_starts_with($controller, 'BackOfficeDefaultTwigBundle\\Controller\\')) {
                continue;
            }
            if (\in_array($name, self::NOT_WRITES, true) || preg_match(self::WRITE_PATH, $route->getPath()) !== 1) {
                continue;
            }

            ++$checked;
            if ($route->getMethods() !== ['POST']) {
                $acceptingOtherMethods[] = \sprintf('%s %s [%s]', $name, $route->getPath(), implode('|', $route->getMethods()) ?: 'ANY');
            }
        }

        self::assertGreaterThan(100, $checked, 'The write routes of the theme are found in the router.');
        self::assertSame([], $acceptingOtherMethods, 'These write routes accept another method than POST.');
    }

    /**
     * @return iterable<string, array{string, string, callable(FixtureFactory): array<string, scalar>}>
     */
    public static function tokenizedActions(): iterable
    {
        $brand = static fn (FixtureFactory $factory): array => ['brand_id' => (int) $factory->brand()->getId()];

        yield 'toggle' => ['/admin/brand/toggle-online', TheliaEvents::BRAND_TOGGLE_VISIBILITY, $brand];
        yield 'deletion' => ['/admin/brand/delete', TheliaEvents::BRAND_DELETE, $brand];
        yield 'position' => ['/admin/brand/update-position', TheliaEvents::BRAND_UPDATE_POSITION, static fn (FixtureFactory $factory): array => $brand($factory) + ['position' => 1]];
        yield 'cache flush' => ['/admin/configuration/advanced/flush-cache', TheliaEvents::CACHE_CLEAR, static fn (): array => []];
        yield 'toggle declared without methods' => ['/admin/configuration/languages/toggleVisible/{lang_id}', TheliaEvents::LANG_TOGGLEVISIBLE, static fn (): array => ['lang_id' => (int) LangQuery::create()->findOne()?->getId()]];
        yield 'module activation' => ['/admin/module/toggle-activation/{module_id}', TheliaEvents::MODULE_TOGGLE_ACTIVATION, static fn (): array => ['module_id' => (int) ModuleQuery::create()->findOne()?->getId()]];
        yield 'product toggle' => ['/admin/products/toggle-online', TheliaEvents::PRODUCT_TOGGLE_VISIBILITY, static fn (FixtureFactory $factory): array => ['product_id' => (int) $factory->product($factory->category(), $factory->taxRule(), $factory->currency())->getId()]];
        yield 'personal data export' => ['/admin/customer/personal-data', TheliaEvents::CUSTOMER_PERSONAL_DATA_EXPORT, static fn (FixtureFactory $factory): array => ['customer_id' => (int) $factory->customer($factory->customerTitle())->getId()]];
    }

    /**
     * @param callable(FixtureFactory): array<string, scalar> $parameters
     */
    #[DataProvider('tokenizedActions')]
    public function testAGetWithTheTokenInItsUrlChangesNothing(string $path, string $eventName, callable $parameters): void
    {
        [$url] = $this->prepare($path, $eventName, $parameters);

        $this->client->request('GET', $this->withQuery($url, ['_token' => $this->token()]));

        self::assertSame([], $this->dispatched, 'A GET must not run the action.');
    }

    /**
     * @param callable(FixtureFactory): array<string, scalar> $parameters
     */
    #[DataProvider('tokenizedActions')]
    public function testAPostWithTheTokenInItsUrlOnlyChangesNothing(string $path, string $eventName, callable $parameters): void
    {
        [$url] = $this->prepare($path, $eventName, $parameters);

        $this->client->request('POST', $this->withQuery($url, ['_token' => $this->token()]));

        self::assertSame([], $this->dispatched, 'The token is read from the request body only.');
    }

    /**
     * @param callable(FixtureFactory): array<string, scalar> $parameters
     */
    #[DataProvider('tokenizedActions')]
    public function testAPostWithoutTheTokenChangesNothing(string $path, string $eventName, callable $parameters): void
    {
        [$url] = $this->prepare($path, $eventName, $parameters);

        $this->client->request('POST', $url);

        self::assertSame([], $this->dispatched, 'A POST without the token must not run the action.');
    }

    /**
     * @param callable(FixtureFactory): array<string, scalar> $parameters
     */
    #[DataProvider('tokenizedActions')]
    public function testAPostWithTheTokenInItsBodyRunsTheAction(string $path, string $eventName, callable $parameters): void
    {
        [$url] = $this->prepare($path, $eventName, $parameters);

        $this->client->request('POST', $url, ['_token' => $this->token()]);

        self::assertSame([$eventName], $this->dispatched, 'The action runs when the token is in the body.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function writesThatHadNoToken(): iterable
    {
        yield 'profile resource rights' => ['/admin/configuration/profiles/saveResourceAccess', TheliaEvents::PROFILE_RESOURCE_ACCESS_UPDATE];
        yield 'profile module rights' => ['/admin/configuration/profiles/saveModuleAccess', TheliaEvents::PROFILE_MODULE_ACCESS_UPDATE];
        yield 'shipping zone country added' => ['/admin/configuration/shipping_configuration/country/add', TheliaEvents::AREA_ADD_COUNTRY];
        yield 'shipping zone countries removed' => ['/admin/configuration/shipping_configuration/countries/remove', TheliaEvents::AREA_REMOVE_COUNTRY];
        yield 'currency rates' => ['/admin/configuration/currencies/update-rates', TheliaEvents::CURRENCY_UPDATE_RATES];
    }

    #[DataProvider('writesThatHadNoToken')]
    public function testAWriteThatHadNoTokenNowRequiresIt(string $path, string $eventName): void
    {
        $this->spy($eventName);
        $body = $this->bodyOf($path);

        $this->client->request('POST', $path, $body);
        self::assertSame([], $this->dispatched, 'A POST without the token must not run the action.');

        $this->client->request('POST', $path, $body + ['_token' => $this->token()]);
        self::assertSame([$eventName], $this->dispatched, 'The action runs when the token is in the body.');
    }

    /**
     * A database error quotes the values of the rows it failed on: the administrator is
     * told the action failed, the log keeps the detail.
     */
    public function testADatabaseErrorIsNeverShownToTheAdministrator(): void
    {
        $this->failWith(TheliaEvents::CURRENCY_UPDATE_RATES, new \PDOException("SQLSTATE[23000]: Duplicate entry 'buyer@example.com' for key 'email'"));

        $this->client->request('POST', '/admin/configuration/currencies/update-rates', ['_token' => $this->token()]);
        $this->client->followRedirects();
        $html = (string) $this->client->request('GET', '/admin/configuration/currencies')->html();

        self::assertStringNotContainsString('buyer@example.com', $html);
        self::assertStringNotContainsString('SQLSTATE', $html);
    }

    /**
     * What an action of the shop says of its refusal is meant for the administrator: it
     * is still shown as it is.
     */
    public function testWhatTheShopSaysOfARefusalIsShown(): void
    {
        $this->failWith(TheliaEvents::CURRENCY_UPDATE_RATES, new \RuntimeException('The rates cannot be updated now.'));

        $this->client->request('POST', '/admin/configuration/currencies/update-rates', ['_token' => $this->token()]);
        $this->client->followRedirects();
        $html = (string) $this->client->request('GET', '/admin/configuration/currencies')->html();

        self::assertStringContainsString('The rates cannot be updated now.', $html);
    }

    /**
     * A trigger the form never offers is refused as such, not read as a server error.
     */
    public function testAnUnknownTriggerOfAnOrderStatusActionIsRefusedAsSuch(): void
    {
        $status = OrderStatusQuery::create()->findOne();
        self::assertNotNull($status);

        $this->client->request('POST', '/admin/configuration/order-status/actions/'.$status->getId().'/create', [
            '_token' => $this->token(),
            'trigger' => 'whenever',
            'action_type' => 'notify_customer',
        ]);
        $html = (string) $this->client->request('GET', '/admin/configuration/order-status/update/'.$status->getId().'?tab=actions')->html();

        self::assertStringContainsString('This trigger is unknown.', $html);
        self::assertStringNotContainsString(AdminFailureMessage::SERVER_ERROR, $html);
    }

    /**
     * A database error on an order address quotes what was typed: the log names it by
     * its class and place, never by its text.
     */
    public function testAFailedAddressUpdateLogsNoneOfTheCustomersData(): void
    {
        $order = $this->factory->order();
        $address = $order->getOrderAddressRelatedByInvoiceOrderAddressId();
        self::assertNotNull($address);
        $marker = uniqid('leaked-').'@example.com';
        $this->failWith(TheliaEvents::ORDER_UPDATE_ADDRESS, new \PDOException(\sprintf("SQLSTATE[23000]: Duplicate entry '%s'", $marker)));
        $log = THELIA_LOG_DIR.'log-thelia.txt';
        clearstatcache();
        $before = is_file($log) ? (int) filesize($log) : 0;

        $this->client->request('POST', '/admin/order/update/'.$order->getId().'/address', [
            '_token' => $this->token(),
            'thelia_order_address' => [
                'id' => (string) $address->getId(),
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
                'address1' => '1 rue de la Paix',
                'zipcode' => '75001',
                'city' => 'Paris',
                'country' => (string) $address->getCountryId(),
            ],
        ]);
        clearstatcache();
        $written = is_file($log) ? (string) file_get_contents($log, false, null, $before) : '';

        self::assertStringContainsString('address update failed', $written);
        self::assertStringNotContainsString($marker, $written);
    }

    public function testTheDomainPerLanguageSettingOnlyChangesThroughATokenizedPost(): void
    {
        ConfigQuery::write('one_domain_foreach_lang', '1');
        $url = '/admin/configuration/languages/domain/deactivate';

        $this->client->request('GET', $url);
        self::assertSame('1', $this->storedConfig('one_domain_foreach_lang'), 'A GET must not change the setting.');

        $this->client->request('POST', $url);
        self::assertSame('1', $this->storedConfig('one_domain_foreach_lang'), 'A POST without the token must not change the setting.');

        $this->client->request('POST', $url, ['_token' => $this->token()]);
        self::assertSame('0', $this->storedConfig('one_domain_foreach_lang'), 'A POST with the token changes the setting.');
    }

    public function testAMediaIsAssociatedToACombinationThroughATokenizedPostOnly(): void
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency());
        $pse = $this->factory->productSaleElement($product);
        $image = (new ProductImage())->setProductId((int) $product->getId())->setFile('combination.png');
        $image->save($this->getPropelConnection());
        $url = \sprintf('/admin/product_sale_elements/%d/image/%d', $pse->getId(), $image->getId());

        $this->client->request('GET', $url);
        self::assertFalse($this->isAssociated((int) $pse->getId(), (int) $image->getId()), 'A GET must not associate the image.');

        $this->client->request('POST', $url);
        self::assertFalse($this->isAssociated((int) $pse->getId(), (int) $image->getId()), 'A POST without the token must not associate the image.');

        $this->client->request('POST', $url, ['_token' => $this->token()]);
        self::assertTrue($this->isAssociated((int) $pse->getId(), (int) $image->getId()), 'A POST with the token associates the image.');
    }

    /**
     * @param callable(FixtureFactory): array<string, scalar> $parameters
     *
     * @return array{string}
     */
    private function prepare(string $path, string $eventName, callable $parameters): array
    {
        $this->spy($eventName);

        $values = $parameters($this->factory);

        $query = [];
        foreach ($values as $name => $value) {
            if (str_contains($path, '{'.$name.'}')) {
                $path = str_replace('{'.$name.'}', (string) $value, $path);
            } else {
                $query[$name] = $value;
            }
        }

        return [$this->withQuery($path, $query)];
    }

    /**
     * @param array<string, scalar> $query
     */
    private function withQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * Records the given event and stops it, so the action it stands for never
     * reaches the shop.
     */
    private function spy(string $eventName): void
    {
        $listener = function (Event $event) use ($eventName): void {
            $this->dispatched[] = $eventName;
            $event->stopPropagation();
        };

        $this->dispatcher()->addListener($eventName, $listener, 4096);
        $this->spies[] = [$eventName, $listener];
    }

    private function failWith(string $eventName, \Throwable $failure): void
    {
        $listener = static function () use ($failure): void {
            throw $failure;
        };

        $this->dispatcher()->addListener($eventName, $listener, 4096);
        $this->spies[] = [$eventName, $listener];
    }

    /**
     * The token of the admin session, read from a page the way a script reads it.
     */
    private function token(): string
    {
        $html = (string) $this->client->request('GET', '/admin/brand')->html();

        $found = preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches) === 1
            || preg_match('/name="_token" value="([^"]+)"/', $html, $matches) === 1
            || preg_match('/[?&]_token=([0-9a-f]+)/', $html, $matches) === 1;
        self::assertTrue($found, 'The brand list renders the back-office token.');

        return $matches[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyOf(string $path): array
    {
        $area = AreaQuery::create()->findOne($this->getPropelConnection());
        $country = CountryQuery::create()->findOne($this->getPropelConnection());

        return match (true) {
            str_contains($path, '/profiles/') => ['profile_id' => (int) $this->factory->profile()->getId()],
            str_contains($path, '/country/add') => ['area_id' => (int) $area?->getId(), 'country_id' => [(int) $country?->getId()]],
            str_contains($path, '/countries/remove') => ['area_id' => (int) $area?->getId(), 'country_id' => [(string) $country?->getId()]],
            default => [],
        };
    }

    private function storedConfig(string $name): string
    {
        ConfigTableMap::clearInstancePool();

        return (string) ConfigQuery::create()->findOneByName($name, $this->getPropelConnection())?->getValue();
    }

    private function isAssociated(int $pseId, int $imageId): bool
    {
        return ProductSaleElementsProductImageQuery::create()
            ->filterByProductSaleElementsId($pseId)
            ->filterByProductImageId($imageId)
            ->exists($this->getPropelConnection());
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->getService(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
