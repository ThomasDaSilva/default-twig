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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * A tab a module adds to an edit sheet through `<entity>.tab` reopens after a save, as the
 * native tabs do, when the sheet is asked for it by `current_tab`; a tab the sheet does not
 * have falls back to the general one instead of leaving every tab closed.
 */
final class ModuleTabTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    /** @var list<array{0: string, 1: callable}> */
    private array $listeners = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
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
        if (isset($this->injector)) {
            $this->injector->clear();
        }

        foreach ($this->listeners as [$eventName, $listener]) {
            $this->getService(EventDispatcherInterface::class)->removeListener($eventName, $listener);
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sheets(): iterable
    {
        yield 'product' => ['product'];
        yield 'category' => ['category'];
        yield 'folder' => ['folder'];
        yield 'brand' => ['brand'];
    }

    #[DataProvider('sheets')]
    public function testTheSheetReopensOnTheModuleTabItIsAskedFor(string $entity): void
    {
        $this->moduleTab($entity);

        $crawler = $this->sheet($entity, 'notes');

        self::assertSame(['notes'], $this->activeTabs($crawler, $entity));
        self::assertSame(['notes'], $this->shownPanes($crawler));
    }

    #[DataProvider('sheets')]
    public function testAnUnknownTabFallsBackToTheGeneralOne(string $entity): void
    {
        $this->moduleTab($entity);

        $crawler = $this->sheet($entity, 'tickets');

        self::assertSame(['general'], $this->activeTabs($crawler, $entity), 'A tab of a module that is not there, or a typo.');
        self::assertSame(['tab-general'], $this->shownPanes($crawler));
    }

    #[DataProvider('sheets')]
    public function testTheNativeTabsOpenAsBefore(string $entity): void
    {
        $this->moduleTab($entity);

        self::assertSame(['general'], $this->activeTabs($this->sheet($entity, null), $entity));
        self::assertSame(['seo'], $this->activeTabs($this->sheet($entity, 'seo'), $entity));
    }

    private function moduleTab(string $entity): void
    {
        $eventName = 'hook.'.TemplateDefinition::BACK_OFFICE.'.'.$entity.'.tab';
        $listener = static function (HookRenderBlockEvent $event): void {
            $event->add(['id' => 'notes', 'title' => 'Notes', 'content' => '<p data-testid="module-notes-body">Notes</p>']);
        };

        $this->getService(EventDispatcherInterface::class)->addListener($eventName, $listener);
        $this->listeners[] = [$eventName, $listener];
    }

    private function sheet(string $entity, ?string $currentTab): Crawler
    {
        $parameters = match ($entity) {
            'product' => ['product_id' => $this->factory->product(
                $this->factory->category(),
                $this->factory->taxRule(),
                CurrencyQuery::create()->findOneByByDefault(1, $this->getPropelConnection()) ?? $this->factory->currency(['byDefault' => 1]),
            )->getId()],
            'category' => ['category_id' => $this->factory->category()->getId()],
            'folder' => ['folder_id' => $this->factory->folder()->getId()],
            'brand' => ['brand_id' => $this->factory->brand()->getId()],
        };
        $route = match ($entity) {
            'product' => 'admin.products.update',
            'category' => 'admin.categories.update',
            'folder' => 'admin.folders.update',
            'brand' => 'admin.brand.update',
        };

        if (null !== $currentTab) {
            $parameters['current_tab'] = $currentTab;
        }

        $router = $this->getService('router');
        \assert($router instanceof UrlGeneratorInterface);

        $crawler = $this->client->request('GET', $router->generate($route, $parameters));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 500));

        return $crawler;
    }

    /**
     * @return list<string> the tabs shown as active, by the suffix of their test id
     */
    private function activeTabs(Crawler $crawler, string $entity): array
    {
        $prefix = $entity.'-tab-';

        return $crawler->filter(\sprintf('button.nav-link.active[data-testid^="%s"]', $prefix))
            ->each(static fn (Crawler $tab): string => substr((string) $tab->attr('data-testid'), \strlen($prefix)));
    }

    /**
     * @return list<string> the ids of the panes shown
     */
    private function shownPanes(Crawler $crawler): array
    {
        return $crawler->filter('.tab-pane.show.active')->each(static fn (Crawler $pane): string => (string) $pane->attr('id'));
    }
}
