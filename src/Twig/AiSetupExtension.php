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

namespace BackOfficeDefaultTwigBundle\Twig;

use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiSetupGuide;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `ai_setup_pending_count()`: how many AI features cannot run until the merchant finishes the setup, for the
 * notice every tab of the AI screens shows.
 */
final class AiSetupExtension extends AbstractExtension
{
    public function __construct(
        private readonly AiSetupGuide $guide,
        private readonly RequestStack $requests,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ai_setup_pending_count', fn (): int => $this->guide->pendingCount($this->requests->getCurrentRequest()?->getLocale())),
        ];
    }
}
