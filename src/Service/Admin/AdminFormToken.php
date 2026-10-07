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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Tools\TokenProvider;

/**
 * Checks the token a back-office form was sent with. A form left open past its token
 * is sent back with a message, never answered with a server error.
 */
final readonly class AdminFormToken
{
    public function __construct(
        private TokenProvider $tokens,
        private TranslatorInterface $translator,
        private AdminFlash $flash,
    ) {
    }

    public function isValid(Request $request): bool
    {
        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));

            return true;
        } catch (TokenAuthenticationException) {
            $this->flash->add($request, 'error', $this->translator->trans('The form has expired, please try again.'));

            return false;
        }
    }
}
