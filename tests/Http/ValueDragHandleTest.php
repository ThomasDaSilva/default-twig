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
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The values of an attribute or a feature are reordered by dragging: each row shows a
 * handle in front of its title, and only the handle starts the drag, so that the text
 * of a title can still be selected with the mouse.
 */
final class ValueDragHandleTest extends WebIntegrationTestCase
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
     * @return iterable<string, array{string}>
     */
    public static function valueSheets(): iterable
    {
        yield 'attribute' => ['attribute'];
        yield 'feature' => ['feature'];
    }

    #[DataProvider('valueSheets')]
    public function testEachValueRowStartsWithTheHandleThatDragsIt(string $kind): void
    {
        if ($kind === 'attribute') {
            $owner = $this->factory->attribute();
            $values = [$this->factory->attributeAv($owner), $this->factory->attributeAv($owner)];
            $url = '/admin/configuration/attributes/update?attribute_id='.$owner->getId();
            $prefix = 'attributeav';
        } else {
            $owner = $this->factory->feature();
            $values = [$this->factory->featureAv($owner), $this->factory->featureAv($owner)];
            $url = '/admin/configuration/features/update?feature_id='.$owner->getId();
            $prefix = 'featureav';
        }

        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $table = $crawler->filter('[data-testid="'.$prefix.'-table"]');
        self::assertSame(
            $table->filter('thead tr')->first()->filter('th')->count(),
            $table->filter('[data-testid="'.$prefix.'-row-'.$values[0]->getId().'"] > td')->count(),
            'The header has a cell over the handles.',
        );

        foreach ($values as $value) {
            $row = $table->filter('[data-testid="'.$prefix.'-row-'.$value->getId().'"]');
            $firstCell = $row->filter('td')->first();

            self::assertNotNull($firstCell->attr('data-bo-sortable-handle'), 'The first cell of the row is its drag handle.');
            self::assertSame($prefix.'-handle-'.$value->getId(), $firstCell->attr('data-testid'));
            self::assertCount(1, $firstCell->filter('i.bi-grip-vertical'));
            self::assertCount(1, $row->filter('[data-bo-sortable-handle]'), 'One handle per row.');
        }
    }
}
