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
use Thelia\Model\Admin;
use Thelia\Model\Config;
use Thelia\Model\ConfigQuery;
use Thelia\Model\MessageQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * Configuration > Mailing system, the test mail.
 */
final class MailingSystemTestMailTest extends WebIntegrationTestCase
{
    private const URL = '/admin/configuration/mailingSystem/test';

    private ?AdminSessionInjector $injector = null;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    /**
     * A mistyped address is the administrator's to fix: they read what is wrong with
     * it, not a server error.
     */
    public function testAMistypedAddressIsNamedAsSuch(): void
    {
        $this->loginAs($this->factory->admin());
        $this->client->request('GET', '/admin/configuration/mailingSystem');
        $this->givenAStoreEmail();

        $this->client->request('GET', self::URL, ['email' => 'admin@@example']);

        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($answer['success']);
        self::assertStringContainsString('admin@@example', $answer['message']);
    }

    /**
     * A message sent as a test answers what became of it: a mistyped recipient is
     * never told the message was sent.
     */
    public function testATestMessageToAMistypedAddressIsNotSaidToBeSent(): void
    {
        $this->loginAs($this->factory->admin());
        $this->client->request('GET', '/admin/configuration/mailingSystem');
        $this->givenAStoreEmail();
        $message = MessageQuery::create()->findOne();
        self::assertNotNull($message);

        $this->client->request('POST', '/admin/message/send/'.$message->getId(), ['recipient_email' => 'admin@@example']);

        $answer = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('successfully sent', $answer);
        self::assertStringContainsString('admin@@example', $answer);
    }

    /**
     * Written after the first request: a config write before it loses the session.
     */
    private function givenAStoreEmail(): void
    {
        $config = ConfigQuery::create()->findOneByName('store_email') ?? (new Config())->setName('store_email');
        $config->setValue('shop@example.com')->save($this->getPropelConnection());
        ConfigQuery::resetCache();
    }

    private function loginAs(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector?->setAdmin($admin);
    }
}
