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
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\Service\Module\ModuleDocumentationReader;
use BackOfficeDefaultTwigBundle\Service\Module\ModuleListPresenter;
use BackOfficeDefaultTwigBundle\Service\Module\ModuleMetadataReader;
use BackOfficeDefaultTwigBundle\Service\Module\ModuleSynchronizer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Module\ModuleDeleteEvent;
use Thelia\Core\Event\Module\ModuleEvent;
use Thelia\Core\Event\Module\ModuleInstallEvent;
use Thelia\Core\Event\Module\ModuleToggleActivationEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Module\Validator\ModuleValidator;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin', name: 'admin.')]
final class ModuleController
{
    private const RESOURCE = AdminResources::MODULE;
    private const LIST_ROUTE = 'admin.module';
    private const EDIT_ROUTE = 'admin.module.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/module/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/module/edit.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly EventDispatcherInterface $events,
        private readonly TranslatorInterface $translator,
        private readonly ModuleListPresenter $listPresenter,
        private readonly EditLocaleResolver $editLocale,
        private readonly ModuleMetadataReader $metadataReader,
        private readonly ModuleDocumentationReader $documentationReader,
        private readonly ModuleSynchronizer $moduleSynchronizer,
    ) {
    }

    #[Route('/modules', name: 'module', methods: ['GET'])]
    public function list(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render(
            self::LIST_TEMPLATE,
            $this->listPresenter->buildListContext($this->defaultLocale()),
        ));
    }

    #[Route('/modules/refresh', name: 'module.refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (\Throwable) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $errors = $this->moduleSynchronizer->synchronize();
        if ($errors === null) {
            $this->flashSuccess($request, $this->translator->trans('Modules checked: the list is up to date.'));
        } else {
            $this->flashError($request, $errors);
        }

        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
    }

    #[Route('/module/update/{module_id}', name: 'module.update', methods: ['GET'], requirements: ['module_id' => '\d+'])]
    public function updateView(int $module_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $module = ModuleQuery::create()->findPk($module_id);
        if ($module === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $module->setLocale($locale);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'module' => $module,
            'locale' => $locale,
            'edit_language_id' => (int) $editLang->getId(),
        ]));
    }

    #[Route('/module/save', name: 'module.save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $moduleId = (int) $request->request->get('module_id', $request->request->get('id', 0));

        try {
            $this->tokens->checkToken((string) $request->request->get('_token'));
        } catch (\Throwable) {
            $this->flashError($request, $this->translator->trans('Invalid CSRF token.'));

            return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['module_id' => $moduleId]));
        }

        $module = ModuleQuery::create()->findPk($moduleId);
        if ($module === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $title = trim((string) $request->request->get('title', ''));
        if ($title === '') {
            $this->flashError($request, $this->translator->trans('The module title is required.'));

            return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['module_id' => $moduleId]));
        }

        try {
            $event = new ModuleEvent($module);
            $event->setId($moduleId);
            $event->setLocale((string) $request->request->get('locale', $this->defaultLocale()));
            $event->setTitle($title);
            $event->setChapo($request->request->get('chapo') !== null ? (string) $request->request->get('chapo') : null);
            $event->setDescription($request->request->get('description') !== null ? (string) $request->request->get('description') : null);
            $event->setPostscriptum($request->request->get('postscriptum') !== null ? (string) $request->request->get('postscriptum') : null);

            $this->events->dispatch($event, TheliaEvents::MODULE_UPDATE);
        } catch (\Throwable $exception) {
            $this->flashError($request, AdminFailureMessage::of($exception, $this->translator));

            return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['module_id' => $moduleId]));
        }

        if ($request->request->get('save_mode') === 'close') {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['module_id' => $moduleId]));
    }

    #[Route('/module/toggle-activation/{module_id}', name: 'module.toggle-activation', methods: ['POST'], requirements: ['module_id' => '\d+'])]
    public function toggleActivation(int $module_id, Request $request): Response
    {
        if (ModuleQuery::create()->findPk($module_id) === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new ModuleToggleActivationEvent($module_id),
            eventName: TheliaEvents::MODULE_TOGGLE_ACTIVATION,
            actionLabel: 'Module activation toggled',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/module/delete', name: 'module.delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $moduleId = (int) ($request->request->get('module_id') ?? 0);
        if (ModuleQuery::create()->findPk($moduleId) === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $event = new ModuleDeleteEvent($moduleId);
        $event->setDeleteData($request->request->getBoolean('delete-module-data'));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::MODULE_DELETE,
            actionLabel: 'Module deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/module/update-position', name: 'module.update-position', methods: ['POST'])]
    public function updatePosition(Request $request): Response
    {
        $event = new UpdatePositionEvent(
            (int) $request->request->get('module_id', 0),
            (int) $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE),
            (int) $request->request->get('position', 0),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::MODULE_UPDATE_POSITION,
            actionLabel: 'Module reorder',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/module/install', name: 'module.install', methods: ['POST'])]
    public function install(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        if (!(bool) (int) ConfigQuery::read('allow_module_zip_install', 0)) {
            $this->flash($request, 'danger', $this->translator->trans('Module installation from a zip file is disabled.'));

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (\Throwable) {
            $this->flash($request, 'danger', $this->translator->trans('Invalid CSRF token.'));

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $upload = $request->files->get('module');
        if (!$upload instanceof UploadedFile) {
            $this->flash($request, 'danger', $this->translator->trans('Please upload a valid Zip file.'));

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        try {
            $extractPath = $this->extractModule($upload);
            $children = array_values(array_filter(
                array_diff(scandir($extractPath) ?: [], ['.', '..']),
                static fn (string $name) => is_dir($extractPath.\DIRECTORY_SEPARATOR.$name),
            ));

            if (\count($children) !== 1) {
                throw new \RuntimeException($this->translator->trans('Your zip must contain a single root directory.'));
            }

            $modulePath = $extractPath.\DIRECTORY_SEPARATOR.$children[0];

            $validator = new ModuleValidator($modulePath);
            $validator->validate();

            $event = new ModuleInstallEvent();
            $event->setModulePath($modulePath)->setModuleDefinition($validator->getModuleDefinition());

            $this->events->dispatch($event, TheliaEvents::MODULE_INSTALL);

            $this->flash(
                $request,
                'success',
                $this->translator->trans('Module %code installed successfully.', ['%code' => $validator->getModuleDefinition()->getCode()]),
            );
        } catch (\Throwable $exception) {
            $this->flash($request, 'danger', $this->translator->trans('Module installation failed: %message', ['%message' => AdminFailureMessage::of($exception, $this->translator)]));
        }

        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
    }

    private function extractModule(UploadedFile $file): string
    {
        $zip = new \ZipArchive();

        if ($zip->open((string) $file->getRealPath()) !== true) {
            throw new \RuntimeException($this->translator->trans('Unable to open the uploaded archive.'));
        }

        $tempName = tempnam(sys_get_temp_dir(), 'thelia_module_');
        if ($tempName === false) {
            $zip->close();

            throw new \RuntimeException($this->translator->trans('Unable to create a temporary directory.'));
        }
        unlink($tempName);
        mkdir($tempName);

        if ($zip->extractTo($tempName) === false) {
            $zip->close();

            throw new \RuntimeException($this->translator->trans('Unable to extract the uploaded archive.'));
        }

        $zip->close();

        return $tempName;
    }

    private function flash(Request $request, string $type, string $message): void
    {
        try {
            $session = $request->getSession();
            if (method_exists($session, 'getFlashBag')) {
                $session->getFlashBag()->add($type, $message);
            }
        } catch (\Throwable) {
            // silent - CLI session has no FlashBag
        }
    }

    #[Route('/module/information/{module_id}', name: 'module.information', methods: ['GET'], requirements: ['module_id' => '\d+'])]
    public function information(int $module_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $module = ModuleQuery::create()->findPk($module_id);
        if ($module === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $module->setLocale($this->editLocale->resolveFromRequest($request)->getLocale() ?? 'en_US');

        return new Response($this->twig->render('@BackOfficeDefaultTwig/module/_information_body.html.twig', [
            'module' => $module,
            'metadata' => $this->metadataReader->read($module),
        ]));
    }

    #[Route('/module/documentation/{module_id}', name: 'module.documentation', methods: ['GET'], requirements: ['module_id' => '\d+'])]
    public function documentation(int $module_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $module = ModuleQuery::create()->findPk($module_id);
        if ($module === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $module->setLocale($this->editLocale->resolveFromRequest($request)->getLocale() ?? 'en_US');

        return new Response($this->twig->render('@BackOfficeDefaultTwig/module/_documentation_body.html.twig', [
            'module' => $module,
            'documentation' => $this->documentationReader->read($module),
        ]));
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }

    private function flashError(Request $request, string $message): void
    {
        $this->flash($request, 'danger', $message);
    }

    private function flashSuccess(Request $request, string $message): void
    {
        $this->flash($request, 'success', $message);
    }
}
