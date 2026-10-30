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

namespace BackOfficeDefaultTwigBundle\Tests\Query;

use BackOfficeDefaultTwigBundle\Repository\CustomerRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Repository\SearchRepository;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerFilters;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;
use Thelia\Model\Customer;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * A phone number is stored the way the customer or the shop typed it, most often in
 * the international form (+33 6 12 34 56 78), and searched the way the admin reads
 * it on a text message (06 12 34 56 78, 0612345678). The search box of the customer
 * list, its phone filter and the global search compare the digits only, the leading
 * zeros of the searched number standing for the country code.
 */
final class CustomerPhoneSearchTest extends IntegrationTestCase
{
    private CustomerRepository $customers;

    private FixtureFactory $factory;

    /** Eight random digits after the 6 of a French mobile, so the test only meets its own customers. */
    private string $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $orderAccess = new OrderAccess(new AdminSaleUnitScope());
        $this->customers = new CustomerRepository(new OrderRepository($orderAccess), $orderAccess);
        $this->factory = $this->createFixtureFactory();
        $this->subscriber = (string) random_int(10000000, 99999999);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function waysOfTypingTheNumber(): iterable
    {
        yield 'national, spaced' => ['06 %s %s %s %s'];
        yield 'national, packed' => ['06%s%s%s%s'];
        yield 'national, dotted' => ['06.%s.%s.%s.%s'];
        yield 'international' => ['+336%s%s%s%s'];
        yield 'international, double zero' => ['0033 6 %s %s %s %s'];
    }

    #[DataProvider('waysOfTypingTheNumber')]
    public function testTheSearchBoxFindsTheCustomerByTheMobileOfOneOfTheirAddresses(string $typed): void
    {
        $customer = $this->customerWithPhones(cellphone: $this->stored());

        self::assertContains((int) $customer->getId(), $this->listed(['q' => $this->typed($typed)]));
    }

    public function testTheSearchBoxFindsTheCustomerByTheLandlineOfASecondAddress(): void
    {
        $customer = $this->customerWithPhones(cellphone: '');
        $second = $this->factory->address($customer);
        $second->setPhone('+33 1 '.$this->pairs())->save($this->getPropelConnection());

        self::assertContains((int) $customer->getId(), $this->listed(['q' => '01'.str_replace(' ', '', $this->pairs())]));
    }

    public function testTheLastDigitsAreEnough(): void
    {
        $customer = $this->customerWithPhones(cellphone: $this->stored());

        self::assertContains((int) $customer->getId(), $this->listed(['q' => substr($this->subscriber, -6)]));
    }

    public function testTheSearchBoxStillFindsTheCustomerByName(): void
    {
        $name = 'Phone'.bin2hex(random_bytes(4));
        $customer = $this->factory->customer($this->factory->customerTitle(), ['lastname' => $name]);

        self::assertSame([(int) $customer->getId()], $this->listed(['q' => $name]));
    }

    public function testATermWithLettersOrTooFewDigitsIsNotSearchedAsAPhone(): void
    {
        $customer = $this->customerWithPhones(cellphone: $this->stored());

        self::assertNotContains((int) $customer->getId(), $this->listed(['q' => 'tel 06'.$this->subscriber]), 'Letters: a name or an email, not a phone.');
        self::assertNotContains((int) $customer->getId(), $this->listed(['q' => $this->digitsOnlyInThePhone($customer, 3)]), 'Three digits would match most of the customers.');
    }

    public function testThePhoneFilterComparesTheDigitsToo(): void
    {
        $customer = $this->customerWithPhones(cellphone: $this->stored());

        self::assertContains((int) $customer->getId(), $this->listed(['phone' => $this->typed('06 %s %s %s %s')]));
    }

    public function testTheGlobalSearchFindsTheCustomerByPhone(): void
    {
        $customer = $this->customerWithPhones(cellphone: $this->stored());

        $found = (new SearchRepository(new OrderAccess(new AdminSaleUnitScope())))->findCustomers($this->typed('06 %s %s %s %s'));

        self::assertContains((int) $customer->getId(), array_map(static fn (Customer $row): int => (int) $row->getId(), iterator_to_array($found)));
    }

    private function customerWithPhones(string $cellphone): Customer
    {
        $customer = $this->factory->customer($this->factory->customerTitle());
        $address = $this->factory->address($customer);
        $address->setCellphone($cellphone)->save($this->getPropelConnection());

        return $customer;
    }

    /** A run of the subscriber digits found neither in the reference nor in the email, which the search box also reads. */
    private function digitsOnlyInThePhone(Customer $customer, int $length): string
    {
        for ($start = 0; $start <= \strlen($this->subscriber) - $length; ++$start) {
            $run = substr($this->subscriber, $start, $length);
            if (!str_contains((string) $customer->getRef(), $run) && !str_contains((string) $customer->getEmail(), $run)) {
                return $run;
            }
        }

        self::markTestSkipped('Every run of the random number appears in the reference or the email.');
    }

    /** The subscriber digits split in pairs: 12 34 56 78. */
    private function pairs(): string
    {
        return implode(' ', str_split($this->subscriber, 2));
    }

    private function stored(): string
    {
        return '+33 6 '.$this->pairs();
    }

    private function typed(string $format): string
    {
        return \sprintf($format, ...str_split($this->subscriber, 2));
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<int>
     */
    private function listed(array $query): array
    {
        $page = $this->customers->findPaginated(CustomerFilters::fromRequest(new Request($query)), 1, 100);

        $ids = [];
        foreach ($page['rows'] as $customer) {
            $ids[] = (int) $customer->getId();
        }

        return $ids;
    }
}
