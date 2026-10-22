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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Shipping\DeliveryDate\Enum\DeliveryDateChoiceMode;
use Thelia\Domain\Shipping\DeliveryDate\Exception\InvalidDeliveryDateSettingsException;
use Thelia\Domain\Shipping\DeliveryDate\Service\ClosedWeekdays;
use Thelia\Domain\Shipping\DeliveryDate\Service\DeliveryDateCalendar;
use Thelia\Domain\Shipping\DeliveryDate\Service\DeliveryDateSettings;
use Thelia\Model\DeliveryClosureQuery;
use Thelia\Model\DeliverySlotI18nQuery;
use Thelia\Model\DeliverySlotQuery;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The delivery dates the buyer can pick: the closed days of the shop, then per carrier the
 * shape of the choice, the delay, the horizon, its own closed days, its slots and closures.
 *
 * Every rule a setting has to follow lives in the core (DeliveryDateSettings): a refusal
 * reaches the merchant as a flash message, never as a check duplicated here. Placed orders
 * are never touched — they keep the day and the hours they were placed with.
 */
#[Route('/admin/configuration/delivery-dates', name: 'admin.delivery-date.')]
final class DeliveryDateController
{
    private const RESOURCE = AdminResources::DELIVERY_DATE;
    private const LIST_ROUTE = 'admin.delivery-date.list';
    private const CARRIER_ROUTE = 'admin.delivery-date.carrier';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/delivery-date/list.html.twig';
    private const CARRIER_TEMPLATE = '@BackOfficeDefaultTwig/configuration/delivery-date/carrier.html.twig';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminFormErrorRenderer $errorRenderer,
        private readonly AdminLogger $adminLogger,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly EditLocaleResolver $editLocale,
        private readonly DeliveryDateSettings $settings,
        private readonly DeliveryDateCalendar $calendar,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $locale = $request->getLocale();
        $carriers = [];

