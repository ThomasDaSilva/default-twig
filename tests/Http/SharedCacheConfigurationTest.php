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

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\SharedCache\SharedCacheMode;
use Thelia\Core\SharedCache\SharedCachePolicy;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The mode and the lifetime of the shared cache, set from the store configuration.
 */
final class SharedCacheConfigurationTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        if (!enum_exists(SharedCacheMode::class)) {
            self::markTestSkipped('The core of this checkout predates the shared cache setting.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        // The cache of the config table is static and outlives the transaction rollback.
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheModeAndTheLifetimeAreSavedFromTheStoreConfiguration(): void
    {
        $this->submit('enabled', '600');

        self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'A 400 means the form was rejected.');
        self::assertSame('enabled', $this->storedValue(SharedCacheMode::CONFIG_KEY));
        self::assertSame('600', $this->storedValue(SharedCachePolicy::TTL_CONFIG_KEY));
    }

    public function testAnUnknownModeIsRefusedAndNothingIsWritten(): void
    {
        $this->submit('enabled', '600');
        $this->submit('everything', '900', false);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('enabled', $this->storedValue(SharedCacheMode::CONFIG_KEY));
        self::assertSame('600', $this->storedValue(SharedCachePolicy::TTL_CONFIG_KEY));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLifetimes(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'above a day' => [(string) (SharedCachePolicy::MAX_TTL + 1)];
    }

    #[DataProvider('invalidLifetimes')]
    public function testALifetimeOutsideTheBoundsIsRefused(string $lifetime): void
    {
        $this->submit('announce', '120');
        $this->submit('enabled', $lifetime, false);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('announce', $this->storedValue(SharedCacheMode::CONFIG_KEY));
        self::assertSame('120', $this->storedValue(SharedCachePolicy::TTL_CONFIG_KEY));
    }

    public function testThePageOffersTheThreeModesAndTheCurrentOneIsSelected(): void
    {
        $this->submit('announce', '120');

        $crawler = $this->client->request('GET', '/admin/configuration/store');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $options = $crawler->filter('[data-testid="config-store-shared-cache-mode"] option');
        self::assertCount(3, $options);
        self::assertSame('announce', $crawler->filter('[data-testid="config-store-shared-cache-mode"] option[selected]')->attr('value'));
        self::assertSame('120', $crawler->filter('[data-testid="config-store-shared-cache-ttl"]')->attr('value'));
    }

    private function submit(string $mode, string $lifetime, bool $expectRedirect = true): void
    {
        $crawler = $this->client->request('GET', '/admin/configuration/store');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $form = $crawler->filter('[data-testid="config-store-save-stay"]')->form([
            'thelia_configuration_store[store_name]' => 'Test Store',
            'thelia_configuration_store[store_email]' => 'store@test.com',
            'thelia_configuration_store[store_notification_emails]' => 'store@test.com',
            'thelia_configuration_store[store_address1]' => '1 Main Street',
            'thelia_configuration_store[store_zipcode]' => '75001',
            'thelia_configuration_store[store_city]' => 'Paris',
            'thelia_configuration_store[shared_cache_ttl]' => $lifetime,
        ]);

        // A value outside the offered choices is rejected by the browser-kit form itself.
        $form->disableValidation();
        $form->setValues(['thelia_configuration_store[shared_cache_mode]' => $mode]);

        $this->client->submit($form);

        if ($expectRedirect) {
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'A 400 means the form was rejected.');
        }
    }

    private function storedValue(string $name): ?string
    {
        ConfigQuery::resetCache();

        return ConfigQuery::create()->findOneByName($name)?->getValue();
    }
}
