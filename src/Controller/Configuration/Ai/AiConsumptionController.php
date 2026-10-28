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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;
use Thelia\Domain\Ai\Log\AiConsumptionReport;
use Thelia\Domain\Ai\Service\AiSettings;
use Thelia\Domain\Ai\Service\AiSettingsWriter;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * Configuration > AI > Consumption: what the AI cost over a period, by usage, module, model and day, and the two
 * global settings of the layer: the master switch and the monthly cap all usages share.
 */
#[Route('/admin/configuration/ai/consumption', name: 'admin.configuration.ai.consumption.')]
final class AiConsumptionController
{
    private const RESOURCE = AdminResources::AI;
    private const LIST_ROUTE = 'admin.configuration.ai.consumption.default';
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        private readonly AiConsumptionReport $report,
        private readonly AiSettings $settings,
        private readonly AiSettingsWriter $writer,
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly AdminFlash $flash,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly TokenProvider $tokens,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $today = new \DateTimeImmutable('today');
        $to = $this->date($request->query->getString('to')) ?? $today;
        $from = $this->date($request->query->getString('from')) ?? $to->modify('-29 days');

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $tables = [];
        $total = ['calls' => 0, 'cost' => 0.0];

        foreach (AiConsumptionReport::DIMENSIONS as $dimension) {
            $tables[$dimension] = $this->report->by($dimension, $from, $to);
        }

        foreach ($tables[AiConsumptionReport::BY_DAY] as $day) {
            $total['calls'] += $day['calls'];
            $total['cost'] += (float) $day['cost'];
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/ai/consumption/index.html.twig', [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'tables' => $tables,
            'total_calls' => $total['calls'],
            'total_cost' => number_format($total['cost'], 4, '.', ''),
            'ai_enabled' => $this->settings->isEnabled(),
            'monthly_cap' => $this->settings->monthlyCap(),
        ]));
    }

    #[Route('/settings', name: 'settings', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $back = new RedirectResponse($this->urls->generate(self::LIST_ROUTE));

        if (!$this->tokenIsValid($request)) {
            $this->flash->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $back;
        }

        try {
            $this->writer->setMonthlyCap('' === trim($request->request->getString('monthly_cap')) ? '0' : $request->request->getString('monthly_cap'));
        } catch (AiExceptionInterface $refusal) {
            $this->flash->add($request, 'danger', $refusal->getMessage());

            return $back;
        }

        $this->writer->setEnabled($request->request->getBoolean('ai_enabled'));
        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, 'AI global settings updated');
        $this->flash->add($request, 'success', $this->translator->trans('The setting was saved.'));

        return $back;
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        if (1 !== preg_match(self::DATE, $value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date || $date->format('Y-m-d') !== $value ? null : $date;
    }

    private function tokenIsValid(Request $request): bool
    {
        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
