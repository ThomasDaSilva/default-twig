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

namespace BackOfficeDefaultTwigBundle\Service\Configuration\Ai;

use Symfony\Component\HttpFoundation\Request;

/**
 * A one-time value for the form that creates an MCP token: set in the session when the form is shown, used up by the
 * first post that carries it. The security token of the back office lives as long as the session, so alone it lets a
 * reloaded post create a second token; with this value the same post is read once.
 */
final class AiTokenCreationNonce
{
    private const SESSION_KEY = 'ai_token_creation_nonce';

    public function issue(Request $request): string
    {
        $nonce = bin2hex(random_bytes(16));

        if ($request->hasSession()) {
            $request->getSession()->set(self::SESSION_KEY, $nonce);
        }

        return $nonce;
    }

    /**
     * @return bool true once for the value of the last form shown; false for any other, a second time included
     */
    public function consume(Request $request, string $submitted): bool
    {
        if (!$request->hasSession()) {
            return false;
        }

        $session = $request->getSession();
        $expected = $session->remove(self::SESSION_KEY);

        return \is_string($expected) && '' !== $expected && hash_equals($expected, $submitted);
    }
}
