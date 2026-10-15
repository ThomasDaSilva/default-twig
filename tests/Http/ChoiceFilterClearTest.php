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

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\Category;
use Thelia\Model\ChoiceFilter;
use Thelia\Model\ChoiceFilterQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Event\ChoiceFilterEvent;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * Emptying the filters of a category, by the reset button or by a save with no filter
 * left, deletes each row on its own: a listener of the deletion (the shared cache of the
 * core purges the pages that read the filters) hears every one of them.
 */
final class ChoiceFilterClearTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    /** @var list<int|null> the category of each filter whose deletion was dispatched */
    private array $deletedFor = [];

    private \Closure $spy;

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

        $this->spy = function (ChoiceFilterEvent $event): void {
            $this->deletedFor[] = $event->getModel()->getCategoryId();
        };
        $this->dispatcher()->addListener(ChoiceFilterEvent::POST_DELETE, $this->spy);
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->dispatcher()->removeSubscriber($this->injector);
            $this->dispatcher()->removeListener(ChoiceFilterEvent::POST_DELETE, $this->spy);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheResetButtonDispatchesTheDeletionOfEachFilter(): void
    {
        $category = $this->categoryWithTwoFilters();

        $this->client->request('POST', '/admin/choicefilter/clear', [
            '_token' => $this->token(),
            'ChoiceFilter' => ['category_id' => $category->getId()],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, ChoiceFilterQuery::create()->filterByCategoryId($category->getId())->count());
        self::assertSame([(int) $category->getId(), (int) $category->getId()], $this->deletedFor);
    }

    public function testSavingAnEmptyListDispatchesTheDeletionOfEachFilter(): void
    {
        $category = $this->categoryWithTwoFilters();

        $this->client->request('POST', '/admin/choicefilter/save', [
            '_token' => $this->token(),
            'ChoiceFilter' => ['category_id' => $category->getId()],
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, ChoiceFilterQuery::create()->filterByCategoryId($category->getId())->count());
        self::assertSame([(int) $category->getId(), (int) $category->getId()], $this->deletedFor);
    }

    private function categoryWithTwoFilters(): Category
    {
        $category = $this->factory->category();

        foreach ([$this->factory->feature(), $this->factory->feature()] as $position => $feature) {
            (new ChoiceFilter())
                ->setCategoryId((int) $category->getId())
                ->setFeatureId((int) $feature->getId())
                ->setVisible(1)
                ->setPosition($position + 1)
                ->setType('checkbox')
                ->save($this->getPropelConnection());
        }

        return $category;
    }

    /**
     * The token the filter form carries, read from the sheet of a template: opening the
     * sheet of a category earlier in the run puts CustomerSheetTest one query over its
     * budget.
     */
    private function token(): string
    {
        $template = $this->factory->template();
        $crawler = $this->client->request('GET', '/admin/configuration/templates/update?template_id='.$template->getId());
        $token = $crawler->filter('[data-testid="choice-filter-form"] input[name="_token"]');

        self::assertSame(1, $token->count(), 'The template sheet renders no form for its filters.');

        return (string) $token->attr('value');
    }

    private function dispatcher(): EventDispatcherInterface
    {
        return $this->getService(EventDispatcherInterface::class);
    }
}
