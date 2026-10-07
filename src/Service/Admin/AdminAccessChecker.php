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

namespace BackOfficeDefaultTwigBundle\Service\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;

/**
 * Returns `null` when the current admin is granted, otherwise either:
 *  - a 302 redirect to /admin/login with `_target_path` when no admin session is open;
 *  - a 403 Response when the admin is authenticated but lacks the required permission.
 *
 *     if ($denied = $this->access->check(AdminResources::BRAND, [], 'UPDATE')) {
 *         return $denied;
 *     }
 *
 * Denials are appended to the admin audit log.
 */
readonly class AdminAccessChecker
{
    private const ADMIN_ROLE = 'ADMIN';
    private const LOGIN_ROUTE = 'admin.login';

    public function __construct(
        private SecurityContext $securityContext,
        private AdminLogger $adminLogger,
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param list<string>|string $resources
     * @param list<string>|string $modules
     * @param list<string>|string $accesses
     */
    public function check(array|string $resources, array|string $modules, array|string $accesses): ?Response
    {
        $resources = (array) $resources;
        $modules = (array) $modules;
        $accesses = (array) $accesses;

        if ($this->securityContext->isGranted([self::ADMIN_ROLE], $resources, $modules, $accesses)) {
            return null;
        }

        if (!$this->securityContext->hasAdminUser()) {
            return $this->redirectToLogin();
        }

        $this->adminLogger->log(
            implode(',', $resources),
            implode(',', $accesses),
            \sprintf(
                'User is not granted for resources [%s] with accesses [%s]',
                implode(',', $resources),
                implode(',', $accesses),
            ),
        );

        return new Response(
            $this->translator->trans("Sorry, you're not allowed to perform this action"),
            Response::HTTP_FORBIDDEN,
        );
    }

    /**
     * A plain yes/no on one resource, for the screens that adapt their content instead of
     * refusing the whole request: no redirect, no 403, no audit entry - the admin did not
     * try to do anything they are not allowed to.
     */
    /**
     * Whether the administrator holds the access, for a screen that adapts to it: no
     * audit entry, nobody tried to do anything.
     */
    public function can(string $resource, string $access): bool
    {
        return $this->securityContext->isGranted([self::ADMIN_ROLE], [$resource], [], [$access]);
    }

    public function canView(string $resource): bool
    {
        return $this->securityContext->isGranted([self::ADMIN_ROLE], [$resource], [], [AccessManager::VIEW]);
    }

    /**
     * Grants access as soon as ONE of the resources is allowed (logical OR), unlike {@see check()}
     * which requires all of them. Used to bridge a renamed ACL resource with its legacy name during
     * the Smarty/Twig cohabitation.
     *
     * @param list<string> $resources
     */
    public function checkAny(array $resources, string $access): ?Response
    {
        foreach ($resources as $resource) {
            if ($this->securityContext->isGranted([self::ADMIN_ROLE], [$resource], [], [$access])) {
                return null;
            }
        }

        return $this->check($resources, [], $access);
    }

    private function redirectToLogin(): RedirectResponse
    {
        $loginUrl = $this->urls->generate(self::LOGIN_ROUTE);
        $request = $this->requestStack->getMainRequest();
        if ($request !== null) {
            $target = $request->getRequestUri();
            if ($target !== '' && $target !== '/' && !str_starts_with($target, '/admin/login')) {
                $loginUrl .= (str_contains($loginUrl, '?') ? '&' : '?').'_target_path='.rawurlencode($target);
            }
        }

        return new RedirectResponse($loginUrl);
    }
}
