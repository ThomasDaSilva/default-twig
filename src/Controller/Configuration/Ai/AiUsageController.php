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
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiLabels;
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
    public function index(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $rows = [];
        foreach ($this->usages->rows() as $row) {
            $id = (string) $row['id'];
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
            $this->usages->save(
                $id,
                $request->request->getBoolean('enabled'),
                $request->request->getString('tier'),
                $request->request->getString('monthly_budget'),
                $request->request->getString('pinned_model'),
                $request->request->getBoolean('notice'),
                $request->request->getBoolean('mark'),
            );
        } catch (AiExceptionInterface|HttpExceptionInterface|\InvalidArgumentException $refusal) {
            // The message is plain text of the core: the flash is escaped by the layout.
            $this->flash->add($request, 'danger', $refusal->getMessage());

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
        ]));
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
