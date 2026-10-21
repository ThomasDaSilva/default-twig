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

use BackOfficeDefaultTwigBundle\Controller\Configuration\AreaZipCodeRuleController;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use Propel\Runtime\Exception\InvalidArgumentException as PropelInvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\Area;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Thelia\Tools\TokenProvider;

/**
 * The postal code rule actions of a shipping zone, given the writer a core hands them.
 *
 * On a core that has no postal code rules, the writer the controller asks its locator for
 * does not exist, and the actions answer 404 instead of failing: the controller is given
 * an empty locator, as the container of such a core builds it. The zone page itself checks
 * `class_exists()` on a class of the core, which a test running on a core that has the
 * class cannot take away: the CI of the theme, which installs it on the released core,
 * covers that side.
 */
final class ZipCodeRulesOnAnOlderCoreTest extends WebIntegrationTestCase
{
    private const string RULE_QUERY = 'Thelia\\Model\\AreaZipCodeRuleQuery';

    private AdminSessionInjector $injector;

    /**
     * The services the controller asked its locator about.
     */
    private \ArrayObject $asked;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->asked = new \ArrayObject();
        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        // Absent when setUp() skipped the test on a shop without the Twig back office.
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testAddingARuleOnACoreWithoutRulesIsNotFoundByTheAction(): void
    {
        $area = $this->area();
        $this->controllerWith(new ServiceLocator([]));

        $this->assertTheActionAnswersNotFound('/admin/configuration/shipping_configuration/'.$area->getId().'/zip-code-rules', ['pattern' => '20*', 'excluded' => '1']);

        if (class_exists(self::RULE_QUERY)) {
            self::assertSame(0, (self::RULE_QUERY)::create()->filterByAreaId($area->getId())->count());
        }
    }

    public function testDeletingARuleOnACoreWithoutRulesIsNotFoundByTheAction(): void
    {
        $area = $this->area();
        $this->controllerWith(new ServiceLocator([]));

        $this->assertTheActionAnswersNotFound('/admin/configuration/shipping_configuration/'.$area->getId().'/zip-code-rules/delete', ['rule_id' => '1']);
    }

    /**
     * Propel's InvalidArgumentException is a native one too: its message comes from the
     * database layer and must not reach the merchant as the reason of a refusal.
     */
    public function testAnInvalidArgumentOfTheDatabaseLayerIsNotShownToTheMerchant(): void
    {
        $area = $this->area();
        $failingWriter = new class {
            public function add(int $areaId, string $pattern, bool $excluded, ?int $countryId): never
            {
                throw new PropelInvalidArgumentException('Internal detail of the database layer');
            }
        };
        $this->controllerWith(new ServiceLocator(['writer' => static fn (): object => $failingWriter]));
        $token = $this->token($area);

        $this->client->request('POST', '/admin/configuration/shipping_configuration/'.$area->getId().'/zip-code-rules', ['pattern' => '20*', 'excluded' => '1', '_token' => $token]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->client->followRedirect();
        $flash = $this->client->getCrawler()->filter('[data-testid="bo-flash-danger"]')->text();
        self::assertStringContainsString('The postal code rule could not be saved. Try again later.', $flash);
        self::assertStringNotContainsString('Internal detail', $flash);
    }

    /**
     * The 404 comes from the action, which asked for the writer and threw: not from a router
     * that found no route.
     *
     * @param array<string, string> $body
     */
    private function assertTheActionAnswersNotFound(string $path, array $body): void
    {
        $this->client->catchExceptions(false);

        try {
            $this->client->request('POST', $path, $body);
            self::fail('The action answered instead of throwing.');
        } catch (NotFoundHttpException $exception) {
            self::assertSame('This version of Thelia has no postal code rules.', $exception->getMessage());
        } finally {
            $this->client->catchExceptions(true);
        }

        self::assertSame(['writer'], $this->asked->getArrayCopy(), 'The controller asked for the writer.');
    }

    private function controllerWith(ContainerInterface $locator): void
    {
        $spy = new class($locator, $this->asked) implements ContainerInterface {
            public function __construct(
                private readonly ContainerInterface $locator,
                private readonly \ArrayObject $asked,
            ) {
            }

            public function get(string $id): mixed
            {
                return $this->locator->get($id);
            }

            public function has(string $id): bool
            {
                $this->asked->append($id);

                return $this->locator->has($id);
            }
        };

        static::getContainer()->set(AreaZipCodeRuleController::class, new AreaZipCodeRuleController(
            $this->getService(AdminAccessChecker::class),
            $spy,
            $this->getService(UrlGeneratorInterface::class),
            $this->getService(TranslatorInterface::class),
            $this->getService(TokenProvider::class),
            $this->getService(AdminLogger::class),
            $this->getService(LoggerInterface::class),
        ));
    }

    private function token(Area $area): string
    {
        $html = (string) $this->client->request('GET', '/admin/configuration/shipping_configuration/update/'.$area->getId())->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches) ?: preg_match('/name="_token" value="([^"]+)"/', $html, $matches), 'The zone page renders the back-office token.');

        return $matches[1];
    }

    private function area(): Area
    {
        $area = (new Area())->setName('Zone of an older core');
        $area->save($this->getPropelConnection());

        return $area;
    }
}
