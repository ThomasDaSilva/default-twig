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

namespace BackOfficeDefaultTwigBundle\Controller\Module;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFailureMessage;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Module\ModuleHookListPresenter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Hook\ModuleHookCreateEvent;
use Thelia\Core\Event\Hook\ModuleHookDeleteEvent;
use Thelia\Core\Event\Hook\ModuleHookToggleActivationEvent;
use Thelia\Core\Event\Hook\ModuleHookUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Messenger\JobFailureMessage;
use Thelia\Model\HookQuery;
use Thelia\Model\IgnoredModuleHookQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\ModuleHookQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

final class ModuleHookController
{
    private const RESOURCE = AdminResources::MODULE_HOOK;
    private const LIST_ROUTE = 'admin.module-hook';
    private const EDIT_ROUTE = 'admin.module-hook.update';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly EventDispatcherInterface $events,
        private readonly TranslatorInterface $translator,
        private readonly ModuleHookListPresenter $listPresenter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/module-hooks', name: 'admin.module-hook', methods: ['GET'])]
    public function list(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/module/hook-list.html.twig', $this->buildListContext()));
    }

    #[Route('/admin/module-hooks/create', name: 'admin.module-hook.create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        try {
            $event = new ModuleHookCreateEvent();
            $event->setModuleId((int) $request->request->get('module_id', 0));
            $event->setHookId((int) $request->request->get('hook_id', 0));
            $event->setClassname((string) $request->request->get('classname', ''));
            $event->setMethod((string) $request->request->get('method', ''));
            $event->setTemplates((string) $request->request->get('templates', ''));

