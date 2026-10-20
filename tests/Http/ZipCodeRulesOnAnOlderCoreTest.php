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
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\Area;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Thelia\Tools\TokenProvider;

/**
 * The postal code rules of a shipping zone on a core that has none: the writer the
 * controller asks its locator for does not exist there, and the actions answer 404 instead
 * of failing. The controller is given an empty locator, as the container of such a core
 * builds it.
 *
 * The zone page itself checks `class_exists()` on a class of the core, which a test running
 * on a core that has the class cannot take away: the CI of the theme, which installs it on
 * the released core, covers that side.
 */
final class ZipCodeRulesOnAnOlderCoreTest extends WebIntegrationTestCase
{
    private const string RULE_QUERY = 'Thelia\\Model\\AreaZipCodeRuleQuery';

    private AdminSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        static::getContainer()->set(AreaZipCodeRuleController::class, new AreaZipCodeRuleController(
            $this->getService(AdminAccessChecker::class),
            new ServiceLocator([]),
            $this->getService(UrlGeneratorInterface::class),
            $this->getService(TranslatorInterface::class),
            $this->getService(TokenProvider::class),
            $this->getService(AdminLogger::class),
            $this->getService(LoggerInterface::class),
        ));
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

    public function testAddingARuleAnswersNotFound(): void
    {
        $area = $this->area();

        $this->client->request('POST', '/admin/configuration/shipping_configuration/'.$area->getId().'/zip-code-rules', ['pattern' => '20*', 'excluded' => '1']);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertNoRuleWritten($area);
    }

    public function testDeletingARuleAnswersNotFound(): void
    {
        $area = $this->area();

        $this->client->request('POST', '/admin/configuration/shipping_configuration/'.$area->getId().'/zip-code-rules/delete', ['rule_id' => '1']);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function area(): Area
    {
        $area = (new Area())->setName('Zone of an older core');
        $area->save($this->getPropelConnection());

        return $area;
    }

    private function assertNoRuleWritten(Area $area): void
    {
        if (class_exists(self::RULE_QUERY)) {
            self::assertSame(0, (self::RULE_QUERY)::create()->filterByAreaId($area->getId())->count());
        }
    }
}
