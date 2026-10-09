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

use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Admin;
use Thelia\Model\Config;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Message;
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

        $this->client->request('POST', self::URL, ['email' => 'admin@@example', '_token' => $this->token()]);

        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($answer['success']);
        self::assertStringContainsString('admin@@example', $answer['message']);
    }

    /**
     * A test mail goes to the address it is given: never on a link followed, never on
     * the word of a page of another site.
     */
    public function testATestMailIsNeverSentByALinkNorWithoutTheToken(): void
    {
        $this->loginAs($this->factory->admin());
        $this->client->request('GET', '/admin/configuration/mailingSystem');
        $this->givenAStoreEmail();

        $this->client->request('GET', self::URL, ['email' => 'someone@example.com']);
        self::assertSame(405, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', self::URL, ['email' => 'someone@example.com']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * A test mail writes to whatever address is typed: an administrator sends ten in ten
     * minutes, not one more.
     */
    public function testAnAdministratorSendsTenTestMailsInTenMinutesNotOneMore(): void
    {
        $this->loginAs($this->factory->admin());
        $this->client->request('GET', '/admin/configuration/mailingSystem');
        $this->givenAStoreEmail();
        $token = $this->token();

        for ($sent = 0; $sent < 10; ++$sent) {
            $this->client->request('POST', self::URL, ['email' => 'someone@example.com', '_token' => $token]);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
        }

        $this->client->request('POST', self::URL, ['email' => 'someone@example.com', '_token' => $token]);
        self::assertSame(429, $this->client->getResponse()->getStatusCode());
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

        $this->client->request('POST', '/admin/message/send/'.$message->getId(), ['recipient_email' => 'admin@@example', '_token' => $this->token()]);

        $answer = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('successfully sent', $answer);
        self::assertStringContainsString('admin@@example', $answer);
    }

    /**
     * A test message goes to the address typed: an administrator who may only read the
     * messages sends none.
     */
    public function testAnAdministratorWhoMayOnlyReadTheMessagesSendsNoTestMessage(): void
    {
        $this->loginAs($this->factory->restrictedAdmin([AdminResources::MESSAGE => [AccessManager::VIEW]]));
        $this->client->request('GET', '/admin/configuration/mailingSystem');
        $this->givenAStoreEmail();
        $message = MessageQuery::create()->findOne();
        self::assertNotNull($message);

        $this->client->request('POST', '/admin/message/send/'.$message->getId(), ['recipient_email' => 'someone@example.com', '_token' => $this->token()]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('successfully sent', (string) $this->client->getResponse()->getContent());
    }

    /**
     * The button to send a test is offered only to who may use it.
     */
    public function testAnAdministratorWhoMayOnlyReadTheMessagesIsOfferedNoTestToSend(): void
    {
        $this->loginAs($this->factory->restrictedAdmin([AdminResources::MESSAGE => [AccessManager::VIEW]]));
        $message = MessageQuery::create()->findOne();
        self::assertNotNull($message);

        $html = (string) $this->client->request('GET', '/admin/configuration/messages/update/'.$message->getId())->html();

        self::assertStringContainsString('message-preview-html', $html);
        self::assertStringNotContainsString('message-send-test', $html);
    }

    /**
     * The answer of a send is shown in the page as it is: it is text, whatever the
     * recipient typed, and a refusal is one the page can tell from a success.
     */
    public function testTheAnswerOfASendIsTextAndARefusalIsNotASuccess(): void
    {
        $this->loginAs($this->factory->admin());
        $this->client->request('GET', '/admin/configuration/mailingSystem');
        $this->givenAStoreEmail();
        $message = MessageQuery::create()->findOne();
        self::assertNotNull($message);

        $this->client->request('POST', '/admin/message/send/'.$message->getId(), ['recipient_email' => '<b>admin@@example</b>', '_token' => $this->token()]);

        $response = $this->client->getResponse();
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertGreaterThanOrEqual(400, $response->getStatusCode());

        $this->client->request('POST', '/admin/message/send/'.$message->getId(), ['recipient_email' => 'someone@example.com', '_token' => $this->token()]);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
    }

    /**
     * A message template is written by whoever may edit the messages and previewed in the
     * session of whoever may read them: the preview shows itself, with its styles and
     * images, and runs nothing on the origin of the back office.
     */
    public function testThePreviewOfAMessageRunsNoScript(): void
    {
        $this->loginAs($this->factory->admin());
        $message = (new Message())
            ->setName('preview_under_test')
            ->setLocale('en_US')
            ->setTitle('Preview under test')
            ->setSubject('Preview under test')
            ->setHtmlMessage('<p style="color:red">Hello</p><script>alert(1)</script>')
            ->setTextMessage('Hello');
        $message->save($this->getPropelConnection());

        $this->client->request('GET', '/admin/message/preview/'.$message->getId());

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $policy = (string) $response->headers->get('Content-Security-Policy');
        self::assertStringContainsString("default-src 'none'", $policy);
        self::assertStringNotContainsString('script-src', $policy);
        self::assertStringContainsString('sandbox', $policy);

        $this->client->request('GET', '/admin/message/preview/text/'.$message->getId());

        self::assertStringStartsWith('text/plain', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * A shop without an address to send from is told so.
     */
    public function testATestMessageOfAShopWithoutAnAddressSaysSo(): void
    {
        $this->loginAs($this->factory->admin());
        $this->client->request('GET', '/admin/configuration/mailingSystem');
        $config = ConfigQuery::create()->findOneByName('store_email') ?? (new Config())->setName('store_email');
        $config->setValue('')->save($this->getPropelConnection());
        ConfigQuery::resetCache();
        $message = MessageQuery::create()->findOne();
        self::assertNotNull($message);

        $this->client->request('POST', '/admin/message/send/'.$message->getId(), ['recipient_email' => 'someone@example.com', '_token' => $this->token()]);

        self::assertStringNotContainsString('RFC', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('store email', (string) $this->client->getResponse()->getContent());
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', self::URL, ['email' => 'someone@example.com', '_token' => $this->token()]);

        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($answer['success']);
        self::assertStringContainsString('store email', $answer['message']);
    }

    /**
     * Sending a test message writes to whoever is named: a page of another site never
     * makes the shop send one through the session of an administrator.
     */
    public function testATestMessageIsNeverSentWithoutTheToken(): void
    {
        $this->loginAs($this->factory->admin());
        $this->client->request('GET', '/admin/configuration/mailingSystem');
        $this->givenAStoreEmail();
        $message = MessageQuery::create()->findOne();
        self::assertNotNull($message);

        $this->client->request('POST', '/admin/message/send/'.$message->getId(), ['recipient_email' => 'someone@example.com']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('successfully sent', (string) $this->client->getResponse()->getContent());
    }

    private function token(): string
    {
        // The home page, which every administrator may open, renders the token of the session.
        $html = (string) $this->client->request('GET', '/admin')->html();
        self::assertSame(1, preg_match('/<meta name="bo-token" content="([^"]+)"/', $html, $matches));

        return $matches[1];
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

        // The count of test mails survives the test transaction.
        $limiter = $this->getService('limiter.admin_test_mail');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);
        $limiter->create((string) $admin->getId())->reset();
    }
}