        foreach (ModuleQuery::create()->filterByType(BaseModule::DELIVERY_MODULE_TYPE)->orderByPosition()->find() as $module) {
            $carriers[] = [
                'id' => (int) $module->getId(),
                'code' => $module->getCode(),
                'title' => $module->setLocale($locale)->getTitle() ?: $module->getCode(),
                'active' => (bool) $module->getActivate(),
                'accepts_dates' => [] !== $this->calendar->acceptedChoiceModesOf($module),
                'choice_mode' => $this->calendar->choiceModeOf($module)->value,
            ];
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'carriers' => $carriers,
            'weekdays' => $this->weekdays($locale),
            'shop_closed_weekdays' => $this->settings->shopClosedWeekdays(),
            'shop_closures' => $this->closureRows(null),
        ]));
    }

    #[Route('/shop/save', name: 'shop.save', methods: ['POST'])]
    public function saveShop(Request $request): Response
    {
        return $this->write(
            $request,
            'Shop delivery days update',
            fn () => $this->settings->saveShopClosedWeekdays($this->weekdayList($request)),
            $this->urls->generate(self::LIST_ROUTE),
        );
    }

    #[Route('/carrier/{module_id}', name: 'carrier', methods: ['GET'], requirements: ['module_id' => '\d+'])]
    public function carrier(int $module_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $module = $this->deliveryModule($module_id);

        if (null === $module) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $editLocale = $editLang->getLocale() ?? 'en_US';
        $rule = $this->settings->ruleOf($module);
        $slots = $this->settings->slotsOf($module);
        $titles = [];

        if ([] !== $slots) {
            foreach (DeliverySlotI18nQuery::create()->filterById(array_map(static fn ($slot): int => (int) $slot->getId(), $slots))->filterByLocale($editLocale)->find() as $title) {
                $titles[(int) $title->getId()] = (string) $title->getTitle();
            }
        }

        return new Response($this->twig->render(self::CARRIER_TEMPLATE, [
            'module' => [
                'id' => (int) $module->getId(),
                'code' => $module->getCode(),
                'title' => $module->setLocale($request->getLocale())->getTitle() ?: $module->getCode(),
            ],
            'accepted_modes' => array_map(static fn (DeliveryDateChoiceMode $mode): string => $mode->value, $this->calendar->acceptedChoiceModesOf($module)),
            'effective_mode' => $this->calendar->choiceModeOf($module)->value,
            'rule' => [
                'choice_mode' => $rule?->getChoiceMode() ?? DeliveryDateChoiceMode::None->value,
                'minimum_delay_days' => $rule?->getMinimumDelayDays() ?? 0,
                'horizon_days' => $rule?->getHorizonDays() ?? 30,
                'follows_shop' => null === $rule || null === $rule->getClosedWeekdays(),
                'closed_weekdays' => null === $rule?->getClosedWeekdays() ? $this->settings->shopClosedWeekdays() : ClosedWeekdays::parse($rule->getClosedWeekdays()),
            ],
            'weekdays' => $this->weekdays($request->getLocale()),
            'slots' => array_map(static fn ($slot): array => [
                'id' => (int) $slot->getId(),
                'start' => (string) $slot->getStartTime('H:i'),
                'end' => (string) $slot->getEndTime('H:i'),
                'capacity' => null === $slot->getCapacity() ? null : (int) $slot->getCapacity(),
                'title' => $titles[(int) $slot->getId()] ?? '',
            ], $slots),
            'closures' => $this->closureRows($module),
            'edit_language_id' => (int) $editLang->getId(),
            'edit_locale' => $editLocale,
        ]));
    }

    #[Route('/carrier/{module_id}/save', name: 'carrier.save', methods: ['POST'], requirements: ['module_id' => '\d+'])]
    public function saveCarrier(int $module_id, Request $request): Response
    {
        $module = $this->deliveryModule($module_id);

        return $this->write(
            $request,
            'Carrier delivery dates update',
            function () use ($module, $request): void {
                $this->settings->saveRule(
                    $module ?? throw new \InvalidArgumentException($this->translator->trans('No such carrier.')),
                    DeliveryDateChoiceMode::tryFrom((string) $request->request->get('choice_mode')) ?? DeliveryDateChoiceMode::None,
                    (int) $request->request->get('minimum_delay_days', 0),
                    (int) $request->request->get('horizon_days', 0),
                    $request->request->getBoolean('follows_shop') ? null : $this->weekdayList($request),
                );
            },
            $this->carrierUrl($module_id, $request),
            $module_id,
        );
    }

    #[Route('/carrier/{module_id}/slot/add', name: 'slot.add', methods: ['POST'], requirements: ['module_id' => '\d+'])]
    public function addSlot(int $module_id, Request $request): Response
    {
        $module = $this->deliveryModule($module_id);

        return $this->write(
            $request,
            'Delivery slot creation',
            fn () => $this->settings->addSlot(
                $module ?? throw new \InvalidArgumentException($this->translator->trans('No such carrier.')),
                (string) $request->request->get('start_time'),
                (string) $request->request->get('end_time'),
                $this->capacity($request),
                $this->title($request),
            ),
            $this->carrierUrl($module_id, $request),
            $module_id,
        );
    }

    #[Route('/slot/{slot_id}/save', name: 'slot.save', methods: ['POST'], requirements: ['slot_id' => '\d+'])]
    public function saveSlot(int $slot_id, Request $request): Response
    {
        $slot = DeliverySlotQuery::create()->findPk($slot_id);
        $moduleId = (int) $slot?->getModuleId();

        return $this->write(
            $request,
            'Delivery slot update',
            fn () => $this->settings->updateSlot(
                $slot ?? throw new \InvalidArgumentException($this->translator->trans('No such delivery slot.')),
                (string) $request->request->get('start_time'),
                (string) $request->request->get('end_time'),
                $this->capacity($request),
                $this->title($request),
            ),
            null === $slot ? $this->urls->generate(self::LIST_ROUTE) : $this->carrierUrl($moduleId, $request),
            $slot_id,
        );
    }

    #[Route('/slot/{slot_id}/delete', name: 'slot.delete', methods: ['POST'], requirements: ['slot_id' => '\d+'])]
    public function deleteSlot(int $slot_id, Request $request): Response
    {
        $slot = DeliverySlotQuery::create()->findPk($slot_id);

        return $this->write(
            $request,
            'Delivery slot deletion',
            function () use ($slot): void {
                if (null !== $slot) {
                    $this->settings->deleteSlot($slot);
                }
            },
            null === $slot ? $this->urls->generate(self::LIST_ROUTE) : $this->carrierUrl((int) $slot->getModuleId(), $request),
            $slot_id,
        );
    }

    #[Route('/closure/add', name: 'closure.add', methods: ['POST'])]
    public function addClosure(Request $request): Response
    {
        $moduleId = (int) $request->request->get('module_id', 0);
        $module = 0 === $moduleId ? null : $this->deliveryModule($moduleId);

        return $this->write(
            $request,
            'Delivery closure creation',
            fn () => $this->settings->addClosure(
                0 === $moduleId ? null : ($module ?? throw new \InvalidArgumentException($this->translator->trans('No such carrier.'))),
                (string) $request->request->get('start_date'),
                (string) $request->request->get('end_date'),
                (string) $request->request->get('label', ''),
            ),
            0 === $moduleId ? $this->urls->generate(self::LIST_ROUTE) : $this->carrierUrl($moduleId, $request),
            0 === $moduleId ? null : $moduleId,
        );
    }

    #[Route('/closure/{closure_id}/delete', name: 'closure.delete', methods: ['POST'], requirements: ['closure_id' => '\d+'])]
    public function deleteClosure(int $closure_id, Request $request): Response
    {
        $closure = DeliveryClosureQuery::create()->findPk($closure_id);
        $moduleId = $closure?->getModuleId();

        return $this->write(
            $request,
            'Delivery closure deletion',
            function () use ($closure): void {
                if (null !== $closure) {
                    $this->settings->deleteClosure($closure);
                }
            },
            null === $moduleId ? $this->urls->generate(self::LIST_ROUTE) : $this->carrierUrl((int) $moduleId, $request),
            $closure_id,
        );
    }

    /**
     * The one road every write takes: permission, token read from the body, the write
     * itself, the log, and the way back to the screen — with the refusal as a flash message.
     */
    private function write(Request $request, string $actionLabel, callable $write, string $backTo, ?int $resourceId = null): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
            $write();
            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, $actionLabel, $resourceId);

            $session = $request->getSession();
            if ($session instanceof Session) {
                $session->getFlashBag()->add('success', $this->translator->trans('Delivery dates saved.'));
            }
        } catch (\Throwable $exception) {
            // The merchant reads the refusals written for them — a rule the setting breaks, a
            // carrier or slot that is gone. Anything else is a fault of the shop: its wording is
            // for the log, which the renderer writes, and the screen says it did not save.
            $shown = $exception instanceof InvalidDeliveryDateSettingsException || $exception instanceof \InvalidArgumentException
                ? $exception->getMessage()
                : $this->translator->trans('The delivery dates could not be saved. Please try again.');

            $this->errorRenderer->refuse($this->translator->trans($actionLabel), $shown, null, $exception);
        }

        return new RedirectResponse($backTo);
    }

    private function deliveryModule(int $moduleId): ?Module
    {
        return ModuleQuery::create()->filterByType(BaseModule::DELIVERY_MODULE_TYPE)->findPk($moduleId);
    }

    private function carrierUrl(int $moduleId, Request $request): string
    {
        $parameters = ['module_id' => $moduleId];
        $editLanguageId = (int) $request->request->get('edit_language_id', 0);

        if ($editLanguageId > 0) {
            $parameters['edit_language_id'] = $editLanguageId;
        }

        return $this->urls->generate(self::CARRIER_ROUTE, $parameters);
    }

    /**
     * @return list<int>
     */
    private function weekdayList(Request $request): array
    {
        $days = $request->request->all()['closed_weekdays'] ?? [];

        return array_values(array_map('intval', \is_array($days) ? $days : []));
    }

    private function capacity(Request $request): ?int
    {
        $capacity = trim((string) $request->request->get('capacity', ''));

        return '' === $capacity ? null : (int) $capacity;
    }

    /**
     * @return array<string, string>
     */
    private function title(Request $request): array
    {
        $locale = (string) $request->request->get('locale', '');

        return '' === $locale ? [] : [$locale => (string) $request->request->get('title', '')];
    }

    /**
     * The days of the week in the language of the back office, Monday first as in ISO 8601.
     *
     * @return array<int, string>
     */
    private function weekdays(string $locale): array
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'EEEE');
        $days = [];

        for ($day = 1; $day <= 7; ++$day) {
            // 2024-01-01 was a Monday.
            $days[$day] = mb_convert_case((string) $formatter->format(new \DateTimeImmutable(\sprintf('2024-01-%02d', $day))), \MB_CASE_TITLE);
        }

        return $days;
    }

    /**
     * @return list<array{id: int, start: string, end: string, label: ?string, past: bool}>
     */
    private function closureRows(?Module $module): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        return array_map(static fn ($closure): array => [
            'id' => (int) $closure->getId(),
            'start' => (string) $closure->getStartDate('Y-m-d'),
            'end' => (string) $closure->getEndDate('Y-m-d'),
            'label' => $closure->getLabel(),
            'past' => (string) $closure->getEndDate('Y-m-d') < $today,
        ], $this->settings->closuresOf($module));
    }
}
