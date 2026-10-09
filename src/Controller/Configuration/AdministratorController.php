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

use BackOfficeDefaultTwigBundle\Form\Administrator\AdministratorType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
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
use Thelia\Core\Event\Administrator\AdministratorEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Admin\TwoFactor\AdminTwoFactorManager;
use Thelia\Model\Admin;
use Thelia\Model\AdminQuery;
use Thelia\Model\AdminTwoFactorQuery;
use Thelia\Model\ProfileQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin/configuration/administrators', name: 'admin.configuration.administrators.')]
final class AdministratorController
{
    private const RESOURCE = AdminResources::ADMINISTRATOR;
    private const LIST_ROUTE = 'admin.configuration.administrators.view';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/administrator/list.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly SecurityContext $securityContext,
        private readonly AdminTwoFactorManager $twoFactorManager,
        private readonly TokenProvider $tokens,
    ) {
    }

    #[Route('', name: 'view', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, $this->buildListContext($request)));
    }

    #[Route('/view', name: 'view-profile', methods: ['GET'])]
    public function viewProfile(): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
    }

    #[Route('/add', name: 'add', methods: ['POST'])]
    public function add(): Response
    {
        $form = $this->formFactory->createNamed('thelia_administrator_create', AdministratorType::class, null, [
            'profile_choices' => $this->profileChoices(),
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::ADMINISTRATOR_CREATE,
            eventFactory: function (FormInterface $validated): AdministratorEvent {
                $profileId = $validated->get('profile')->getData() ?: null;
                $this->assertProfileAssignable($profileId);

                $event = new AdministratorEvent();
                $event
                    ->setLogin((string) $validated->get('login')->getData())
                    ->setFirstname((string) $validated->get('firstname')->getData())
                    ->setLastname((string) $validated->get('lastname')->getData())
                    ->setEmail((string) $validated->get('email')->getData())
                    ->setPassword((string) $validated->get('password')->getData())
                    ->setProfile($profileId)
                    ->setLocale((string) $validated->get('locale')->getData());

                return $event;
            },
            actionLabel: 'Administrator creation',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): Response => $this->renderListWithError(),
            describeForLog: fn (AdministratorEvent $event): array => $this->describe($event, 'created'),
        );
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        // Each row's edit form is registered as 'thelia_administrator_update_<id>' (see
        // createEditForm), so the posted fields are namespaced by id. This route carries no id, so
        // recover it from the posted form-name prefix and rebuild the form under the matching name —
        // otherwise handleRequest() never sees the submission and the update silently fails.
        $form = $this->formFactory->createNamed(
            $this->submittedUpdateFormName($request),
            AdministratorType::class,
            null,
            [
                'include_id' => true,
                'profile_choices' => $this->profileChoices(),
            ],
        );

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::ADMINISTRATOR_UPDATE,
            eventFactory: function (FormInterface $validated): AdministratorEvent {
                $administratorId = (int) $validated->get('id')->getData();
                $profileId = $validated->get('profile')->getData() ?: null;
                $this->assertProfileAssignable($profileId, AdminQuery::create()->findPk($administratorId));

                $event = new AdministratorEvent();
                $event
                    ->setId($administratorId)
                    ->setLogin((string) $validated->get('login')->getData())
                    ->setFirstname((string) $validated->get('firstname')->getData())
                    ->setLastname((string) $validated->get('lastname')->getData())
                    ->setEmail((string) $validated->get('email')->getData())
                    ->setPassword((string) ($validated->get('password')->getData() ?? ''))
                    ->setProfile($profileId)
                    ->setLocale((string) $validated->get('locale')->getData());

                return $event;
            },
            actionLabel: 'Administrator update',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): Response => $this->renderListWithError(),
            describeForLog: fn (AdministratorEvent $event): array => $this->describe($event, 'modified'),
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $administratorId = (int) ($request->query->get('administrator_id') ?? $request->request->get('administrator_id', 0));

        if ($administratorId === $this->currentAdminId()) {
            $this->flashError($request, $this->translator->trans('You cannot delete your own administrator account.'));

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $target = AdminQuery::create()->findPk($administratorId);

        if ($target instanceof Admin && $this->isAboveTheCeilingOfTheCurrentAdmin($target)) {
            $this->flashError($request, $this->translator->trans('Only a superadministrator can delete a superadministrator account.'));

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $event = new AdministratorEvent();
        $event->setId($administratorId);

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::ADMINISTRATOR_DELETE,
            actionLabel: 'Administrator deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/two-factor-reset', name: 'two-factor-reset', methods: ['POST'])]
    public function resetTwoFactor(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $current = $this->securityContext->getAdminUser();
        $target = AdminQuery::create()->findPk((int) $request->request->get('administrator_id', 0));

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (\Throwable) {
            $this->flashError($request, $this->translator->trans('Your session has expired. Please try again.'));

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        if (!$current instanceof Admin || !$target instanceof Admin || (int) $target->getId() === (int) $current->getId()) {
            $this->flashError($request, $this->translator->trans('You cannot reset the two-step verification of this account.'));

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        if ($this->isAboveTheCeilingOfTheCurrentAdmin($target)) {
            $this->flashError($request, $this->translator->trans('Only a superadministrator can edit a superadministrator account.'));

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $this->twoFactorManager->resetOnBehalfOf($target, $current);
        $this->flash($request, 'success', $this->translator->trans('The two-step verification of %login% is reset: this administrator signs in with their password alone until they enable it again.', ['%login%' => $target->getLogin()]));

        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function describe(AdministratorEvent $event, string $action): array
    {
        if (!$event->hasAdministrator()) {
            // A key per action: one built from the action would never be found in a catalogue.
            throw new \LogicException('created' === $action
                ? $this->translator->trans('No administrator was created.')
                : $this->translator->trans('No administrator was modified.'));
        }

        $admin = $event->getAdministrator();

        return [\sprintf('Administrator %s (ID %d) %s', $admin->getLogin(), $admin->getId(), $action), $admin->getId()];
    }

    private function renderListWithError(): Response
    {
        return new Response(
            $this->twig->render(self::LIST_TEMPLATE, $this->buildListContext()),
            Response::HTTP_BAD_REQUEST,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildListContext(?Request $request = null): array
    {
        $sort = $request !== null
            ? ListSort::fromRequest($request, ['id', 'login', 'firstname', 'lastname', 'email'], 'login')
            : new ListSort('login', 'asc');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = AdminQuery::create();
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            'firstname' => $query->orderByFirstname($criteria),
            'lastname' => $query->orderByLastname($criteria),
            'email' => $query->orderByEmail($criteria),
            default => $query->orderByLogin($criteria),
        };
        $admins = $query->find();
        $rows = [];
        $editForms = [];
        $defaultLocale = $this->resolveDefaultLocale();
        $profileChoices = $this->profileChoices();
        $currentAdminId = $this->currentAdminId();
        $protectedAdminIds = array_map(intval(...), AdminTwoFactorQuery::create()
            ->filterByEnabledAt(null, Criteria::ISNOTNULL)
            ->select(['AdminId'])
            ->find()
            ->getData());

        foreach ($admins as $admin) {
            $rows[] = $this->administratorToRow($admin, $currentAdminId, $defaultLocale, \in_array((int) $admin->getId(), $protectedAdminIds, true));
            $editForms[$admin->getId()] = $this->createEditForm($admin, $defaultLocale, $profileChoices)->createView();
        }

        $createForm = $this->formFactory->createNamed('thelia_administrator_create', AdministratorType::class, [
            'locale' => $defaultLocale,
        ], [
            'profile_choices' => $profileChoices,
        ]);

        $showEmailChangeNotice = $request !== null
            && $request->query->getBoolean('show_email_change_notice')
            && $currentAdminId !== null
            && isset($editForms[$currentAdminId]);

        return [
            'rows' => $rows,
            'edit_forms' => $editForms,
            'create_form' => $createForm->createView(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
            'show_email_change_notice' => $showEmailChangeNotice,
            'current_admin_id' => $currentAdminId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function administratorToRow(Admin $admin, ?int $currentAdminId, string $defaultLocale, bool $twoFactorEnabled): array
    {
        $id = $admin->getId();

        $actions = [
            new RowAction(
                kind: 'edit',
                label: $this->translator->trans('Edit this administrator'),
                modalTarget: '#administrator-edit-modal-'.$id,
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: self::RESOURCE,
                dataAttributes: ['administrator-id' => $id],
            ),
        ];

        if ($id === $currentAdminId) {
            $actions[] = new RowAction(
                kind: 'custom',
                label: $this->translator->trans('Account security'),
                href: $this->urls->generate('admin.account.two-factor.view'),
                inMenu: true,
            );
        }

        if ($twoFactorEnabled && $id !== $currentAdminId && !$this->isAboveTheCeilingOfTheCurrentAdmin($admin)) {
            $actions[] = new RowAction(
                kind: 'custom',
                label: $this->translator->trans('Reset the two-step verification'),
                modalTarget: '#administrator-two-factor-reset-modal',
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: self::RESOURCE,
                dataAttributes: ['administrator-id' => $id],
                inMenu: true,
            );
        }

        if ($id !== $currentAdminId && !$this->isAboveTheCeilingOfTheCurrentAdmin($admin)) {
            $actions[] = new RowAction(
                kind: 'delete',
                label: $this->translator->trans('Delete this administrator'),
                modalTarget: '#administrator-delete-modal',
                grantedAttribute: AccessManager::DELETE,
                grantedSubject: self::RESOURCE,
                dataAttributes: ['administrator-id' => $id, 'administrator-login' => $admin->getLogin()],
            );
        }

        $profile = $admin->getProfile();
        $profileLabel = $profile !== null
            ? ($profile->setLocale($defaultLocale)->getTitle() ?: $profile->getCode())
            : $this->translator->trans('Superadministrator');

        return [
            'id' => $id,
            'login' => $admin->getLogin(),
            'name' => trim($admin->getFirstname().' '.$admin->getLastname()),
            'email' => $admin->getEmail(),
            'profile' => $profileLabel,
            'two_factor' => $twoFactorEnabled ? $this->translator->trans('Turned on') : $this->translator->trans('Turned off'),
            '_actions' => $actions,
        ];
    }

    /**
     * Recover the per-id edit form name ('thelia_administrator_update_<id>') from the posted body.
     * Falls back to the bare name so an empty/unexpected body fails validation cleanly.
     */
    private function submittedUpdateFormName(Request $request): string
    {
        foreach (array_keys($request->request->all()) as $key) {
            if (preg_match('/^thelia_administrator_update_\d+$/', (string) $key) === 1) {
                return (string) $key;
            }
        }

        return 'thelia_administrator_update';
    }

    /**
     * @param array<int|string, int|string> $profileChoices
     */
    private function createEditForm(Admin $admin, string $defaultLocale, array $profileChoices): FormInterface
    {
        return $this->formFactory->createNamed('thelia_administrator_update_'.$admin->getId(), AdministratorType::class, [
            'id' => $admin->getId(),
            'login' => $admin->getLogin(),
            'firstname' => $admin->getFirstname(),
            'lastname' => $admin->getLastname(),
            'email' => $admin->getEmail(),
            'profile' => $admin->getProfileId(),
            'locale' => $admin->getLocale() ?: $defaultLocale,
        ], [
            'include_id' => true,
            'profile_choices' => $profileChoices,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function profileChoices(): array
    {
        $locale = $this->resolveDefaultLocale();
        $choices = [];
        foreach (ProfileQuery::create()->orderByCode()->find() as $profile) {
            $profile->setLocale($locale);
            $label = $profile->getTitle();
            if ($label === null || $label === '') {
                $label = $profile->getCode();
            }
            $choices[(string) $label] = (int) $profile->getId();
        }

        return $choices;
    }

    private function resolveDefaultLocale(): string
    {
        $defaultLang = \Thelia\Model\LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }

    /**
     * A profile is a ceiling: an administrator who has one manages accounts within it.
     * The superadministrator profile (no profile row) is above every ceiling, so only a
     * superadministrator may grant it, edit an account that holds it, or move their own
     * account to another profile.
     */
    private function assertProfileAssignable(?int $profileId, ?Admin $target = null): void
    {
        $current = $this->securityContext->getAdminUser();

        if (!$current instanceof Admin || null === $current->getProfileId()) {
            return;
        }

        if (null === $profileId) {
            throw new \RuntimeException($this->translator->trans('Only a superadministrator can grant the superadministrator profile.'));
        }

        if (!$target instanceof Admin) {
            return;
        }

        if (null === $target->getProfileId()) {
            throw new \RuntimeException($this->translator->trans('Only a superadministrator can edit a superadministrator account.'));
        }

        if ((int) $target->getId() === (int) $current->getId() && $profileId !== (int) $current->getProfileId()) {
            throw new \RuntimeException($this->translator->trans('You cannot change the profile of your own account.'));
        }
    }

    private function isAboveTheCeilingOfTheCurrentAdmin(Admin $target): bool
    {
        $current = $this->securityContext->getAdminUser();

        return $current instanceof Admin && null !== $current->getProfileId() && null === $target->getProfileId();
    }

    private function currentAdminId(): ?int
    {
        $admin = $this->securityContext->getAdminUser();

        return $admin instanceof Admin ? (int) $admin->getId() : null;
    }

    private function flashError(Request $request, string $message): void
    {
        $this->flash($request, 'danger', $message);
    }

    private function flash(Request $request, string $type, string $message): void
    {
        try {
            $session = $request->getSession();
            if (method_exists($session, 'getFlashBag')) {
                $session->getFlashBag()->add($type, $message);
            }
        } catch (\Throwable) {
        }
    }
}
