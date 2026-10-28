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
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiLabels;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiUsageAdmin;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Ai\Service\AiUsageRegisterExport;
use Twig\Environment;

/**
 * Configuration > AI > Register: the list of the AI the shop runs, to keep as a record (AI Act). The exports give
 * the lines of the register exactly as the console command and the admin API do.
 */
#[Route('/admin/configuration/ai/register', name: 'admin.configuration.ai.register.')]
final class AiRegisterController
{
    private const RESOURCE = AdminResources::AI;

    public function __construct(
        private readonly AiUsageAdmin $usages,
        private readonly AiUsageRegisterExport $export,
        private readonly AiLabels $labels,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function index(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $rows = [];
        foreach ($this->usages->register() as $row) {
            $row = $this->usages->withTarget($row);
            $row['audience_label'] = $this->labels->audience((string) $row['audience']);
            $row['tier_label'] = $this->labels->tierName((string) $row['tier']);
            $row['provider_label'] = $this->labels->provider(\is_string($row['provider']) ? $row['provider'] : null);
            $row['compliance_label'] = $this->labels->compliance((string) $row['compliance']);
            $rows[] = $row;
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/ai/register/index.html.twig', ['rows' => $rows]));
    }

    #[Route('/export.{format}', name: 'export', methods: ['GET'], requirements: ['format' => 'csv|json'])]
    public function export(string $format): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $rows = $this->usages->register();
        $csv = 'csv' === $format;

        return new Response(
            $csv ? $this->export->csv($rows) : $this->export->json($rows),
            Response::HTTP_OK,
            [
                'Content-Type' => $csv ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="ai-usage-register.'.$format.'"',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
