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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFailureMessage;
use BackOfficeDefaultTwigBundle\Service\Admin\CurrentAdministrator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\HttpFoundation\Session\Session as TheliaSession;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Mailer\Exception\EmailNotSentException;
use Thelia\Mailer\MailerFactory;
use Thelia\Messenger\JobFailureMessage;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\MessageQuery;
use Thelia\Tools\TokenProvider;

/**
 * Renders the HTML/text preview for a mailing template and sends sample emails.
 *
 * Routes live under /admin/message/* to mirror the legacy admin.email.* names
 * exposed in Smarty (preview-button, send-test-mail-form) so module hooks and
 * legacy bookmarks keep resolving without modification.
 */
final class MessagePreviewController
{
    private const RESOURCE = AdminResources::MESSAGE;

    /**
     * What a preview may do in the browser: show itself, styled, with its images, and
     * nothing else. A message template is edited by whoever may edit the messages, and
     * previewed in the session of whoever may read them: a script it carries would run
     * in that session, on the origin of the back office.
     */
    private const PREVIEW_POLICY = "default-src 'none'; style-src 'unsafe-inline'; img-src * data:; sandbox";

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly TranslatorInterface $translator,
        private readonly ParserResolver $parserResolver,
        private readonly TemplateHelperInterface $templateHelper,
        private readonly MailerFactory $mailer,
        private readonly TokenProvider $tokens,
        private readonly CurrentAdministrator $administrator,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.admin_test_mail')]
        private readonly RateLimiterFactoryInterface $testMailLimiter,
    ) {
    }

    #[Route(path: '/admin/message/preview/{messageId}', name: 'admin.email.preview_html', methods: ['GET'], requirements: ['messageId' => '\d+'])]
    public function previewHtml(Request $request, int $messageId): Response
    {
        return $this->renderPreview($request, $messageId, true);
    }

    #[Route(path: '/admin/message/preview/text/{messageId}', name: 'admin.email.preview_text', methods: ['GET'], requirements: ['messageId' => '\d+'])]
    public function previewText(Request $request, int $messageId): Response
    {
        return $this->renderPreview($request, $messageId, false);
    }

    #[Route(path: '/admin/message/send/{messageId}', name: 'admin.email.test_send', methods: ['POST'], requirements: ['messageId' => '\d+'])]
    public function sendSample(Request $request, int $messageId): Response
    {
        // It writes to whatever address is typed: the right to change the messages, never
        // on the word of a page of another site, and ten in ten minutes at most.
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (TokenAuthenticationException) {
            return $this->plain($this->translator->trans('Invalid security token, please try again.'), Response::HTTP_FORBIDDEN);
        }

        $message = MessageQuery::create()->findPk($messageId);
        if ($message === null) {
            return $this->plain($this->translator->trans('Message not found.'), Response::HTTP_NOT_FOUND);
        }

        $recipient = trim((string) $request->request->get('recipient_email', ''));
        if ($recipient === '') {
            return $this->plain($this->translator->trans('Recipient email is required.'), Response::HTTP_BAD_REQUEST);
        }

        if (!$this->testMailLimiter->create((string) $this->administrator->id())->consume()->isAccepted()) {
            return $this->plain($this->translator->trans('Too many test mails in a short time: wait a few minutes before the next one.'), Response::HTTP_TOO_MANY_REQUESTS);
        }

        // What the form sends besides the variables of the message.
        $parameters = $request->request->all();
        unset($parameters['recipient_email'], $parameters['_token'], $parameters['edit_language_id']);

        try {
            $this->mailer->sendTestMessage($message->getName(), $recipient, $parameters, $this->resolveLocale($request));

            return $this->plain($this->translator->trans('The message has been successfully sent to %recipient.', ['%recipient' => $recipient]));
        } catch (EmailNotSentException $notSent) {
            if ($notSent->isStoreEmailMissing()) {
                return $this->plain($this->translator->trans('You have to configure your store email first !'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->notSent($notSent);
        } catch (\Throwable $exception) {
            return $this->notSent($exception);
        }
    }

    private function notSent(\Throwable $exception): Response
    {
        $this->logger->error(\sprintf('A test message could not be sent: %s', JobFailureMessage::forLog($exception)));

        return $this->plain($this->translator->trans('Something goes wrong, the message was not sent to recipient. Error is : %err', ['%err' => AdminFailureMessage::of($exception, $this->translator)]), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The answer of a send, shown as it is in the page: never as HTML.
     */
    private function plain(string $text, int $status = Response::HTTP_OK): Response
    {
        return new Response($text, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function renderPreview(Request $request, int $messageId, bool $asHtml): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $message = MessageQuery::create()->findPk($messageId);
        if ($message === null) {
            return $this->plain($this->translator->trans('Message not found.'), Response::HTTP_NOT_FOUND);
        }

        $mailTemplate = $this->templateHelper->getActiveMailTemplate();
        $locale = $this->resolveLocale($request);

        // The Twig parser reads its "locale" global and the translator locale from the admin
        // language stored in session (LangService::getLang()), not from the message's edition
        // locale. Temporarily switch it so the preview renders in the expected language, the
        // same way MailerFactory::createEmailMessage() swaps the session language for real sends.
        $session = $request->hasSession() ? $request->getSession() : null;
        $previousAdminLang = null;
        $editionLang = LangQuery::create()->findOneByLocale($locale);

        if ($session instanceof TheliaSession && $editionLang instanceof Lang) {
            $previousAdminLang = $session->getAdminLang();
            $session->setAdminLang($editionLang);
        }

        try {
            // The parser MailerFactory would send the message with: the one that claims the
            // template file of the message, the default parser for a body stored in the
            // database, which no file lets a parser claim.
            $templateFileName = (string) ($message->getHtmlTemplateFileName() ?: $message->getTextTemplateFileName());
            $parser = '' === $templateFileName
                ? $this->parserResolver->getDefaultParser()
                : $this->parserResolver->getParser($mailTemplate->getAbsolutePath(), pathinfo($templateFileName, \PATHINFO_FILENAME));
            $parser->setTemplateDefinition($mailTemplate, true);

            foreach ($request->query->all() as $key => $value) {
                $parser->assign($key, $value);
            }

            $message->setLocale($locale);
            $content = $asHtml ? $message->getHtmlMessageBody($parser) : $message->getTextMessageBody($parser);
        } catch (\Throwable $exception) {
            return $this->plain($this->translator->trans("You probably didn't inject the missing variable to preview the message. Error is : %err", ['%err' => AdminFailureMessage::of($exception, $this->translator)]), Response::HTTP_UNPROCESSABLE_ENTITY);
        } finally {
            if ($session instanceof TheliaSession && $previousAdminLang instanceof Lang) {
                $session->setAdminLang($previousAdminLang);
            }
        }

        if (!$asHtml) {
            return $this->plain($content);
        }

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => self::PREVIEW_POLICY,
        ]);
    }

    private function resolveLocale(Request $request): string
    {
        $editLanguageId = $request->query->get('edit_language_id') ?? $request->request->get('edit_language_id');
        if ($editLanguageId !== null && '' !== (string) $editLanguageId) {
            $lang = LangQuery::create()->findPk((int) $editLanguageId);
            if ($lang !== null) {
                return $lang->getLocale();
            }
        }

        return $this->defaultLocale();
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
