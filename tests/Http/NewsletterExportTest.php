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
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Newsletter;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The subscriber export is opened in a spreadsheet: a name typed by a visitor
 * on the subscription form reaches it as text, never as a formula.
 */
final class NewsletterExportTest extends WebIntegrationTestCase
{
    private const URL = '/admin/newsletter/export';

    private ?AdminSessionInjector $injector = null;

    private FixtureFactory $factory;

    /** @var list<Newsletter> */
    private array $subscribers = [];

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
    }

    protected function tearDown(): void
    {
        foreach ($this->subscribers as $subscriber) {
            $subscriber->delete();
        }

        $this->injector?->clear();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formulaProvider(): iterable
    {
        yield 'equals' => ['=1+2', "'=1+2"];
        yield 'plus' => ['+A1', "'+A1"];
        yield 'minus' => ['-2+3', "'-2+3"];
        yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tab' => ["\t=1", "\"'\t=1\""];
        yield 'carriage return' => ["\r=1", "\"'\r=1\""];
        yield 'already quoted' => ["'=1", "'=1"];
        yield 'negative amount' => ['-5.00', '-5.00'];
        yield 'phone number' => ['+33612345678', '+33612345678'];
        yield 'plain name' => ['Martin', 'Martin'];
    }

    #[DataProvider('formulaProvider')]
    public function testANameIsExportedAsText(string $typed, string $exported): void
    {
        $email = $this->subscribe($typed, 'Doe');

        $this->loginAs($this->factory->admin());
        $this->client->request('GET', self::URL);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringStartsWith($email.','.$exported.',Doe,fr_FR,', $this->lineOf($email));
    }

    public function testANameHoldingTheSeparatorOfAFrenchSpreadsheetIsEnclosed(): void
    {
        $email = $this->subscribe('Rue;=1+2', 'Doe');

        $this->loginAs($this->factory->admin());
        $this->client->request('GET', self::URL);

        self::assertStringStartsWith($email.',"Rue;=1+2",Doe,', $this->lineOf($email));
    }

    public function testABackslashDoesNotKeepAQuoteFromBeingDoubled(): void
    {
        $email = $this->subscribe('A \\"B', 'Doe');

        $this->loginAs($this->factory->admin());
        $this->client->request('GET', self::URL);

        self::assertStringStartsWith($email.',"A \\""B",Doe,', $this->lineOf($email));
    }

    private function subscribe(string $firstname, string $lastname): string
    {
        $email = 'export-'.bin2hex(random_bytes(4)).'@example.com';
        $subscriber = (new Newsletter())
            ->setEmail($email)
            ->setFirstname($firstname)
            ->setLastname($lastname)
            ->setLocale('fr_FR');
        $subscriber->save();
        $this->subscribers[] = $subscriber;

        return $email;
    }

    private function lineOf(string $email): string
    {
        $csv = (string) $this->client->getInternalResponse()->getContent();

        foreach (explode("\n", $csv) as $line) {
            if (str_starts_with($line, $email.',')) {
                return $line;
            }
        }

        self::fail('The subscriber is missing from the export.');
    }

    private function loginAs(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector?->setAdmin($admin);
    }
}
