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

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\Accounting\AccountingChart;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * Configuration > Accounting: the optional account of commercial credit notes, saved with
 * the chart and refused when it is not an account number.
 */
final class AccountingCreditNoteAccountScreenTest extends WebIntegrationTestCase
{
    private const string PAGE = '/admin/configuration/accounting';

    private ?AdminSessionInjector $injector = null;

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
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();
        parent::tearDown();
        ConfigQuery::resetCache();
    }

    public function testTheAccountOfCommercialCreditNotesIsSavedAndShownAgain(): void
    {
        $this->client->request('POST', self::PAGE, $this->chart(' 709000 '));

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('709000', ConfigQuery::read(AccountingChart::COMMERCIAL_CREDIT_ACCOUNT_KEY));

        $crawler = $this->client->request('GET', self::PAGE);
        self::assertSame('709000', $crawler->filter('[data-testid="accounting-commercial-credit-account"]')->attr('value'));

        $this->client->request('POST', self::PAGE, $this->chart(''));

        self::assertSame('', ConfigQuery::read(AccountingChart::COMMERCIAL_CREDIT_ACCOUNT_KEY), 'Emptied, the sales account of the rate takes the commercial credit notes again.');
    }

    public function testAnAccountThatIsNotANumberIsRefusedAndNothingIsWritten(): void
    {
        ConfigQuery::write(AccountingChart::COMMERCIAL_CREDIT_ACCOUNT_KEY, '709000');

        $crawler = $this->client->request('POST', self::PAGE, $this->chart('709 000'));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('709 000', $crawler->filter('[data-testid="accounting-error"]')->text(''));
        self::assertSame('709000', ConfigQuery::read(AccountingChart::COMMERCIAL_CREDIT_ACCOUNT_KEY));
    }

    /**
     * @return array<string, mixed>
     */
    private function chart(string $commercialCreditAccount): array
    {
        $crawler = $this->client->request('GET', self::PAGE);
        $values = $crawler->filter('[data-testid="accounting-save"]')->form()->getPhpValues();
        $values['customer_account'] = '411000';
        $values['shipping_account'] = '708500';
        $values['rates'][0] = ['rate' => '20', 'product' => '706200', 'tax' => '445720'];
        $values['commercial_credit_account'] = $commercialCreditAccount;

        return $values;
    }
}
