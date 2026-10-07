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
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private AdminFormToken $formToken,
        private TranslatorInterface $translator,
        private DataTransferJobAccess $jobAccess,
        private AdminFlash $flash,
        #[Autowire(service: 'limiter.admin_data_transfer_launch')]
        private RateLimiterFactoryInterface $launchLimiter,
    ) {
    }

    public function hasValidToken(Request $request): bool
    {
        return $this->formToken->isValid($request);
    }

    public function mayLaunch(Request $request): bool
    {
        if ($this->launchLimiter->create((string) $this->jobAccess->currentAdminId())->consume()->isAccepted()) {
            return true;
        }

        $this->flash->add($request, 'error', $this->translator->trans('Too many exports and imports asked for in a short time: wait a few minutes before the next one.'));

        return false;
    }
}
