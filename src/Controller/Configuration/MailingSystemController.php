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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration;

use BackOfficeDefaultTwigBundle\Form\Configuration\MailingSystemType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Admin\CurrentAdministrator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\MailingSystem\MailingSystemEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Mailer\Exception\StoreEmailMissingException;
use Thelia\Mailer\MailerFactory;
use Thelia\Mailer\TransportCredentials;
use Thelia\Messenger\JobFailureMessage;
use Thelia\Model\ConfigQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin/configuration/mailingSystem', name: 'admin.mailingSystem.')]
final class MailingSystemController
{
    private const RESOURCE = AdminResources::MAILING_SYSTEM;
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/mailing-system/index.html.twig';
    private const REDIRECT_ROUTE = 'admin.mailingSystem.default';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly MailerFactory $mailer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly TokenProvider $tokens,
        private readonly CurrentAdministrator $administrator,
        #[Autowire(service: 'limiter.admin_test_mail')]
        private readonly RateLimiterFactoryInterface $testMailLimiter,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function index(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $form = $this->formFactory->createNamed('thelia_mailing_system_modification', MailingSystemType::class, $this->loadConfig(), []);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'form' => $form->createView(),
            'edit_disabled' => ConfigQuery::isSmtpInEnv(),
            'store_email' => (string) ConfigQuery::read('store_email', ''),
        ]));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(): Response
    {
        $form = $this->formFactory->createNamed('thelia_mailing_system_modification', MailingSystemType::class, null, [
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::MAILING_SYSTEM_UPDATE,
            eventFactory: $this->updateEvent(...),
            actionLabel: 'Mailing system update',
            successRoute: self::REDIRECT_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::REDIRECT_ROUTE)),
        );
    }

    #[Route('/test', name: 'test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        // It writes to whatever address is typed: never on the word of a page of another
        // site, and ten in ten minutes at most.
        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (TokenAuthenticationException) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('Invalid security token, please try again.')], Response::HTTP_FORBIDDEN);
        }

        if (!$this->testMailLimiter->create((string) $this->administrator->id())->consume()->isAccepted()) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('Too many test mails in a short time: wait a few minutes before the next one.')], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $recipient = trim((string) $request->request->get('email', ''));
        if ('' === $recipient) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('Recipient email is required.')], Response::HTTP_BAD_REQUEST);
        }

        $subject = $this->translator->trans('Email test from : %store%', ['%store%' => (string) ConfigQuery::read('store_name', 'Thelia')]);

        // Handed to the mail server now, even when the shop delivers its mails through a
        // queue: the point of a test is the server's answer. The name of the shop is text,
        // not HTML, in the body.
        try {
            $this->mailer->sendTestMail($recipient, $subject, '<p>'.htmlspecialchars($subject, \ENT_QUOTES | \ENT_SUBSTITUTE).'</p>');

            return new JsonResponse([
                'success' => true,
                'message' => $this->translator->trans('Your configuration seems to be ok. Checked out your mailbox : %email%', ['%email%' => $recipient]),
            ]);
        } catch (StoreEmailMissingException) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('You have to configure your store email first !'),
            ]);
        } catch (TransportExceptionInterface $refusal) {
            // What the mail server answered is what the administrator came for; the
            // credentials it may quote are hidden.
            return new JsonResponse([
                'success' => false,
                'message' => TransportCredentials::hide($refusal->getMessage()),
            ]);
        } catch (RfcComplianceException $mistypedAddress) {
            // It quotes the address typed, nothing else.
            return new JsonResponse([
                'success' => false,
                'message' => $mistypedAddress->getMessage(),
            ]);
        } catch (\Throwable $exception) {
            return $this->failed($exception);
        }
    }

    private function failed(\Throwable $exception): JsonResponse
    {
        $this->logger->error(\sprintf('The test mail could not be sent: %s', JobFailureMessage::forLog($exception)));

        return new JsonResponse([
            'success' => false,
            'message' => $this->translator->trans('The test mail could not be sent. The details are in the server log.'),
        ]);
    }

    /** @return array<string, mixed> */
    private function loadConfig(): array
    {
        return [
            'enabled' => (bool) ConfigQuery::isSmtpEnable(),
            'host' => (string) (ConfigQuery::getSmtpHost() ?? ''),
            'port' => (int) (ConfigQuery::getSmtpPort() ?? 25),
            'encryption' => (string) (ConfigQuery::getSmtpEncryption() ?? ''),
            'username' => (string) (ConfigQuery::getSmtpUsername() ?? ''),
            'password' => (string) (ConfigQuery::getSmtpPassword() ?? ''),
            'auth_mode' => (string) (ConfigQuery::getSmtpAuthMode() ?? ''),
            'timeout' => (int) (ConfigQuery::getSmtpTimeout() ?? 30),
            'source_ip' => (string) (ConfigQuery::getSmtpSourceIp() ?? ''),
        ];
    }

    private function updateEvent(FormInterface $validated): MailingSystemEvent
    {
        $data = $validated->getData() ?? [];
        $event = new MailingSystemEvent();
        $event->setEnabled((bool) ($data['enabled'] ?? false));
        $event->setHost((string) ($data['host'] ?? ''));
        $event->setPort((int) ($data['port'] ?? 25));
        $event->setEncryption((string) ($data['encryption'] ?? ''));
        $event->setUsername((string) ($data['username'] ?? ''));
        $event->setPassword((string) ($data['password'] ?? ''));
        $event->setAuthMode((string) ($data['auth_mode'] ?? ''));
        $event->setTimeout((int) ($data['timeout'] ?? 30));
        $event->setSourceIp((string) ($data['source_ip'] ?? ''));

        return $event;
    }
}
