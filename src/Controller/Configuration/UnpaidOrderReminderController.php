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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Order\Reminder\InvalidReminderScheduleException;
use Thelia\Domain\Order\Reminder\UnpaidOrderReminderSchedule;
use Thelia\Domain\Order\Reminder\UnpaidOrderReminderSettings;
use Thelia\Domain\Order\Reminder\UnpaidOrderReminderStep;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The reminder schedule of unpaid orders: up to a handful of steps counted from the
 * creation of the order, each sending a message or, last, cancelling the order, and the
 * payment modules whose orders are left alone. Applied by the order:remind-unpaid
 * command the host schedules.
 */
#[Route('/admin/configuration/unpaid-order-reminder', name: 'admin.unpaid-order-reminder.')]
final class UnpaidOrderReminderController
{
    private const RESOURCE = AdminResources::CONFIG;
    private const EDIT_ROUTE = 'admin.unpaid-order-reminder.edit';
    private const TEMPLATE = '@BackOfficeDefaultTwig/configuration/unpaid-order-reminder/edit.html.twig';

    /**
     * Rows the page offers; empty ones are ignored. The schedule itself accepts more.
     */
    private const ROWS = 5;

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly UnpaidOrderReminderSettings $settings,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[Route('', name: 'edit', methods: ['GET'])]
    public function edit(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $steps = array_map(
            static fn (UnpaidOrderReminderStep $step): array => ['delay' => (string) $step->delayInHours, 'action' => $step->messageCode ?? UnpaidOrderReminderStep::CANCELLATION],
            $this->settings->schedule()->steps(),
        );

        return $this->render($steps, $this->settings->excludedPaymentModuleCodes());
    }

    #[Route('', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (TokenAuthenticationException) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $steps = [];

        foreach ((array) $request->request->all('steps') as $row) {
            $delay = trim((string) ($row['delay'] ?? ''));
            $action = trim((string) ($row['action'] ?? ''));

            if ('' !== $delay || '' !== $action) {
                $steps[] = ['delay' => $delay, 'action' => $action];
            }
        }

        $knownModules = $this->paymentModuleCodes();
        $excluded = array_values(array_intersect(array_map('strval', (array) $request->request->all('excluded_modules')), $knownModules));

        try {
            $schedule = UnpaidOrderReminderSchedule::fromSetting(implode(',', array_map(static fn (array $step): string => $step['delay'].':'.$step['action'], $steps)));
        } catch (InvalidReminderScheduleException $exception) {
            return $this->render($steps, $excluded, $exception->getMessage());
        }

        $this->settings->save($schedule, $excluded);
        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Unpaid order reminder schedule set to "%s"', $schedule->toSetting()));
        $session = $this->requestStack->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $this->translator->trans('The reminder schedule has been saved.'));
        }

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE));
    }

    /**
     * @param list<array{delay: string, action: string}> $steps
     * @param list<string>                               $excludedModules
     */
    private function render(array $steps, array $excludedModules, ?string $error = null): Response
    {
        $session = $this->requestStack->getSession();
        $locale = method_exists($session, 'getAdminEditionLang') ? (string) $session->getAdminEditionLang()->getLocale() : 'en_US';

        return new Response($this->twig->render(self::TEMPLATE, [
            'steps' => array_pad($steps, max(self::ROWS, \count($steps)), ['delay' => '', 'action' => '']),
            'messages' => array_map(
                static fn ($message): array => ['code' => (string) $message->getName(), 'title' => (string) ($message->setLocale($locale)->getTitle() ?: $message->getName())],
                iterator_to_array(MessageQuery::create()->orderByName()->find(), false),
            ),
            'payment_modules' => $this->paymentModuleCodes(),
            'excluded_modules' => $excludedModules,
            'error' => $error,
            'token' => $this->tokens->assignToken(),
        ]));
    }

    /**
     * @return list<string>
     */
    private function paymentModuleCodes(): array
    {
        return array_values(array_map('strval', ModuleQuery::create()
            ->filterByType(BaseModule::PAYMENT_MODULE_TYPE)
            ->orderByCode()
            ->select(['Code'])
            ->find()
            ->toArray()));
    }
}
