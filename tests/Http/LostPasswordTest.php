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

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;

/**
 * The lost password form mails the administrator it names a link that sets a new
 * password: it is what a visitor who knows a login may ask for, a few times.
 */
final class LostPasswordTest extends WebIntegrationTestCase
{
    private const URL = '/admin/lost-password';

    private const PER_ADMIN = 5;

    private const PER_CALLER = 10;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->factory = new FixtureFactory($this->getPropelConnection());
        // The throttle of the caller survives the test transaction: the key AuthThrottle
        // writes for the test client, whose address is 127.0.0.1.
        $this->getService(CacheItemPoolInterface::class)->deleteItem('bo_auth_throttle.'.sha1(inet_pton('127.0.0.1').'|lost-password'));
    }

    /**
     * A request that names an administrator is a request like the others: it does not
     * give the caller a fresh count of attempts to guess the next login with.
     */
    public function testAValidRequestDoesNotRenewTheCallersAttempts(): void
    {
        $admins = [];
        for ($asked = 0; $asked < self::PER_CALLER; ++$asked) {
            // Each administrator is asked for once: only the count per caller is at play.
            $admin = $this->factory->admin();
            $this->resetTheLimitOf($admin);
            $this->ask($admin->getLogin());
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), \sprintf('request %d', $asked + 1));
        }

        $this->ask('nobody-by-that-name');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Too many attempts', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Whoever knows a login mails that administrator a few links an hour, not a flood.
     */
    public function testAnAdministratorIsMailedFiveLinksAnHourNotOneMore(): void
    {
        $admin = $this->factory->admin();
        $this->resetTheLimitOf($admin);

        for ($asked = 0; $asked < self::PER_ADMIN; ++$asked) {
            $this->ask($admin->getLogin());
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), \sprintf('request %d', $asked + 1));
        }

        $this->ask($admin->getLogin());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Too many attempts', (string) $this->client->getResponse()->getContent());
    }

    private function ask(string $login): void
    {
        $crawler = $this->client->request('GET', self::URL);
        $token = (string) $crawler->filter('input[name="lost_password[_token]"]')->attr('value');

        $this->client->request('POST', self::URL, ['lost_password' => ['username_or_email' => $login, '_token' => $token]]);
    }

    private function resetTheLimitOf(Admin $admin): void
    {
        $limiter = $this->getService('limiter.admin_lost_password');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);
        $limiter->create((string) $admin->getId())->reset();
    }
}
