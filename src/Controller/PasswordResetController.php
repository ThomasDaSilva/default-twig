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

namespace BackOfficeDefaultTwigBundle\Controller;

use BackOfficeDefaultTwigBundle\Form\Auth\CreatePasswordType;
use BackOfficeDefaultTwigBundle\Form\Auth\LostPasswordType;
use BackOfficeDefaultTwigBundle\Security\AuthThrottle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Administrator\AdministratorEvent;
use Thelia\Core\Event\Administrator\AdministratorUpdatePasswordEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session as TheliaSession;
use Thelia\Core\Security\SecurityContext;
use Thelia\Model\AdminLog;
use Thelia\Model\AdminQuery;
use Thelia\Model\ConfigQuery;
use Twig\Environment;

final class PasswordResetController
{
    private const TOKEN_KEY = 'thelia_admin_password_renew_token';

    private const THROTTLE_LOST_PASSWORD = 'lost-password';

    private const THROTTLE_CREATE_PASSWORD = 'create-password';

    public function __construct(
        private readonly Environment $twig,
        private readonly EventDispatcherInterface $events,
        private readonly SecurityContext $securityContext,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly FormFactoryInterface $forms,
        private readonly AuthThrottle $throttle,
        #[Autowire(service: 'limiter.admin_lost_password')]
        private readonly RateLimiterFactoryInterface $lostPasswordLimiter,
    ) {
    }

    #[Route('/admin/lost-password', name: 'admin.lost-password', methods: ['GET', 'POST'])]
    public function lostPassword(Request $request): Response
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $form = $this->forms->create(LostPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if (!$this->throttle->consume(self::THROTTLE_LOST_PASSWORD)) {
                $form->addError(new \Symfony\Component\Form\FormError(
                    $this->translator->trans('Too many attempts, please try again later.'),
                ));

                return $this->renderLostPassword($form);
            }

            if ($form->isValid()) {
                return $this->handleLostPasswordRequest($request, $form);
            }
        }

        return $this->renderLostPassword($form);
    }

    #[Route('/admin/password-create-request-success', name: 'admin.password-create-success', methods: ['GET'])]
    public function lostPasswordSuccess(): Response
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return $this->renderLostPassword(
            $this->forms->create(LostPasswordType::class),
            ['create_request_success' => true],
        );
    }

    #[Route('/admin/password-create/{token}', name: 'admin.password-create-form', methods: ['GET', 'POST'], requirements: ['token' => '.*'])]
    public function createPassword(Request $request, string $token): Response
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $admin = AdminQuery::create()->findOneByPasswordRenewToken($token);
        if ($admin === null) {
            return $this->renderLostPassword(
                $this->forms->create(LostPasswordType::class),
                ['token_error' => true],
            );
        }

        $session = $request->getSession();
        if ($session instanceof TheliaSession) {
            $session->set(self::TOKEN_KEY, $token);
        }

        $form = $this->forms->create(CreatePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if (!$this->throttle->consume(self::THROTTLE_CREATE_PASSWORD)) {
                $form->addError(new \Symfony\Component\Form\FormError(
                    $this->translator->trans('Too many attempts, please try again later.'),
                ));

                return $this->renderCreatePassword($form);
            }

            if ($form->isValid()) {
                return $this->handlePasswordChange($request, $form);
            }
        }

        return $this->renderCreatePassword($form);
    }

    #[Route('/admin/password-create-success', name: 'admin.password-renewed-success', methods: ['GET'])]
    public function passwordRenewedSuccess(): Response
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/auth/create-password-success.html.twig'));
    }

    #[Route('/admin/set-email-address', name: 'admin.set-email-address', methods: ['GET', 'POST'])]
    public function setEmailAddress(): Response
    {
        return new RedirectResponse($this->urls->generate('admin.configuration.administrators.view', [
            'show_email_change_notice' => 1,
        ]));
    }

    private function handleLostPasswordRequest(Request $request, FormInterface $form): Response
    {
        $usernameOrEmail = trim((string) $form->get('username_or_email')->getData());
        $admin = AdminQuery::create()->findOneByEmail($usernameOrEmail)
            ?? AdminQuery::create()->findOneByLogin($usernameOrEmail);

        if ($admin === null) {
            AdminLog::append('admin', 'ADMIN_LOST_PASSWORD', 'Invalid username or email', $request);
            $form->addError(new \Symfony\Component\Form\FormError(
                $this->translator->trans('Invalid username or email.'),
            ));

            return $this->renderLostPassword($form);
        }

        if (((string) $admin->getEmail()) === '') {
            $form->addError(new \Symfony\Component\Form\FormError(
                $this->translator->trans('Sorry, no email defined for this administrator.'),
            ));

            return $this->renderLostPassword($form);
        }

        // The count of the caller stands: naming an administrator is not a proof of being
        // them. And the administrator named is mailed a few links an hour, from whoever
        // asks.
        if (!$this->lostPasswordLimiter->create((string) $admin->getId())->consume()->isAccepted()) {
            $form->addError(new \Symfony\Component\Form\FormError(
                $this->translator->trans('Too many attempts, please try again later.'),
            ));

            return $this->renderLostPassword($form);
        }

        $this->events->dispatch(new AdministratorEvent($admin), TheliaEvents::ADMINISTRATOR_CREATEPASSWORD);

        return new RedirectResponse($this->urls->generate('admin.password-create-success'));
    }

    private function handlePasswordChange(Request $request, FormInterface $form): Response
    {
        $session = $request->getSession();
        $token = $session instanceof TheliaSession ? (string) ($session->get(self::TOKEN_KEY) ?? '') : '';
        $admin = $token === '' ? null : AdminQuery::create()->findOneByPasswordRenewToken($token);

        if ($admin === null) {
            $form->addError(new \Symfony\Component\Form\FormError(
                $this->translator->trans('An invalid token was provided, your password cannot be changed.'),
            ));

            return $this->renderCreatePassword($form);
        }

        $event = new AdministratorUpdatePasswordEvent($admin);
        $event->setPassword((string) $form->get('password')->getData());
        $this->events->dispatch($event, TheliaEvents::ADMINISTRATOR_UPDATEPASSWORD);

        $session->set(self::TOKEN_KEY, null);

        $this->throttle->reset(self::THROTTLE_CREATE_PASSWORD);

        return new RedirectResponse($this->urls->generate('admin.password-renewed-success'));
    }

    /** @param array<string, mixed> $extra */
    private function renderLostPassword(FormInterface $form, array $extra = []): Response
    {
        return new Response($this->twig->render('@BackOfficeDefaultTwig/auth/lost-password.html.twig', array_merge([
            'form' => $form->createView(),
            'create_request_success' => false,
            'token_error' => false,
        ], $extra)));
    }

    private function renderCreatePassword(FormInterface $form): Response
    {
        return new Response($this->twig->render('@BackOfficeDefaultTwig/auth/create-password.html.twig', [
            'form' => $form->createView(),
        ]));
    }

    private function guard(): ?Response
    {
        if (!ConfigQuery::read('enable_lost_admin_password_recovery', false)) {
            return new Response(
                $this->twig->render('@BackOfficeDefaultTwig/general_error.html.twig', [
                    'error_message' => $this->translator->trans('The lost admin password recovery feature is disabled.'),
                ]),
                Response::HTTP_FORBIDDEN,
            );
        }

        if ($this->securityContext->getAdminUser() !== null) {
            return new RedirectResponse('/admin');
        }

        return null;
    }
}
