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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\HttpFoundation\Session\Session as TheliaSession;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;
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

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly TranslatorInterface $translator,
        private readonly ParserResolver $parserResolver,
        private readonly TemplateHelperInterface $templateHelper,
        private readonly MailerFactory $mailer,
        private readonly TokenProvider $tokens,
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
        $response = $this->renderPreview($request, $messageId, false);
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');

        return $response;
    }

    #[Route(path: '/admin/message/send/{messageId}', name: 'admin.email.test_send', methods: ['POST'], requirements: ['messageId' => '\d+'])]
    public function sendSample(Request $request, int $messageId): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        // It writes to whoever is named: never on the word of a page of another site.
        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (TokenAuthenticationException) {
            return new Response($this->translator->trans('Invalid security token, please try again.'), Response::HTTP_FORBIDDEN);
        }

        $message = MessageQuery::create()->findPk($messageId);
        if ($message === null) {
            return new Response($this->translator->trans('Message not found.'), Response::HTTP_NOT_FOUND);
        }

        $recipient = trim((string) $request->request->get('recipient_email', ''));
        if ($recipient === '') {
            return new Response($this->translator->trans('Recipient email is required.'), Response::HTTP_BAD_REQUEST);
        }

        $parameters = $request->request->all();
        unset($parameters['recipient_email']);

        try {
            // Built and handed to the mail server now, queue or not: the point of a test is
            // the server's answer, which the administrator reads either way.
            $this->mailer->sendNow($this->mailer->createEmailMessage(
                $message->getName(),
                [(string) ConfigQuery::read('store_email', '') => (string) ConfigQuery::read('store_name', 'Thelia')],
                [$recipient => $recipient],
                $parameters,
                $this->resolveLocale($request),
            ));

            return new Response($this->translator->trans('The message has been successfully sent to %recipient.', ['%recipient' => $recipient]));
        } catch (\Throwable $exception) {
            return new Response($this->translator->trans('Something goes wrong, the message was not sent to recipient. Error is : %err', ['%err' => AdminFailureMessage::of($exception, $this->translator)]));
        }
    }

    private function renderPreview(Request $request, int $messageId, bool $asHtml): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $message = MessageQuery::create()->findPk($messageId);
        if ($message === null) {
            return new Response($this->translator->trans('Message not found.'), Response::HTTP_NOT_FOUND);
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
            $parser = $this->parserResolver->getParser($mailTemplate->getAbsolutePath(), null);
            $parser->setTemplateDefinition($mailTemplate, true);

            foreach ($request->query->all() as $key => $value) {
                $parser->assign($key, $value);
            }

            $message->setLocale($locale);
            $content = $asHtml ? $message->getHtmlMessageBody($parser) : $message->getTextMessageBody($parser);
        } catch (\Throwable $exception) {
            return new Response($this->translator->trans("You probably didn't inject the missing variable to preview the message. Error is : %err", ['%err' => AdminFailureMessage::of($exception, $this->translator)]));
        } finally {
            if ($session instanceof TheliaSession && $previousAdminLang instanceof Lang) {
                $session->setAdminLang($previousAdminLang);
            }
        }

        return new Response($content);
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
