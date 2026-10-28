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
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiGuardrailSelection;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiLabels;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiToolAdmin;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiUsageAdmin;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;
use Thelia\Domain\Ai\Guardrail\GuardrailMode;
use Thelia\Domain\Ai\Service\AiFailureExplainer;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * Configuration > AI > Usages: what each module asks the AI for, with its switch, its monthly budget, and the two
 * duties of Article 50 of the European AI regulation. The screen explains; it never blocks a choice: a usage that
 * does not respect Article 50 is saved and flagged.
 */
#[Route('/admin/configuration/ai/usages', name: 'admin.configuration.ai.usages.')]
final class AiUsageController
{
    private const RESOURCE = AdminResources::AI;
    private const LIST_ROUTE = 'admin.configuration.ai.usages.default';
    private const ID = '[a-z0-9_]+\.[a-z0-9_]+';

    public function __construct(
        private readonly AiUsageAdmin $usages,
        private readonly AiLabels $labels,
        private readonly AiToolAdmin $tools,
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly AdminFlash $flash,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly TokenProvider $tokens,
        private readonly AiFailureExplainer $failures,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function index(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $rows = [];
        foreach ($this->usages->rows() as $row) {
            $id = (string) $row['id'];
            $usageTools = $this->tools->toolsOfUsage($id);
            $rows[] = [
                'id' => $id,
                'usage' => $row['purpose'],
                'module' => $row['module'],
                'audience' => $this->labels->audience((string) $row['audience']),
                'tier' => $this->labels->tierName((string) $row['tier']),
                'budget' => null === $row['budget'] ? $this->translator->trans('No budget') : '$'.$row['budget'],
                'status' => null === $row['inactive_reason'] ? 'active' : 'inactive',
                'status_label' => $this->translator->trans(null === $row['inactive_reason'] ? 'Running' : 'Not running'),
                'reason' => null === $row['inactive_reason'] ? '' : $this->labels->inactiveReason((string) $row['inactive_reason']),
                'tools' => [] === $usageTools ? '-' : implode(', ', array_map(static fn ($tool): string => $tool->name, $usageTools)),
                'trifecta' => $this->tools->isLethalTrifecta($usageTools) ? 'yes' : 'no',
                'trifecta_label' => $this->translator->trans($this->tools->isLethalTrifecta($usageTools) ? 'Lethal trifecta' : 'No'),
                'compliance' => (string) $row['compliance'],
                'compliance_label' => $this->labels->compliance((string) $row['compliance']),
                '_actions' => [
                    new RowAction(kind: 'edit', label: $this->translator->trans('Configure'), href: $this->urls->generate('admin.configuration.ai.usages.edit', ['id' => $id]), grantedAttribute: AccessManager::VIEW, grantedSubject: self::RESOURCE),
                ],
            ];
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/ai/usage/list.html.twig', ['rows' => $rows]));
    }

    #[Route('/{id}', name: 'edit', methods: ['GET'], requirements: ['id' => self::ID])]
    public function edit(string $id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return $this->render($id);
    }

    #[Route('/{id}', name: 'save', methods: ['POST'], requirements: ['id' => self::ID])]
    public function save(Request $request, string $id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $back = new RedirectResponse($this->urls->generate('admin.configuration.ai.usages.edit', ['id' => $id]));

        if (!$this->tokenIsValid($request)) {
            $this->flash->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $back;
        }

        if (null === $this->usages->row($id)) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        try {
            $general = $this->sent($request, AiUsageAdmin::SECTION_GENERAL);
            $current = $this->usages->row($id);
            // A form that does not carry a setting keeps it: only a field sent empty goes back to its default.
            $field = fn (string $name, mixed $stored): string => $general && $request->request->has($name) ? $request->request->getString($name) : (string) $stored;

            $this->usages->save(
                $id,
                $general ? $request->request->getBoolean('enabled') : $this->usages->isSwitchedOn($id),
                $field('tier', $this->usages->chosenTierOf($id)),
                $field('monthly_budget', $this->usages->budgetOf($id)),
                $field('pinned_model', $this->usages->pinnedChoiceOf($id)),
                $general ? $request->request->getBoolean('notice') : (bool) $current['notice_enabled'],
                $general ? $request->request->getBoolean('mark') : (bool) $current['provenance_mark_enabled'],
                $this->limits($request),
                $this->guardrailChoices($request),
                $this->privacy($request),
            );
        } catch (AiExceptionInterface|HttpExceptionInterface|\InvalidArgumentException $refusal) {
            // The message is plain text of the core: the flash is escaped by the layout.
            $this->flash->add($request, 'danger', $this->failureMessage($refusal, $request));

            return $back;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('AI usage %s updated', $id));
        $this->flash->add($request, 'success', $this->translator->trans('The usage was saved.'));

        return $back;
    }

    private function render(string $id): Response
    {
        $row = $this->usages->row($id);

        if (null === $row) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/ai/usage/edit.html.twig', [
            'row' => $row,
            'audience_label' => $this->labels->audience((string) $row['audience']),
            'tier_label' => $this->labels->tierName((string) $row['tier']),
            'declared_tier_label' => $this->labels->tierName((string) $row['declared_tier']),
            'tier_choices' => array_map(fn (AiTier $tier): array => ['value' => $tier->value, 'label' => $this->labels->tier($tier)], AiTier::cases()),
            'chosen_tier' => $this->usages->chosenTierOf($id),
            'inactive_reason' => null === $row['inactive_reason'] ? null : $this->labels->inactiveReason((string) $row['inactive_reason']),
            'budget' => $this->usages->budgetOf($id),
            'pinned' => $this->usages->pinnedChoiceOf($id),
            'model_choices' => $this->usages->modelChoices(),
            'switched_on' => $this->usages->isSwitchedOn($id),
            'limits' => $this->usages->limitsOf($id),
            'guardrails' => array_map(fn (array $guardrail): array => $guardrail + ['label' => $this->labels->guardrail($guardrail['name']), 'help' => $this->labels->guardrailHelp($guardrail['name']), 'declared_label' => null === $guardrail['declared'] ? $this->translator->trans('Not applied') : $this->labels->guardrailMode($guardrail['declared'])], $this->usages->guardrailRows($id)),
        ]));
    }

    private function sent(Request $request, string $section): bool
    {
        return \in_array($section, $request->request->all('sections'), true);
    }

    /**
     * @return array<string, int|null>|null null when the form did not send the section: what is stored stays
     */
    private function limits(Request $request): ?array
    {
        if (!$this->sent($request, AiUsageAdmin::SECTION_LIMITS)) {
            return null;
        }

        $limits = [];

        foreach (['requests_per_minute' => 'Requests per minute', 'requests_per_day' => 'Requests per day', 'max_output_tokens' => 'Length of the answer', 'max_input_characters' => 'Length of what a person types'] as $field => $label) {
            if ($request->request->has($field)) {
                $limits[$field] = $this->wholeNumber($request->request->getString($field), $label);
            }
        }

        return $limits;
    }

    /**
     * @return array<string, string>|null
     */
    private function guardrailChoices(Request $request): ?array
    {
        if (!$this->sent($request, AiUsageAdmin::SECTION_GUARDRAILS)) {
            return null;
        }

        $choices = [];
        $allowed = [AiGuardrailSelection::FOLLOW, AiGuardrailSelection::OFF, ...array_column(GuardrailMode::cases(), 'value')];

        foreach ($request->request->all('guardrail') as $name => $choice) {
            if (!\is_string($choice) || !\in_array($choice, $allowed, true)) {
                throw new \InvalidArgumentException($this->translator->trans('The choice made for the guardrail "%name%" is not one of the proposed ones.', ['%name%' => (string) $name]));
            }

            $choices[(string) $name] = $choice;
        }

        return $choices;
    }

    /**
     * @return array{store_prompt: bool, prompt_retention_days?: int|null}|null
     */
    private function privacy(Request $request): ?array
    {
        if (!$this->sent($request, AiUsageAdmin::SECTION_PRIVACY)) {
            return null;
        }

        $privacy = ['store_prompt' => $request->request->getBoolean('store_prompt')];

        if ($request->request->has('prompt_retention_days')) {
            $privacy['prompt_retention_days'] = $this->wholeNumber($request->request->getString('prompt_retention_days'), 'Retention of the prompts');
        }

        return $privacy;
    }

    private function wholeNumber(string $raw, string $label): ?int
    {
        $raw = trim($raw);

        if ('' === $raw) {
            return null;
        }

        if (1 !== preg_match('/^[1-9]\d{0,8}$/', $raw)) {
            throw new \InvalidArgumentException($this->translator->trans('%field% must be a whole number, 1 or more. Leave it empty to use the default.', ['%field%' => $this->translator->trans($label)]));
        }

        return (int) $raw;
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

    private function failureMessage(\Throwable $refusal, Request $request): string
    {
        if ($refusal instanceof AiExceptionInterface) {
            return $this->failures->explain($refusal, $request->getLocale());
        }

        // The messages of this screen are already in the language of the administrator, but the one of the service is an English source text.
        return $this->translator->trans($refusal->getMessage());
    }
}
