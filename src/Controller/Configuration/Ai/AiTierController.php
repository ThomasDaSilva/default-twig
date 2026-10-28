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

use BackOfficeDefaultTwigBundle\Form\Configuration\Ai\AiTierType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiTierAdmin;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
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
use Thelia\Domain\Ai\Service\AiFailureExplainer;
use Twig\Environment;

/**
 * Configuration > AI > Ranges: the model each range (fast, standard, advanced) runs on. A feature asks for a
 * range, never for a model: changing the model here changes it everywhere.
 */
#[Route('/admin/configuration/ai/tiers', name: 'admin.configuration.ai.tiers.')]
final class AiTierController
{
    private const RESOURCE = AdminResources::AI;
    private const LIST_ROUTE = 'admin.configuration.ai.tiers.default';

    public function __construct(
        private readonly AiTierAdmin $tiers,
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly AdminFlash $flash,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly AiFailureExplainer $failures,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function index(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return $this->render($this->form());
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $form = $this->form();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                foreach (AiTier::cases() as $tier) {
                    $this->tiers->assign($tier, $form->get($tier->value)->getData());
                }
            } catch (AiExceptionInterface|HttpExceptionInterface $refusal) {
                $form->addError(new FormError($this->failureMessage($refusal, $request)));

                return $this->render($form);
            }

            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, 'AI ranges updated');
            $this->flash->add($request, 'success', $this->translator->trans('The ranges were saved.'));

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->render($form);
    }

    private function form(): FormInterface
    {
        $data = [];
        foreach (AiTier::cases() as $tier) {
            $data[$tier->value] = $this->tiers->choiceOf($tier);
        }

        return $this->formFactory->createNamed('thelia_ai_tiers', AiTierType::class, $data, ['choices' => $this->tiers->choices()]);
    }

    private function render(FormInterface $form): Response
    {
        return new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/ai/tier/index.html.twig', [
            'form' => $form->createView(),
            'has_choices' => [] !== $this->tiers->choices(),
        ]));
    }

    private function failureMessage(\Throwable $refusal, Request $request): string
    {
        return $refusal instanceof AiExceptionInterface ? $this->failures->explain($refusal, $request->getLocale()) : $refusal->getMessage();
    }
}
