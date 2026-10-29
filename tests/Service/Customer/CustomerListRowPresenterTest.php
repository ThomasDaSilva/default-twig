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

namespace BackOfficeDefaultTwigBundle\Tests\Service\Customer;

use BackOfficeDefaultTwigBundle\Service\Customer\CustomerListRowPresenter;
use BackOfficeDefaultTwigBundle\Service\I18n\CountryFlag;
use Thelia\Model\Customer;
use Thelia\Test\IntegrationTestCase;

/**
 * The country of a customer shows as a flag before the name, as in the order list,
 * and no longer in a column of its own.
 */
final class CustomerListRowPresenterTest extends IntegrationTestCase
{
    public function testTheFlagStandsBeforeTheNameWithTheCountryOnHover(): void
    {
        $row = $this->present(CountryFlag::of('FR'), 'France <"Métropole">');

        self::assertArrayNotHasKey('country_html', $row);
        self::assertStringContainsString(
            '<div class="fw-semibold"><span class="bo-customer-country" data-bs-toggle="tooltip" title="France &lt;&quot;Métropole&quot;&gt;">'.CountryFlag::of('FR').'</span> <a href=',
            $row['name_html'],
        );
    }

    public function testACustomerWithoutCountryShowsTheNameAlone(): void
    {
        $row = $this->present('', '');

        self::assertStringNotContainsString('bo-customer-country', $row['name_html']);
        self::assertStringContainsString('<div class="fw-semibold"><a href=', $row['name_html']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $countryFlag, string $countryTitle): array
    {
        $factory = $this->createFixtureFactory();
        $customer = $factory->customer($factory->customerTitle(), ['firstname' => 'Jeanne', 'lastname' => 'Martin']);

        return static::getContainer()->get(CustomerListRowPresenter::class)->present(
            customer: $customer,
            locale: 'fr_FR',
            orderCount: 0,
            totalSpent: 0.0,
            lastOrderAt: null,
            phone: '',
            countryFlag: $countryFlag,
            countryTitle: $countryTitle,
            tags: [],
        );
    }
}
