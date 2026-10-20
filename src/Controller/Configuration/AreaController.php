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

use BackOfficeDefaultTwigBundle\Form\Configuration\AreaType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\I18n\CountryStateProvider;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Area\AreaAddCountryEvent;
use Thelia\Core\Event\Area\AreaRemoveCountryEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Area;
use Thelia\Model\AreaQuery;
use Thelia\Model\AreaZipCodeRuleQuery;
use Thelia\Model\CountryArea;
use Thelia\Model\CountryAreaQuery;
use Thelia\Model\CountryQuery;
use Thelia\Model\Event\AreaEvent;
use Thelia\Model\LangQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin/configuration/shipping_configuration', name: 'admin.configuration.shipping-configuration.')]
final class AreaController
{
    private const RESOURCE = AdminResources::AREA;
    private const LIST_ROUTE = 'admin.configuration.shipping-configuration.default';
    private const EDIT_ROUTE = 'admin.configuration.shipping-configuration.update.view';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/area/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/area/edit.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly EventDispatcherInterface $events,
        private readonly CountryStateProvider $countryStates,
        private readonly TokenProvider $tokens,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $locale = $request->getLocale();
        $sort = ListSort::fromRequest($request, ['id', 'name', 'postage'], 'name');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = AreaQuery::create();
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            'postage' => $query->orderByPostage($criteria),
            default => $query->orderByName($criteria),
        };
        $areas = $query->find();
        $rows = [];
        foreach ($areas as $area) {
            \assert($area instanceof Area);
            $rows[] = $this->areaToRow($area, $locale);
        }

        $createForm = $this->formFactory->createNamed('thelia_area_create', AreaType::class, null, []);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'create_form' => $createForm->createView(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(): Response
    {
        $form = $this->formFactory->createNamed('thelia_area_create', AreaType::class, null, []);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::AREA_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Shipping zone creation',
            successRoute: self::EDIT_ROUTE,
            successParametersResolver: static function (AreaEvent $event): array {
                $area = $event->getModel();
                \assert($area instanceof Area);

                return ['area_id' => (int) $area->getId()];
            },
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
            describeForLog: static function (AreaEvent $event): array {
                $area = $event->getModel();
                \assert($area instanceof Area);

                return [
                    \sprintf('Shipping zone "%s" (ID %d) created', (string) $area->getName(), (int) $area->getId()),
                    (int) $area->getId(),
                ];
            },
        );
    }

    #[Route('/update/{area_id}', name: 'update.view', methods: ['GET'], requirements: ['area_id' => '\d+'])]
    public function updateView(int $area_id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $area = AreaQuery::create()->findPk($area_id);
        if ($area === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $modificationForm = $this->formFactory->createNamed(
            'thelia_area_modification',
            AreaType::class,
            ['area_id' => $area->getId(), 'name' => $area->getName()],
            ['include_id' => true],
        );

        $zipCodeRulesSupported = class_exists(AreaZipCodeRuleQuery::class);
        $zoneCountries = $zipCodeRulesSupported ? $this->zoneCountries((int) $area->getId()) : [];
        $zipCodeRules = $this->zipCodeRules((int) $area->getId(), $zoneCountries);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'area' => $area,
            'area_id' => (int) $area->getId(),
            'form' => $modificationForm->createView(),
            'countries_in_area' => $this->collectAssignments((int) $area->getId()),
            'countries_data_url' => $this->urls->generate('admin.configuration.countries.data'),
            'unassigned_country_ids' => $this->unassignedCountryIds(),
            // The postal code rules come with a core that knows them.
            'zip_code_rules_supported' => $zipCodeRulesSupported,
            'zip_code_rules' => $zipCodeRules,
            'zip_code_rules_unformatted_countries' => $this->countriesWithoutZipCodeFormat($zipCodeRules, $zoneCountries),
            'zone_countries' => $zoneCountries,
        ]));
    }

    #[Route('/save/{area_id}', name: 'save', methods: ['POST'], requirements: ['area_id' => '\d+'])]
    public function processUpdate(int $area_id): Response
    {
        $form = $this->formFactory->createNamed('thelia_area_modification', AreaType::class, null, [
            'include_id' => true,
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::AREA_UPDATE,
            eventFactory: fn (FormInterface $validated) => $this->updateEvent($validated),
            actionLabel: 'Shipping zone update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['area_id' => $area_id],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['area_id' => $area_id])),
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $area = AreaQuery::create()->findPk((int) ($request->query->get('area_id') ?? $request->request->get('area_id', 0)));
        if ($area === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new AreaEvent($area),
            eventName: TheliaEvents::AREA_DELETE,
            actionLabel: 'Shipping zone deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/country/add', name: 'country.add', methods: ['POST'])]
    public function addCountry(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        $areaId = (int) $request->request->get('area_id', 0);
        $area = AreaQuery::create()->findPk($areaId);
        if ($area === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $countryIds = (array) $request->request->all('country_id');
        if (\count($countryIds) === 0) {
            return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['area_id' => $areaId]));
        }

        $event = new AreaAddCountryEvent($area, $countryIds);
        $this->events->dispatch($event, TheliaEvents::AREA_ADD_COUNTRY);

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['area_id' => $areaId]));
    }

    #[Route('/country/remove', name: 'country.remove', methods: ['POST'])]
    public function removeCountry(Request $request): Response
    {
        $areaId = (int) $request->request->get('area_id', 0);
        $area = AreaQuery::create()->findPk($areaId);
        if ($area === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $stateRaw = $request->request->get('state_id');
        $stateId = ($stateRaw === null || $stateRaw === '' || (int) $stateRaw === 0) ? null : (int) $stateRaw;
        $event = new AreaRemoveCountryEvent(
            $area,
            [(int) $request->request->get('country_id', 0)],
            $stateId,
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::AREA_REMOVE_COUNTRY,
            actionLabel: 'Remove country from shipping zone',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['area_id' => $areaId],
        );
    }

    #[Route('/countries/remove', name: 'countries.remove', methods: ['POST'])]
    public function removeCountries(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        $areaId = (int) $request->request->get('area_id', 0);
        $area = AreaQuery::create()->findPk($areaId);
        if ($area === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $countryIds = (array) $request->request->all('country_id');
        foreach ($countryIds as $countryId) {
            $parts = explode('-', (string) $countryId);
            $countryPk = (int) $parts[0];
            $statePk = isset($parts[1]) && (int) $parts[1] !== 0 ? (int) $parts[1] : null;

            $event = new AreaRemoveCountryEvent($area, [$countryPk], $statePk);
            $this->events->dispatch($event, TheliaEvents::AREA_REMOVE_COUNTRY);
        }

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['area_id' => $areaId]));
    }

    /** @return array<int, array{country_id: int, state_id: int|null, label: string}> */
    private function collectAssignments(int $areaId): array
    {
        $rows = CountryAreaQuery::create()->filterByAreaId($areaId)->find();
        $assignments = [];
        foreach ($rows as $countryArea) {
            \assert($countryArea instanceof CountryArea);
            $countryId = (int) $countryArea->getCountryId();
            $stateId = $countryArea->getStateId() !== null ? (int) $countryArea->getStateId() : null;
            $assignments[] = [
                'country_id' => $countryId,
                'state_id' => $stateId,
                'label' => $this->describeAssignment($countryId, $stateId),
            ];
        }

        return $assignments;
    }

    private function describeAssignment(int $countryId, ?int $stateId): string
    {
        $locale = $this->defaultLocale();
        $countryTitles = $this->countryStates->countryTitles($locale);
        if (!isset($countryTitles[$countryId])) {
            return '';
        }

        $label = $countryTitles[$countryId];
        if ($stateId !== null) {
            $stateTitles = $this->countryStates->stateTitles($locale);
            if (isset($stateTitles[$stateId])) {
                $label .= ' - '.$stateTitles[$stateId];
            }
        }

        return $label;
    }

    /**
     * @param array<int, string> $zoneCountries
     *
     * @return list<array{id: int, pattern: string, excluded: bool, country_id: int|null, country: string|null, outside_zone: bool}>
     */
    private function zipCodeRules(int $areaId, array $zoneCountries): array
    {
        // Checked here too: the static analysis of an older core reads this method alone.
        if (!class_exists(AreaZipCodeRuleQuery::class)) {
            return [];
        }

        $rules = [];

        foreach (AreaZipCodeRuleQuery::create()->filterByAreaId($areaId)->orderByExcluded()->orderByPattern()->find() as $rule) {
            $countryId = $rule->getCountryId();
            $rules[] = [
                'id' => (int) $rule->getId(),
                'pattern' => (string) $rule->getPattern(),
                'excluded' => (bool) $rule->getExcluded(),
                'country_id' => null === $countryId ? null : (int) $countryId,
                'country' => null === $countryId ? null : ($zoneCountries[$countryId] ?? null),
                // A rule written for a country the zone no longer holds applies to no one
                // until the country comes back: it must not read "every country".
                'outside_zone' => null !== $countryId && !isset($zoneCountries[$countryId]),
            ];
        }

        return $rules;
    }

    /**
     * The countries of the zone a rule applies to where the address forms check no postal
     * code format (none set, or the postal code not required): a customer types any code
     * there, or none. An unknown code is accepted by every rule and a mistyped one matches
     * no exclusion, which the merchant has to know before relying on one.
     *
     * @param list<array{id: int, pattern: string, excluded: bool, country_id: int|null, country: string|null, outside_zone: bool}> $rules
     * @param array<int, string>                                                                                               $zoneCountries
     *
     * @return list<string>
     */
    private function countriesWithoutZipCodeFormat(array $rules, array $zoneCountries): array
    {
        $countryIds = [];

        foreach ($rules as $rule) {
            if (null === $rule['country_id']) {
                $countryIds += array_fill_keys(array_keys($zoneCountries), true);
            } elseif (isset($zoneCountries[$rule['country_id']])) {
                $countryIds[$rule['country_id']] = true;
            }
        }

        if ([] === $countryIds) {
            return [];
        }

        $titles = [];

        foreach (CountryQuery::create()->filterById(array_keys($countryIds))->find() as $country) {
            if ('' === trim((string) $country->getZipCodeFormat()) || 0 === (int) $country->getNeedZipCode()) {
                $titles[] = $zoneCountries[(int) $country->getId()];
            }
        }
        sort($titles);

        return $titles;
    }

    /**
     * The countries of the zone a rule can be written for, once each even when the zone
     * holds several of their states.
     *
     * @return array<int, string>
     */
    private function zoneCountries(int $areaId): array
    {
        $countryTitles = $this->countryStates->countryTitles($this->defaultLocale());
        $countries = [];

        foreach (CountryAreaQuery::create()->filterByAreaId($areaId)->find() as $countryArea) {
            $countryId = (int) $countryArea->getCountryId();
            $countries[$countryId] = $countryTitles[$countryId] ?? (string) $countryId;
        }
        asort($countries);

        return $countries;
    }

    /** @return list<int> */
    private function unassignedCountryIds(): array
    {
        $assigned = CountryAreaQuery::create()->select(['CountryId'])->find()->getData();
        $assignedIds = array_unique(array_map(static fn ($v): int => (int) $v, $assigned));

        $all = CountryQuery::create()->select(['Id'])->find()->getData();
        $unassigned = [];
        foreach ($all as $id) {
            if (!\in_array((int) $id, $assignedIds, true)) {
                $unassigned[] = (int) $id;
            }
        }

        return $unassigned;
    }

    /** @return array<string, mixed> */
    private function areaToRow(Area $area, string $locale): array
    {
        $id = (int) $area->getId();
        $countries = [];
        $countryAreas = CountryAreaQuery::create()->filterByAreaId($id)->limit(6)->find();
        $more = max(0, CountryAreaQuery::create()->filterByAreaId($id)->count() - $countryAreas->count());
        $countryTitles = $this->countryStates->countryTitles($locale);
        foreach ($countryAreas as $countryArea) {
            \assert($countryArea instanceof CountryArea);
            $countryId = (int) $countryArea->getCountryId();
            if (isset($countryTitles[$countryId])) {
                $countries[] = $countryTitles[$countryId];
            }
        }

        $modules = [];
        foreach ($area->getAreaDeliveryModules() as $adm) {
            $module = $adm->getModule();
            if ($module !== null) {
                $module->setLocale($locale);
                $modules[] = $module->getCode().' - '.(string) $module->getTitle();
            }
        }

        $actions = [
            new RowAction(kind: 'edit', label: $this->translator->trans('Edit'), href: $this->urls->generate(self::EDIT_ROUTE, ['area_id' => $id]), grantedAttribute: AccessManager::UPDATE, grantedSubject: self::RESOURCE),
            new RowAction(kind: 'delete', label: $this->translator->trans('Delete'), modalTarget: '#shipping-configuration-delete-modal', grantedAttribute: AccessManager::DELETE, grantedSubject: self::RESOURCE, dataAttributes: ['area-id' => $id, 'area-label' => (string) $area->getName()]),
        ];

        return [
            'id' => $id,
            'name' => (string) $area->getName(),
            'countries' => implode(', ', $countries),
            'countries_more' => $more,
            'modules' => implode(', ', $modules),
            '_actions' => $actions,
        ];
    }

    private function createEvent(FormInterface $validated): AreaEvent
    {
        $data = $validated->getData() ?? [];
        $area = new Area();
        $area->setName((string) ($data['name'] ?? ''));

        return new AreaEvent($area);
    }

    private function updateEvent(FormInterface $validated): AreaEvent
    {
        $data = $validated->getData() ?? [];
        $area = AreaQuery::create()->findPk((int) ($data['area_id'] ?? 0))
            ?? throw new \LogicException('Area not found');
        $area->setName((string) ($data['name'] ?? ''));

        return new AreaEvent($area);
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
