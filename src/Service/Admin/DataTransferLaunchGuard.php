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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Tools\TokenProvider;

/**
 * What an export or an import form must pass before anything is launched: its token,
 * then the number of launches its administrator already asked for.
 *
 * An export reads, an import rewrites, the whole catalog in one job, and a queue runs
 * them one after the other: a form sent over and over would hold the heavy queue for
 * hours. The limit is spent only by a launch that will happen, once the form is known
 * to be valid.
 */
final readonly class DataTransferLaunchGuard
{
    public function __construct(
        private TokenProvider $tokens,
        private TranslatorInterface $translator,
        private DataTransferJobAccess $jobAccess,
        #[Autowire(service: 'limiter.admin_data_transfer_launch')]
        private RateLimiterFactoryInterface $launchLimiter,
    ) {
    }

    /**
     * A form left open past its token is sent back with a message, never answered
     * with a server error.
     */
    public function hasValidToken(Request $request): bool
    {
        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));

            return true;
        } catch (TokenAuthenticationException) {
            self::flash($request, $this->translator->trans('The form has expired, please try again.'));

            return false;
        }
    }

    public function mayLaunch(Request $request): bool
    {
        if ($this->launchLimiter->create((string) $this->jobAccess->currentAdminId())->consume()->isAccepted()) {
            return true;
        }

        self::flash($request, $this->translator->trans('Too many exports and imports asked for in a short time: wait a few minutes before the next one.'));

        return false;
    }

    private static function flash(Request $request, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $message);
        }
    }
}
