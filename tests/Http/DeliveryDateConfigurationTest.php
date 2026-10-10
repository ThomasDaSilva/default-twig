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
use Thelia\Model\ConfigQuery;
use Thelia\Model\DeliveryClosureQuery;
use Thelia\Model\DeliveryDateRuleQuery;
use Thelia\Model\DeliverySlotI18nQuery;
use Thelia\Model\DeliverySlotQuery;
use Thelia\Model\Module;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Thelia\Tests\Support\Delivery\RegistersDeliveryDateTestCarrier;

/**
 * The merchant's side of delivery dates: the closed days of the shop, a carrier set to a free
 * day or to slots with a capacity, the exceptional closures, and the day on the order sheet.
 */
final class DeliveryDateConfigurationTest extends WebIntegrationTestCase
{
    use RegistersDeliveryDateTestCarrier;

    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    private Module $carrier;

    /**
     * Null when setUp() skipped the test before reading it: tearDown() then has nothing to restore.
     */
    private ?string $shopClosedWeekdaysBefore = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $this->factory = new FixtureFactory($this->getPropelConnection());
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        $this->carrier = $this->registerDeliveryDateTestCarrier(static::getContainer(), $this->getPropelConnection());
        $this->shopClosedWeekdaysBefore = (string) ConfigQuery::read('delivery_closed_weekdays', '');
    }

    protected function tearDown(): void
    {
        if (null !== $this->shopClosedWeekdaysBefore) {
            ConfigQuery::write('delivery_closed_weekdays', $this->shopClosedWeekdaysBefore);
        }

        // Absent when setUp() skipped the test on a shop without the Twig back office.
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheScreenSaysWhichCarriersTakeDates(): void
    {
        $crawler = $this->client->request('GET', '/admin/configuration/delivery-dates');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="delivery-date-carrier-edit-DeliveryDateTestCarrier"]'), 'A carrier that takes dates can be configured.');
        self::assertCount(0, $crawler->filter('[data-testid="delivery-date-carrier-edit-VirtualProductDelivery"]'), 'A carrier that does not take dates cannot.');
        self::assertCount(1, $crawler->filter('[data-testid="delivery-date-carrier-VirtualProductDelivery"]'));
        self::assertCount(7, $crawler->filter('[data-testid="delivery-date-shop-weekdays"] input[type="checkbox"]'));
    }

    /**
     * Recette 2: a free day, two days of delay, three weeks of horizon, closed on Sundays.
     */
    public function testTheMerchantSetsAFreeDayWithADelayAHorizonAndClosedSundays(): void
    {
        $this->post('/admin/configuration/delivery-dates/carrier/'.$this->carrier->getId().'/save', [
            'choice_mode' => 'date',
            'minimum_delay_days' => '2',
            'horizon_days' => '21',
            'closed_weekdays' => ['7'],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        $rule = DeliveryDateRuleQuery::create()->findOneByModuleId($this->carrier->getId());
        self::assertNotNull($rule);
        self::assertSame('date', $rule->getChoiceMode());
        self::assertSame(2, (int) $rule->getMinimumDelayDays());
        self::assertSame(21, (int) $rule->getHorizonDays());
        self::assertSame('7', $rule->getClosedWeekdays());

        $crawler = $this->client->request('GET', '/admin/configuration/delivery-dates/carrier/'.$this->carrier->getId());
        self::assertSame('21', $crawler->filter('#bo-delivery-date-horizon')->attr('value'));
        self::assertNotNull($crawler->filter('#delivery-date-carrier-weekdays-7')->attr('checked'), 'Sunday is ticked.');
        self::assertNull($crawler->filter('#delivery-date-carrier-weekdays-6')->attr('checked'));

        $this->post('/admin/configuration/delivery-dates/carrier/'.$this->carrier->getId().'/save', [
            'choice_mode' => 'date',
            'minimum_delay_days' => '2',
            'horizon_days' => '21',
            'follows_shop' => '1',
        ]);
        self::assertNull(DeliveryDateRuleQuery::create()->findOneByModuleId($this->carrier->getId())?->getClosedWeekdays(), 'Ticked, the carrier follows the shop again.');
    }

    public function testASettingTheCoreRefusesIsSaidAndNotSaved(): void
    {
        $this->post('/admin/configuration/delivery-dates/carrier/'.$this->carrier->getId().'/save', [
            'choice_mode' => 'date',
            'minimum_delay_days' => '10',
            'horizon_days' => '3',
        ]);

        self::assertNull(DeliveryDateRuleQuery::create()->findOneByModuleId($this->carrier->getId()));

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('cannot come before', $crawler->filter('.alert-danger')->text(''));
    }

    /**
     * Recette 4: slots of two hours with a capacity of two orders.
     */
    public function testASlotIsAddedRenamedAndDeleted(): void
    {
        $add = '/admin/configuration/delivery-dates/carrier/'.$this->carrier->getId().'/slot/add';
        $this->post($add, ['start_time' => '09:00', 'end_time' => '11:00', 'capacity' => '2', 'title' => 'Morning', 'locale' => 'en_US']);
        $this->post($add, ['start_time' => '11:00', 'end_time' => '13:00', 'capacity' => '', 'title' => '', 'locale' => 'en_US']);

        $slots = DeliverySlotQuery::create()->filterByModuleId($this->carrier->getId())->orderByPosition()->find();
        self::assertCount(2, $slots);
        self::assertSame(2, (int) $slots[0]->getCapacity());
        self::assertNull($slots[1]->getCapacity(), 'An empty capacity is no limit.');
        self::assertSame('Morning', DeliverySlotI18nQuery::create()->filterById($slots[0]->getId())->filterByLocale('en_US')->findOne()?->getTitle());

        $this->post('/admin/configuration/delivery-dates/slot/'.$slots[0]->getId().'/save', ['start_time' => '08:00', 'end_time' => '10:00', 'capacity' => '3', 'title' => 'Early', 'locale' => 'en_US']);
        $edited = DeliverySlotQuery::create()->findPk($slots[0]->getId());
        self::assertSame('08:00', $edited?->getStartTime('H:i'));
        self::assertSame(3, (int) $edited?->getCapacity());

        $crawler = $this->client->request('GET', '/admin/configuration/delivery-dates/carrier/'.$this->carrier->getId().'?edit_language_id='.$this->langIdOf('en_US'));
        self::assertSame('Early', $crawler->filter('[data-testid="delivery-date-slot-'.$slots[0]->getId().'"] input[name="title"]')->attr('value'));

        $this->post('/admin/configuration/delivery-dates/slot/'.$slots[1]->getId().'/delete', []);
        self::assertNull(DeliverySlotQuery::create()->findPk($slots[1]->getId()));
    }

    /**
     * Recette 6: an exceptional closure of the shop, then of the carrier.
     */
    public function testClosuresOfTheShopAndOfACarrierAreAddedAndDeleted(): void
    {
        $this->post('/admin/configuration/delivery-dates/closure/add', ['module_id' => '0', 'start_date' => '2030-08-01', 'end_date' => '2030-08-15', 'label' => 'Summer']);
        $this->post('/admin/configuration/delivery-dates/closure/add', ['module_id' => (string) $this->carrier->getId(), 'start_date' => '2030-12-25', 'end_date' => '2030-12-25']);
        $this->post('/admin/configuration/delivery-dates/closure/add', ['module_id' => '0', 'start_date' => '2030-08-15', 'end_date' => '2030-08-01']);

        $shop = DeliveryClosureQuery::create()->filterByModuleId(null, \Propel\Runtime\ActiveQuery\Criteria::ISNULL)->filterByLabel('Summer')->findOne();
        self::assertNotNull($shop);
        self::assertSame(1, DeliveryClosureQuery::create()->filterByModuleId($this->carrier->getId())->count());
        self::assertSame(0, DeliveryClosureQuery::create()->filterByStartDate('2030-08-15')->filterByEndDate('2030-08-01')->count(), 'A closure ending before it starts is refused.');

        $crawler = $this->client->request('GET', '/admin/configuration/delivery-dates');
        self::assertCount(1, $crawler->filter('[data-testid="delivery-date-closure-'.$shop->getId().'"]'));

        $this->post('/admin/configuration/delivery-dates/closure/'.$shop->getId().'/delete', []);
        self::assertNull(DeliveryClosureQuery::create()->findPk($shop->getId()));
    }

    public function testTheShopClosedDaysAreSaved(): void
    {
        $this->post('/admin/configuration/delivery-dates/shop/save', ['closed_weekdays' => ['6', '7']]);

        self::assertSame('6,7', ConfigQuery::read('delivery_closed_weekdays'));
    }

    public function testAnAdministratorWhoMayOnlyLookCannotWrite(): void
    {
        $this->injector->setAdmin($this->limitedAdmin([\Thelia\Core\Security\AccessManager::VIEW]));

        $this->client->request('GET', '/admin/configuration/delivery-dates');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'Looking is allowed.');

        $this->post('/admin/configuration/delivery-dates/carrier/'.$this->carrier->getId().'/save', ['choice_mode' => 'date', 'minimum_delay_days' => '0', 'horizon_days' => '7']);
        $this->post('/admin/configuration/delivery-dates/closure/add', ['module_id' => '0', 'start_date' => '2030-01-01', 'end_date' => '2030-01-02']);
        $this->post('/admin/configuration/delivery-dates/carrier/'.$this->carrier->getId().'/slot/add', ['start_time' => '09:00', 'end_time' => '11:00', 'capacity' => '', 'locale' => 'en_US']);

        self::assertNull(DeliveryDateRuleQuery::create()->findOneByModuleId($this->carrier->getId()));
        self::assertSame(0, DeliveryClosureQuery::create()->filterByStartDate('2030-01-01')->count());
        self::assertSame(0, DeliverySlotQuery::create()->filterByModuleId($this->carrier->getId())->count());
    }

    public function testAnAdministratorWithoutTheRightSeesNothing(): void
    {
        $this->injector->setAdmin($this->limitedAdmin([]));

        $crawler = $this->client->request('GET', '/admin/configuration/delivery-dates');

        self::assertCount(0, $crawler->filter('[data-testid="delivery-date-page"]'));
        $this->client->request('GET', '/admin/configuration/delivery-dates/carrier/'.$this->carrier->getId());
        self::assertStringNotContainsString('delivery-date-carrier-page', (string) $this->client->getResponse()->getContent());
    }

    public function testNothingIsWrittenWithoutTheToken(): void
    {
        $this->client->request('POST', '/admin/configuration/delivery-dates/carrier/'.$this->carrier->getId().'/save', [
            'choice_mode' => 'date',
            'minimum_delay_days' => '0',
            'horizon_days' => '7',
        ]);

        self::assertNull(DeliveryDateRuleQuery::create()->findOneByModuleId($this->carrier->getId()));
    }

    /**
     * Recette 3: the day and the hours on the order sheet.
     */
    public function testTheOrderSheetShowsTheRequestedDayAndHours(): void
    {
        $order = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        $order->setDeliveryDate('2030-03-14')->setDeliverySlotStart('09:00:00')->setDeliverySlotEnd('11:00:00')->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());
        $line = $crawler->filter('[data-testid="order-delivery-date"]');

        self::assertCount(1, $line);
        self::assertStringContainsString('2030', $line->text());
        self::assertStringContainsString('09:00', $line->text());
        self::assertStringContainsString('11:00', $line->text());

        $plain = $this->factory->order($this->factory->customer($this->factory->customerTitle()));
        self::assertCount(0, $this->client->request('GET', '/admin/order/update/'.$plain->getId())->filter('[data-testid="order-delivery-date"]'), 'An order without a day shows nothing.');
    }

    /**
     * @param list<string> $accesses on the delivery date resource, none at all for an empty list
     */
    private function limitedAdmin(array $accesses): \Thelia\Model\Admin
    {
        $admin = $this->factory->restrictedAdmin(
            [] === $accesses ? ['admin.configuration.gift-wrapping' => [\Thelia\Core\Security\AccessManager::VIEW]] : ['admin.configuration.delivery-date' => $accesses],
        );
        $admin->eraseCredentials();

        return $admin;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): void
    {
        $this->client->request('POST', $path, $body + ['_token' => $this->token()]);
    }

    private function token(): string
    {
        $html = (string) $this->client->request('GET', '/admin/configuration/delivery-dates')->html();
        // The token of the session, rendered in the head of every back-office page: an
        // administrator who may only look gets no form to read it from.
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches) ?: preg_match('/name="_token" value="([^"]+)"/', $html, $matches), 'The screen renders the back-office token.');

        return $matches[1];
    }

    private function langIdOf(string $locale): int
    {
        return (int) \Thelia\Model\LangQuery::create()->findOneByLocale($locale)?->getId();
    }
}
