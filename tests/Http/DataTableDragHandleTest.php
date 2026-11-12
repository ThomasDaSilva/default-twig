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
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * A list reordered by dragging starts each row with the handle that drags it, so that the text of
 * the row can still be selected with the mouse; a list that is not reordered shows no handle.
 */
final class DataTableDragHandleTest extends WebIntegrationTestCase
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

    public function testEachRowOfAReorderedListStartsWithItsHandle(): void
    {
        $currency = $this->factory->currency();

        $table = $this->client->request('GET', '/admin/configuration/currencies')->filter('[data-testid="datatable-currencies-table"]');
        self::assertResponseIsSuccessful();

        $headerCells = $table->filter('thead tr')->first()->filter('th');
        self::assertStringContainsString('bo-sortable-grip', (string) $headerCells->first()->attr('class'), 'The header has a cell over the handles.');

        $row = $table->filter('tbody tr[data-row-id="'.$currency->getId().'"]');
        $firstCell = $row->filter('td')->first();
        self::assertNotNull($firstCell->attr('data-bo-sortable-handle'), 'The first cell of the row is its drag handle.');
        self::assertCount(1, $firstCell->filter('i.bi-grip-vertical'));
        self::assertCount(1, $row->filter('[data-bo-sortable-handle]'), 'One handle per row.');
        self::assertSame($headerCells->count(), $row->filter('td')->count(), 'The handle column lines up with the header.');

        $details = $table->filter('[data-testid="datatable-currencies-details"]');
        self::assertGreaterThan(0, $details->count(), 'The currency list folds columns away on a narrow screen: its rows have a detail line.');
        self::assertSame((string) $headerCells->count(), $details->first()->filter('td')->attr('colspan'), 'The detail line spans the handle column too.');
        self::assertCount(0, $details->filter('[data-bo-sortable-handle]'), 'The detail line is not dragged on its own.');
    }

    public function testAListThatIsNotReorderedShowsNoHandle(): void
    {
        $table = $this->client->request('GET', '/admin/orders')->filter('[data-testid="datatable-orders-table"]');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $table, 'The order list is drawn.');
        self::assertCount(0, $table->filter('.bo-sortable-grip, [data-bo-sortable-handle]'));
    }
}
