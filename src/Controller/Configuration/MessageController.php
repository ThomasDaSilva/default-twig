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

use BackOfficeDefaultTwigBundle\Form\Configuration\MessageType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Configuration\EmailTemplateFileLister;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Message\MessageCreateEvent;
use Thelia\Core\Event\Message\MessageDeleteEvent;
use Thelia\Core\Event\Message\MessageUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Twig\Environment;

#[Route('/admin/configuration/messages', name: 'admin.configuration.messages.')]
final class MessageController
{
    private const RESOURCE = AdminResources::MESSAGE;
    private const LIST_ROUTE = 'admin.configuration.messages.default';
    private const EDIT_ROUTE = 'admin.configuration.messages.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/message/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/message/edit.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly EmailTemplateFileLister $fileLister,
        private readonly EditLocaleResolver $editLocale,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $locale = $request->getLocale();
        $sort = ListSort::fromRequest($request, ['id', 'name', 'title', 'secured'], 'name');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = MessageQuery::create();
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            'secured' => $query->orderBySecured($criteria),
            'title' => $query->useMessageI18nQuery(null, Criteria::LEFT_JOIN)->filterByLocale($locale)->orderByTitle($criteria)->endUse(),
            default => $query->orderByName($criteria),
        };
        $rows = [];
        foreach ($query->find() as $message) {
            \assert($message instanceof Message);
            $message->setLocale($locale);
            $rows[] = $this->messageToRow($message);
        }

        $createForm = $this->formFactory->createNamed('thelia_message_create', MessageType::class, [
            'locale' => $locale,
        ], ['include_secured' => false]);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'create_form' => $createForm->createView(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_message_create', MessageType::class, [
            'locale' => $request->getLocale(),
        ], ['include_secured' => false]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::MESSAGE_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Message creation',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
        );
    }

    #[Route('/update/{message_id}', name: 'update', methods: ['GET'], requirements: ['message_id' => '\d+'])]
    public function updateView(int $message_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $message = MessageQuery::create()->findPk($message_id);
        if ($message === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $message->setLocale($locale);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'message' => $message,
            'form' => $this->buildUpdateForm($message, $locale)->createView(),
            'edit_language_id' => (int) $editLang->getId(),
            'preview_html_url' => $this->urls->generate('admin.email.preview_html', ['messageId' => $message_id, 'edit_language_id' => $editLang->getId()]),
            'preview_text_url' => $this->urls->generate('admin.email.preview_text', ['messageId' => $message_id, 'edit_language_id' => $editLang->getId()]),
            'send_test_url' => $this->urls->generate('admin.email.test_send', ['messageId' => $message_id, 'edit_language_id' => $editLang->getId()]),
            // A test writes to the address typed: offered to who may change the messages.
            'can_send_test' => $this->access->can(self::RESOURCE, AccessManager::UPDATE),
            'store_email' => (string) ConfigQuery::read('store_email', ''),
        ]));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function processUpdate(Request $request): Response
    {
        $form = $this->formFactory->createNamed(
            'thelia_message_update',
            MessageType::class,
            null,
            ['include_id' => true, 'include_body' => true] + $this->fileChoiceOptions(),
        );

        $messageId = (int) $request->request->get('message_id', 0);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::MESSAGE_UPDATE,
            eventFactory: $this->updateEvent(...),
            actionLabel: 'Message update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['message_id' => $messageId],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['message_id' => $messageId])),
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new MessageDeleteEvent((int) ($request->query->get('message_id') ?? $request->request->get('message_id', 0))),
            eventName: TheliaEvents::MESSAGE_DELETE,
            actionLabel: 'Message deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    private function createEvent(FormInterface $validated): MessageCreateEvent
    {
        $data = $validated->getData() ?? [];
        $event = new MessageCreateEvent();
        $event->setMessageName((string) ($data['message_name'] ?? ''))
            ->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setSecured(false); // system (secured) messages are never created from the admin UI

        return $event;
    }

    private function updateEvent(FormInterface $validated): MessageUpdateEvent
    {
        $data = $validated->getData() ?? [];
        $event = new MessageUpdateEvent((int) ($data['id'] ?? 0));
        $event->setMessageName((string) ($data['message_name'] ?? ''))
            ->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setSecured((bool) ($data['secured'] ?? false))
            ->setSubject((string) ($data['subject'] ?? ''))
            ->setHtmlMessage((string) ($data['html_message'] ?? ''))
            ->setHtmlLayoutFileName((string) ($data['html_layout_file_name'] ?? ''))
            ->setHtmlTemplateFileName((string) ($data['html_template_file_name'] ?? ''))
            ->setTextMessage((string) ($data['text_message'] ?? ''))
            ->setTextLayoutFileName((string) ($data['text_layout_file_name'] ?? ''))
            ->setTextTemplateFileName((string) ($data['text_template_file_name'] ?? ''));

        return $event;
    }

    private function buildUpdateForm(Message $message, string $locale): FormInterface
    {
        return $this->formFactory->createNamed(
            'thelia_message_update',
            MessageType::class,
            [
                'id' => $message->getId(),
                'locale' => $locale,
                'message_name' => $message->getName(),
                'title' => $message->getTitle(),
                'secured' => (bool) $message->getSecured(),
                'subject' => $message->getSubject(),
                'html_message' => $message->getHtmlMessage(),
                'html_layout_file_name' => $message->getHtmlLayoutFileName(),
                'html_template_file_name' => $message->getHtmlTemplateFileName(),
                'text_message' => $message->getTextMessage(),
                'text_layout_file_name' => $message->getTextLayoutFileName(),
                'text_template_file_name' => $message->getTextTemplateFileName(),
            ],
            ['include_id' => true, 'include_body' => true] + $this->fileChoiceOptions(),
        );
    }

    /**
     * @return array{layout_choices: list<string>, html_template_choices: list<string>, text_template_choices: list<string>}
     */
    private function fileChoiceOptions(): array
    {
        $files = $this->fileLister->listForActiveTheme();

        return [
            'layout_choices' => $files['layouts'],
            'html_template_choices' => $files['html_templates'],
            'text_template_choices' => $files['text_templates'],
        ];
    }

    /** @return array<string, mixed> */
    private function messageToRow(Message $message): array
    {
        $id = (int) $message->getId();
        $actions = $message->getSecured() ? [] : [
            new RowAction(kind: 'edit', label: $this->translator->trans('Edit'), href: $this->urls->generate(self::EDIT_ROUTE, ['message_id' => $id]), grantedAttribute: AccessManager::UPDATE, grantedSubject: self::RESOURCE),
            new RowAction(kind: 'delete', label: $this->translator->trans('Delete'), modalTarget: '#message-delete-modal', grantedAttribute: AccessManager::DELETE, grantedSubject: self::RESOURCE, dataAttributes: ['message-id' => $id, 'message-label' => (string) $message->getName()]),
        ];

        return [
            'id' => $id,
            'name' => (string) $message->getName(),
            'title' => (string) $message->getTitle(),
            'secured' => (bool) $message->getSecured(),
            'secured_label' => $message->getSecured() ? $this->translator->trans('System') : $this->translator->trans('User'),
            '_actions' => $actions,
        ];
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
