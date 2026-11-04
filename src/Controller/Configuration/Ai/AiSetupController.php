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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiConnectionAdmin;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiLabels;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiSetupGuide;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiTierAdmin;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiUsageAdmin;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Security\User\UserInterface;
use Thelia\Domain\Ai\AiProvider;
use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;
use Thelia\Domain\Ai\Service\AiFailureExplainer;
use Thelia\Domain\Ai\Service\AiSettingsWriter;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * Configuration > AI > Getting started: the three steps that make an AI feature of a module run (a connection to a
 * provider, a model for each range, a budget for the features that talk to visitors), each done on the spot with
 * the services of the other tabs. A module links to a feature with `?usage=<id>`.
 */
#[Route('/admin/configuration/ai/setup', name: 'admin.configuration.ai.setup.')]
final class AiSetupController
{
    private const RESOURCE = AdminResources::AI;
    private const SETUP_ROUTE = 'admin.configuration.ai.setup.default';
    private const ID = '[a-z0-9_]+\.[a-z0-9_]+';

    public function __construct(
        private readonly AiSetupGuide $guide,
        private readonly AiConnectionAdmin $connections,
        private readonly AiTierAdmin $tiers,
        private readonly AiUsageAdmin $usages,
        private readonly AiSettingsWriter $writer,
        private readonly AiLabels $labels,
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly AdminFlash $flash,
        private readonly AdminFormErrorRenderer $errors,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly TokenProvider $tokens,
        private readonly SecurityContext $securityContext,
        #[Target('ai_connection_test')]
        private readonly RateLimiterFactoryInterface $testLimiter,
        private readonly AiFailureExplainer $failures,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $usage = $request->query->getString('usage');
        $response = new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/ai/setup/index.html.twig', [
            'guide' => $this->guide->overview($request->getLocale(), '' === $usage ? null : $usage),
            'usage' => $usage,
        ]));
        // The page shows the result of the last test of a connection, and the form of a key: nothing of it is kept.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Step 1: saves the key of a provider, then tests it.
     */
    #[Route('/connection', name: 'connection', methods: ['POST'])]
    public function connect(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $back = $this->back($request);

        if (!$this->tokenIsValid($request)) {
            return $this->invalidToken($request, $back);
        }

        $provider = AiProvider::tryFrom($request->request->getString('provider'));
        $key = trim($request->request->getString('api_key'));

        if (null === $provider || !\in_array($provider, AiSetupGuide::CONNECTABLE_PROVIDERS, true)) {
            $this->flash->add($request, 'danger', $this->translator->trans('Choose a provider in the list.'));

            return $back;
        }

        if ('' === $key) {
            $this->flash->add($request, 'danger', $this->translator->trans('Paste the API key given by the provider.'));

            return $back;
        }

        try {
            $connection = $this->connections->connect($provider->value, $this->labels->provider($provider), $key);
        } catch (AiExceptionInterface $refusal) {
            $this->flash->add($request, 'danger', $this->failures->explain($refusal, $request->getLocale()));

            return $back;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('AI connection %s saved from the setup', (string) $connection->getTitle()), $connection->getId());

        return $this->test($request, (int) $connection->getId(), $back, true);
    }

    #[Route('/connection/{id}/test', name: 'test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function testConnection(Request $request, int $id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $back = $this->back($request);

        if (!$this->tokenIsValid($request)) {
            return $this->invalidToken($request, $back);
        }

        return $this->test($request, $id, $back, false);
    }

    /**
     * The master switch, turned back on: the same write as the Consumption tab.
     */
    #[Route('/enable', name: 'enable', methods: ['POST'])]
    public function enable(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $back = $this->back($request);

        if (!$this->tokenIsValid($request)) {
            return $this->invalidToken($request, $back);
        }

        $this->writer->setEnabled(true);
        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, 'AI: turned on from the setup');
        $this->flash->add($request, 'success', $this->translator->trans('AI is allowed in the shop again.'));

        return $back;
    }

    /**
     * Step 2: gives each range the model chosen in the form. A range left empty keeps what it has.
     */
    #[Route('/models', name: 'models', methods: ['POST'])]
    public function models(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $back = $this->back($request);

        if (!$this->tokenIsValid($request)) {
            return $this->invalidToken($request, $back);
        }

        $chosen = $request->request->all('tier');

        try {
            foreach (AiTier::cases() as $tier) {
                $choice = $chosen[$tier->value] ?? '';

                if (\is_string($choice) && '' !== $choice && $choice !== $this->tiers->choiceOf($tier)) {
                    $this->tiers->assign($tier, $choice);
                }
            }
        } catch (AiExceptionInterface|\InvalidArgumentException $refusal) {
            $this->flash->add($request, 'danger', $refusal instanceof AiExceptionInterface ? $this->failures->explain($refusal, $request->getLocale()) : $this->translator->trans($refusal->getMessage()));

            return $back;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, 'AI ranges updated from the setup');
        $this->flash->add($request, 'success', $this->translator->trans('The ranges were saved.'));

        return $back;
    }

    /**
     * Step 3: the monthly budget of a feature that talks to visitors. Everything else the merchant set on the
     * feature stays as it is.
     */
    #[Route('/budget', name: 'budget', methods: ['POST'])]
    public function budget(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $usageId = $request->request->getString('usage_id');
        $back = new RedirectResponse($this->urls->generate(self::SETUP_ROUTE, ['usage' => $usageId]));

        if (!$this->tokenIsValid($request)) {
            return $this->invalidToken($request, $back);
        }

        $row = 1 === preg_match('/^'.self::ID.'$/', $usageId) ? $this->usages->row($usageId) : null;
        $budget = str_replace(',', '.', trim($request->request->getString('monthly_budget')));

        if (null === $row) {
            return new RedirectResponse($this->urls->generate(self::SETUP_ROUTE));
        }

        if (1 !== preg_match('/^\d{1,9}(\.\d{1,2})?$/', $budget) || 1 !== preg_match('/[1-9]/', $budget)) {
            $this->flash->add($request, 'danger', $this->translator->trans('Enter an amount in US dollars above zero, for example 10.'));

            return $back;
        }

        try {
            $this->usages->save(
                $usageId,
                $this->usages->isSwitchedOn($usageId),
                $this->usages->chosenTierOf($usageId),
                $budget,
                $this->usages->pinnedChoiceOf($usageId),
                (bool) $row['notice_enabled'],
                (bool) $row['provenance_mark_enabled'],
            );
        } catch (AiExceptionInterface|\InvalidArgumentException $refusal) {
            $this->flash->add($request, 'danger', $refusal instanceof AiExceptionInterface ? $this->failures->explain($refusal, $request->getLocale()) : $this->translator->trans($refusal->getMessage()));

            return $back;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('AI usage %s budget set from the setup', $usageId));
        $this->flash->add($request, 'success', $this->translator->trans('The monthly budget was saved.'));

        return $back;
    }

    private function test(Request $request, int $id, RedirectResponse $back, bool $justSaved): Response
    {
        $connection = $this->connections->find($id);

        if (null === $connection) {
            return $back;
        }

        $adminUser = $this->securityContext->getAdminUser();

        if (!$this->testLimiter->create($adminUser instanceof UserInterface ? $adminUser->getUsername() : null)->consume()->isAccepted()) {
            $this->flash->add($request, 'warning', $this->translator->trans('Too many tests in a row. Try again in a few minutes.'));

            return $back;
        }

        try {
            $error = $this->connections->test($connection);
        } catch (\Throwable $failure) {
            $this->errors->fail($this->translator->trans('AI connection test'), $failure);

            return $back;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('AI connection %s tested', (string) $connection->getTitle()), $id);

        if (null === $error) {
            $this->flash->add($request, 'success', $this->translator->trans($justSaved ? 'The connection was saved and the provider answered: it works.' : 'The provider answered: the connection works.'));

            return $back;
        }

        $explained = $this->failures->explainCheckError($error, $request->getLocale());
        $this->flash->add($request, 'danger', $justSaved
            ? $this->translator->trans('The connection was saved, but the test failed: %error%', ['%error%' => $explained])
            : $this->translator->trans('The test failed: %error%', ['%error%' => $explained]));

        return $back;
    }

    /**
     * Back to the page, still pointing at the feature the merchant came for.
     */
    private function back(Request $request): RedirectResponse
    {
        $usage = $request->request->getString('usage');

        return new RedirectResponse($this->urls->generate(self::SETUP_ROUTE, 1 === preg_match('/^'.self::ID.'$/', $usage) ? ['usage' => $usage] : []));
    }

    private function invalidToken(Request $request, RedirectResponse $back): Response
    {
        $this->flash->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

        return $back;
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
