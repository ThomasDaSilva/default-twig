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
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * An action on an order answers the right first: an administrator who may not update orders
 * learns nothing of an order id, whether the order exists or not.
 */
final class OrderActionRightFirstTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actions(): iterable
    {
        yield 'delivery reference' => ['/delivery-ref'];
        yield 'address' => ['/address'];
    }

    #[DataProvider('actions')]
    public function testAnAdministratorWhoMayNotUpdateOrdersIsRefusedWhetherTheOrderExistsOrNot(string $action): void
    {
        $admin = $this->factory->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW]]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
        $order = $this->factory->order();

        $this->client->request('POST', '/admin/order/update/'.$order->getId().$action);
        $existing = $this->client->getResponse()->getStatusCode();

        $this->client->request('POST', '/admin/order/update/999999999'.$action);
        $missing = $this->client->getResponse()->getStatusCode();

        self::assertSame(403, $existing);
        self::assertSame(403, $missing, 'A missing order answers like an existing one.');
    }
}