            $this->events->dispatch($event, TheliaEvents::MODULE_HOOK_CREATE);
        } catch (\Throwable $exception) {
            $this->reportFailure($request, 'Module hook creation', $exception);
        }

        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
    }

    #[Route('/admin/module-hook/update/{module_hook_id}', name: 'admin.module-hook.update', methods: ['GET'], requirements: ['module_hook_id' => '\d+'])]
    public function updateView(int $module_hook_id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $moduleHook = ModuleHookQuery::create()->findPk($module_hook_id);
        if ($moduleHook === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/module/hook-edit.html.twig', [
            'module_hook' => $moduleHook,
            'available_modules' => $this->moduleChoices(),
            'available_hooks' => $this->hookChoices(),
            'classnames_url_template' => $this->urls->generate('admin.module-hook.get-module-hook-classnames', ['moduleId' => 0]),
            'methods_url_template' => $this->urls->generate('admin.module-hook.get-module-hook-methods', ['moduleId' => 0, 'className' => '__CLASS__']),
        ]));
    }

    #[Route('/admin/module-hook/save/{module_hook_id}', name: 'admin.module-hook.save', methods: ['POST'], requirements: ['module_hook_id' => '\d+'])]
    public function save(int $module_hook_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        try {
            $moduleHook = ModuleHookQuery::create()->findPk($module_hook_id);
            $event = new ModuleHookUpdateEvent($moduleHook);
            $event->setModuleHookId($module_hook_id);
            $event->setModuleId((int) $request->request->get('module_id', 0));
            $event->setHookId((int) $request->request->get('hook_id', 0));
            $event->setClassname((string) $request->request->get('classname', ''));
            $event->setMethod((string) $request->request->get('method', ''));
            $event->setTemplates((string) $request->request->get('templates', ''));
            $event->setActive((bool) $request->request->get('active', false));

            $this->events->dispatch($event, TheliaEvents::MODULE_HOOK_UPDATE);
        } catch (\Throwable $exception) {
            $this->reportFailure($request, 'Module hook modification', $exception);
        }

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['module_hook_id' => $module_hook_id]));
    }

    #[Route('/admin/module-hooks/delete', name: 'admin.module-hook.delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $moduleHookId = (int) ($request->query->get('module_hook_id') ?? $request->request->get('module_hook_id', 0));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new ModuleHookDeleteEvent($moduleHookId),
            eventName: TheliaEvents::MODULE_HOOK_DELETE,
            actionLabel: 'Module hook deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/admin/module-hooks/toggle-activation/{module_hook_id}', name: 'admin.module-hook.toggle-activation', methods: ['POST'], requirements: ['module_hook_id' => '\d+'])]
    public function toggleActivation(int $module_hook_id, Request $request): Response
    {
        $moduleHook = ModuleHookQuery::create()->findPk($module_hook_id);
        if ($moduleHook === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new ModuleHookToggleActivationEvent($moduleHook),
            eventName: TheliaEvents::MODULE_HOOK_TOGGLE_ACTIVATION,
            actionLabel: 'Module hook activation toggled',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/admin/module-hooks/update-position', name: 'admin.module-hook.update-position', methods: ['POST'])]
    public function updatePosition(Request $request): Response
    {
        $event = new UpdatePositionEvent(
            (int) ($request->query->get('module_hook_id') ?? $request->request->get('module_hook_id', 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::MODULE_HOOK_UPDATE_POSITION,
            actionLabel: 'Module hook reorder',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/admin/module-hooks/get-module-hook-classnames/{moduleId}', name: 'admin.module-hook.get-module-hook-classnames', methods: ['GET'], requirements: ['moduleId' => '\d+'])]
    public function getClassnames(int $moduleId): JsonResponse
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return new JsonResponse([], Response::HTTP_FORBIDDEN);
        }

        // Hook classes are discovered when the module is activated and stored in
        // module_hook (registered) / ignored_module_hook (detected but disabled),
        // so we read them from there rather than introspecting loaded classes.
        $classnames = [];
        foreach (ModuleHookQuery::create()->filterByModuleId($moduleId)->groupByClassname()->find() as $moduleHook) {
            $classname = (string) $moduleHook->getClassname();
            if ($classname !== '' && !\in_array($classname, $classnames, true)) {
                $classnames[] = $classname;
            }
        }
        foreach (IgnoredModuleHookQuery::create()->filterByModuleId($moduleId)->groupByClassname()->find() as $ignored) {
            $classname = (string) $ignored->getClassname();
            if ($classname !== '' && !\in_array($classname, $classnames, true)) {
                $classnames[] = $classname;
            }
        }

        sort($classnames);

        return new JsonResponse(['classnames' => $classnames]);
    }

    #[Route('/admin/module-hooks/get-module-hook-methods/{moduleId}/{className}', name: 'admin.module-hook.get-module-hook-methods', methods: ['GET'], requirements: ['moduleId' => '\d+'])]
    public function getMethods(int $moduleId, string $className): JsonResponse
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return new JsonResponse([], Response::HTTP_FORBIDDEN);
        }

        // Same rationale as getClassnames: read the registered/ignored hook
        // methods from the database, plus the generic template-injection method.
        $methods = [BaseHook::INJECT_TEMPLATE_METHOD_NAME];
        foreach (ModuleHookQuery::create()->filterByModuleId($moduleId)->filterByClassname($className)->find() as $moduleHook) {
            $method = (string) $moduleHook->getMethod();
            if ($method !== '' && !\in_array($method, $methods, true)) {
                $methods[] = $method;
            }
        }
        foreach (IgnoredModuleHookQuery::create()->filterByModuleId($moduleId)->filterByClassname($className)->find() as $ignored) {
            $method = (string) $ignored->getMethod();
            if ($method !== '' && !\in_array($method, $methods, true)) {
                $methods[] = $method;
            }
        }

        sort($methods);

        return new JsonResponse(['methods' => $methods]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildListContext(): array
    {
        return [
            'hook_groups' => $this->listPresenter->build($this->defaultLocale()),
            'available_modules' => $this->moduleChoices(),
            'available_hooks' => $this->hookChoices(),
            'create_url' => $this->urls->generate('admin.module-hook.create'),
            'create_token' => $this->tokens->assignToken(),
            'delete_url' => $this->urls->generate('admin.module-hook.delete'),
            'classnames_url_template' => $this->urls->generate('admin.module-hook.get-module-hook-classnames', ['moduleId' => 0]),
            'methods_url_template' => $this->urls->generate('admin.module-hook.get-module-hook-methods', ['moduleId' => 0, 'className' => '__CLASS__']),
            'update_position_url' => $this->urls->generate('admin.module-hook.update-position'),
            'update_position_token' => $this->tokens->assignToken(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function moduleChoices(): array
    {
        $locale = $this->defaultLocale();
        $items = [];
        foreach (ModuleQuery::create()->orderByPosition()->find() as $module) {
            $module->setLocale($locale);
            $items[] = ['id' => (int) $module->getId(), 'code' => (string) $module->getCode()];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function hookChoices(): array
    {
        $locale = $this->defaultLocale();
        $items = [];
        $hooks = HookQuery::create()
            ->filterByType(\Thelia\Core\Template\TemplateDefinition::FRONT_OFFICE, \Propel\Runtime\ActiveQuery\Criteria::NOT_EQUAL)
            ->orderByCode()
            ->find();
        foreach ($hooks as $hook) {
            $hook->setLocale($locale);
            $items[] = ['id' => (int) $hook->getId(), 'code' => (string) $hook->getCode()];
        }

        return $items;
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }

    private function reportFailure(Request $request, string $actionLabel, \Throwable $exception): void
    {
        // By its class, code and place: the text of a database error quotes a customer.
        $this->logger->error(\sprintf('Error during %s: %s', $actionLabel, JobFailureMessage::forLog($exception)));

        $this->flashBag($request)?->add(
            'danger',
            $this->translator->trans('%action failed: %error', [
                '%action' => $this->translator->trans($actionLabel),
                '%error' => AdminFailureMessage::of($exception, $this->translator),
            ]),
        );
    }

    private function flashBag(Request $request): ?FlashBagInterface
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        return $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag() : null;
    }
}
