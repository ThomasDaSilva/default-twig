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

use BackOfficeDefaultTwigBundle\Form\Configuration\HookType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFailureMessage;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Hook\HookCreateAllEvent;
use Thelia\Core\Event\Hook\HookCreateEvent;
use Thelia\Core\Event\Hook\HookDeactivationEvent;
use Thelia\Core\Event\Hook\HookDeleteEvent;
use Thelia\Core\Event\Hook\HookToggleActivationEvent;
use Thelia\Core\Event\Hook\HookToggleNativeEvent;
use Thelia\Core\Event\Hook\HookUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Hook\HookHelper;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Model\Hook;
use Thelia\Model\HookQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

final class HookController
{
    private const RESOURCE = AdminResources::HOOK;
    private const LIST_ROUTE = 'admin.hook';
    private const EDIT_ROUTE = 'admin.hook.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/hook/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/hook/edit.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly EventDispatcherInterface $events,
        #[Autowire(service: 'thelia.hookHelper')]
        private readonly HookHelper $hookHelper,
        private readonly EditLocaleResolver $editLocale,
        private readonly TokenProvider $tokens,
    ) {
    }

    #[Route('/admin/hooks', name: 'admin.hook', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $type = (int) ($request->query->get('type') ?? TemplateDefinition::FRONT_OFFICE);
        $locale = $request->getLocale();
        $sort = ListSort::fromRequest($request, ['id', 'code', 'title', 'native', 'active'], 'id');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = HookQuery::create()->filterByType($type)->joinWithI18n($locale);
        match ($sort->field) {
            'code' => $query->orderByCode($criteria),
            'native' => $query->orderByNative($criteria),
            'active' => $query->orderByActivate($criteria),
            'title' => $query->useHookI18nQuery(null, Criteria::LEFT_JOIN)->filterByLocale($locale)->orderByTitle($criteria)->endUse(),
            default => $query->orderById($criteria),
        };

        $rows = [];
        foreach ($query->find() as $hook) {
            \assert($hook instanceof Hook);
            $hook->setLocale($locale);
            $rows[] = [
                'id' => (int) $hook->getId(),
                'code' => (string) $hook->getCode(),
                'title' => (string) $hook->getTitle(),
                'native' => (bool) $hook->getNative(),
                'active' => (bool) $hook->getActivate(),
                'toggle_native_url' => $this->urls->generate('admin.hook.toggle-native', ['hook_id' => (int) $hook->getId(), 'type' => $type]),
                'toggle_active_url' => $this->urls->generate('admin.hook.toggle-activation', ['hook_id' => (int) $hook->getId(), 'type' => $type]),
                '_actions' => [
                    new RowAction(
                        kind: 'edit',
                        label: $this->translator->trans('Edit'),
                        href: $this->urls->generate(self::EDIT_ROUTE, ['hook_id' => (int) $hook->getId()]),
                        grantedAttribute: AccessManager::UPDATE,
                        grantedSubject: self::RESOURCE,
                    ),
                    new RowAction(
                        kind: 'delete',
                        label: $this->translator->trans('Delete'),
                        modalTarget: '#hook-delete-modal',
                        grantedAttribute: AccessManager::DELETE,
                        grantedSubject: self::RESOURCE,
                        dataAttributes: ['hook-id' => (int) $hook->getId()],
                    ),
                ],
            ];
        }

        $createForm = $this->formFactory->createNamed('thelia_hook_create', HookType::class, [
            'locale' => $locale,
            'active' => true,
            'native' => '0',
            'type' => $type,
        ], []);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'create_form' => $createForm->createView(),
            'current_type' => $type,
            'type_options' => [
                TemplateDefinition::FRONT_OFFICE => $this->translator->trans('Front Office'),
                TemplateDefinition::BACK_OFFICE => $this->translator->trans('Back Office'),
                TemplateDefinition::PDF => $this->translator->trans('pdf'),
                TemplateDefinition::EMAIL => $this->translator->trans('email'),
            ],
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ]));
    }

    #[Route('/admin/hooks/create', name: 'admin.hook.create', methods: ['POST'])]
    public function create(): Response
    {
        $form = $this->formFactory->createNamed('thelia_hook_create', HookType::class, null, []);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::HOOK_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Hook creation',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
        );
    }

    #[Route('/admin/hook/update/{hook_id}', name: 'admin.hook.update', methods: ['GET'], requirements: ['hook_id' => '\d+'])]
    public function updateView(int $hook_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $hook = HookQuery::create()->findPk($hook_id);
        if ($hook === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }
        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $hook->setLocale($locale);

        $form = $this->formFactory->createNamed(
            'thelia_hook_update',
            HookType::class,
            [
                'id' => (int) $hook->getId(),
                'code' => (string) $hook->getCode(),
                'type' => (int) $hook->getType(),
                'title' => (string) $hook->getTitle(),
                'active' => (bool) $hook->getActivate(),
                'native' => $hook->getNative() ? '1' : '0',
                'locale' => $locale,
                'by_module' => (bool) $hook->getByModule(),
                'block' => (bool) $hook->getBlock(),
                'chapo' => (string) $hook->getChapo(),
                'description' => (string) $hook->getDescription(),
            ],
            ['include_id' => true],
        );

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'hook' => $hook,
            'hook_id' => $hook_id,
            'form' => $form->createView(),
            'edit_language_id' => (int) $editLang->getId(),
        ]));
    }

    #[Route('/admin/hook/save/{hook_id}', name: 'admin.hook.save', methods: ['POST'], requirements: ['hook_id' => '\d+'])]
    public function processUpdate(int $hook_id): Response
    {
        $form = $this->formFactory->createNamed(
            'thelia_hook_update',
            HookType::class,
            null,
            ['include_id' => true],
        );

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::HOOK_UPDATE,
            eventFactory: fn (FormInterface $validated) => $this->updateEvent($validated),
            actionLabel: 'Hook update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['hook_id' => $hook_id],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['hook_id' => $hook_id])),
        );
    }

    #[Route('/admin/hooks/delete', name: 'admin.hook.delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $hookId = (int) $request->request->get('hook_id', 0);

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new HookDeleteEvent($hookId),
            eventName: TheliaEvents::HOOK_DELETE,
            actionLabel: 'Hook deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/admin/hook/toggle-activation', name: 'admin.hook.toggle-activation', methods: ['POST'])]
    public function toggleActivation(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new HookToggleActivationEvent((int) $request->query->get('hook_id', 0)),
            eventName: TheliaEvents::HOOK_TOGGLE_ACTIVATION,
            actionLabel: 'Hook activation toggle',
            successRoute: self::LIST_ROUTE,
            successParameters: ['type' => (int) $request->query->get('type', TemplateDefinition::FRONT_OFFICE)],
        );
    }

    #[Route('/admin/hook/toggle-native', name: 'admin.hook.toggle-native', methods: ['POST'])]
    public function toggleNative(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new HookToggleNativeEvent((int) $request->query->get('hook_id', 0)),
            eventName: TheliaEvents::HOOK_TOGGLE_NATIVE,
            actionLabel: 'Hook native toggle',
            successRoute: self::LIST_ROUTE,
            successParameters: ['type' => (int) $request->query->get('type', TemplateDefinition::FRONT_OFFICE)],
        );
    }

    #[Route('/admin/hooks/discover', name: 'admin.hook.discover', methods: ['GET'])]
    public function discover(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        try {
            $templateType = (int) $request->query->get('template_type', TemplateDefinition::FRONT_OFFICE);
            $parsedHooks = $this->hookHelper->parseActiveTemplate($templateType);

            $existingHooks = HookQuery::create()
                ->filterByType($templateType)
                ->joinWithI18n($this->defaultLocale())
                ->find();

            $byCode = [];
            foreach ($existingHooks as $hook) {
                \assert($hook instanceof Hook);
                $byCode[$hook->getCode()] = [
                    'id' => (int) $hook->getId(),
                    'code' => (string) $hook->getCode(),
                    'native' => (bool) $hook->getNative(),
                    'activate' => (bool) $hook->getActivate(),
                    'title' => (string) $hook->getTitle(),
                ];
            }

            $newHooks = [];
            $present = [];
            foreach ($parsedHooks as $parsed) {
                if (\array_key_exists($parsed['code'], $byCode)) {
                    $present[] = $parsed['code'];
                } else {
                    $newHooks[] = $parsed;
                }
            }
            foreach ($present as $code) {
                unset($byCode[$code]);
            }

            return new JsonResponse([
                'success' => true,
                'new' => $newHooks,
                'missing' => array_values($byCode),
            ]);
        } catch (\Throwable $exception) {
            // A missing or unconfigured template parser is a recoverable configuration
            // issue, not a server failure: surface it as 422 so a crawler or the UI can
            // tell it apart from a genuine 500.
            return new JsonResponse(['success' => false, 'message' => AdminFailureMessage::of($exception, $this->translator)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/admin/hooks/discover/save', name: 'admin.hook.discover.save', methods: ['POST'])]
    public function discoverSave(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        $messages = [];
        $templateType = (int) $request->request->get('templateType', TemplateDefinition::FRONT_OFFICE);
        $locale = Lang::getDefaultLanguage()->getLocale();

        foreach ((array) $request->request->all('new') as $hookData) {
            $code = (string) ($hookData['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $event = new HookCreateAllEvent();
            $event
                ->setLocale($locale)
                ->setType($templateType)
                ->setCode($code)
                ->setNative(false)
                ->setActive(true)
                ->setTitle($hookData['title'] !== '' ? (string) $hookData['title'] : $code)
                ->setByModule((bool) ($hookData['module'] ?? false))
                ->setBlock((bool) ($hookData['block'] ?? false))
                ->setChapo('')
                ->setDescription('');

            $this->events->dispatch($event, TheliaEvents::HOOK_CREATE_ALL);
            if (!$event->hasHook()) {
                $messages[] = \sprintf($this->translator->trans('Failed to create new hook %s'), $code);
            }
        }

        foreach ((array) $request->request->all('missing') as $hookId) {
            $event = new HookDeactivationEvent((int) $hookId);
            $this->events->dispatch($event, TheliaEvents::HOOK_DEACTIVATION);
            if (!$event->hasHook()) {
                $messages[] = \sprintf($this->translator->trans('Failed to deactivate hook with id %s'), $hookId);
            }
        }

        if ($messages !== []) {
            return new JsonResponse(['success' => false, 'messages' => $messages], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['success' => true]);
    }

    private function createEvent(FormInterface $validated): HookCreateEvent
    {
        $data = $validated->getData() ?? [];
        $event = new HookCreateEvent();
        $event
            ->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setType((int) ($data['type'] ?? TemplateDefinition::FRONT_OFFICE))
            ->setCode((string) ($data['code'] ?? ''))
            ->setNative((bool) ($data['native'] ?? false))
            ->setActive((bool) ($data['active'] ?? false))
            ->setTitle((string) ($data['title'] ?? ''));

        return $event;
    }

    private function updateEvent(FormInterface $validated): HookUpdateEvent
    {
        $data = $validated->getData() ?? [];
        $event = new HookUpdateEvent((int) ($data['id'] ?? 0));
        $event
            ->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setType((int) ($data['type'] ?? TemplateDefinition::FRONT_OFFICE))
            ->setCode((string) ($data['code'] ?? ''))
            ->setNative((bool) ($data['native'] ?? false))
            ->setActive((bool) ($data['active'] ?? false))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setByModule((bool) ($data['by_module'] ?? false))
            ->setBlock((bool) ($data['block'] ?? false))
            ->setChapo((string) ($data['chapo'] ?? ''))
            ->setDescription((string) ($data['description'] ?? ''));

        return $event;
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
