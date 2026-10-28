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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration\Ai;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiToolAdmin;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Twig\Environment;

/**
 * Configuration > AI > Tools: what the modules let the AI do in the shop, and under which right. Read only: a tool is
 * declared by code, so the screen lists it.
 */
#[Route('/admin/configuration/ai/tools', name: 'admin.configuration.ai.tools.')]
final class AiToolController
{
    public function __construct(
        private readonly AiToolAdmin $tools,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function index(): Response
    {
        if ($denied = $this->access->check(AdminResources::AI, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/ai/tool/list.html.twig', ['rows' => $this->tools->rows()]));
    }
}
