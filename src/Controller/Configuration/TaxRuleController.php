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

use BackOfficeDefaultTwigBundle\Form\Tax\TaxType;
use BackOfficeDefaultTwigBundle\Form\TaxRule\TaxRuleType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFailureMessage;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormValidator;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Configuration\DeliveryModulePostageTaxRules;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Tax\TaxRuleEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Shipping\Enum\PostageTaxStrategy;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Country;
use Thelia\Model\CountryQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\State;
use Thelia\Model\StateQuery;
use Thelia\Model\Tax;
use Thelia\Model\TaxQuery;
use Thelia\Model\TaxRule;
use Thelia\Model\TaxRuleCountryQuery;
use Thelia\Model\TaxRuleQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin/configuration/taxes_rules', name: 'admin.configuration.taxes-rules.')]
final class TaxRuleController
{
    private const RESOURCE = AdminResources::TAX;
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/tax-rule/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/tax-rule/edit.html.twig';
    private const LIST_ROUTE = 'admin.configuration.taxes-rules.list';
    private const UPDATE_ROUTE = 'admin.configuration.taxes-rules.update';
    private const CREATE_TAX_FORM_NAME = 'thelia_tax_creation';
    private const CREATE_TAX_RULE_FORM_NAME = 'thelia_tax_rule_creation';
    private const UPDATE_TAX_RULE_FORM_NAME = 'thelia_tax_rule_modification';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly AdminFormValidator $validator,
        private readonly AdminFormErrorRenderer $errorRenderer,
        private readonly AdminLogger $adminLogger,
        private readonly EventDispatcherInterface $events,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly TokenProvider $tokens,
        private readonly EditLocaleResolver $editLocale,
        private readonly DeliveryModulePostageTaxRules $deliveryModuleTaxRules,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $locale = $request->getLocale();
        $taxSort = ListSort::fromRequest($request, ['id', 'title'], 'id', 'asc', 'tax_order', 'tax_direction');
        $taxRuleSort = ListSort::fromRequest($request, ['id', 'title', 'is_default'], 'id', 'asc', 'tax_rule_order', 'tax_rule_direction');

        $createTaxForm = $this->formFactory->createNamed(self::CREATE_TAX_FORM_NAME, TaxType::class, [
            'locale' => $locale,
        ], [
        ]);

        $createTaxRuleForm = $this->formFactory->createNamed(self::CREATE_TAX_RULE_FORM_NAME, TaxRuleType::class, [
            'locale' => $locale,
        ], [
        ]);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'tax_rows' => $this->taxRows($locale, $taxSort),
            'tax_rule_rows' => $this->taxRuleRows($locale, $taxRuleSort),
            'create_tax_form' => $createTaxForm->createView(),
            'create_tax_rule_form' => $createTaxRuleForm->createView(),
            'delivery_tax_rules' => $this->deliveryTaxRuleOptions($locale),
            'delivery_tax_rule_selected' => (int) ConfigQuery::read('taxrule_id_delivery_module', 0),
            'delivery_module_rows' => $this->deliveryModuleTaxRules->rows($locale),
            'postage_tax_strategies' => $this->postageTaxStrategyOptions(),
            'postage_tax_strategy_selected' => PostageTaxStrategy::fromShopConfiguration()->value,
            'tax_sort_field' => $taxSort->field,
            'tax_sort_direction' => $taxSort->direction,
            'tax_rule_sort_field' => $taxRuleSort->field,
            'tax_rule_sort_direction' => $taxRuleSort->direction,
        ]));
    }

    #[Route('/update/{tax_rule_id}', name: 'update', methods: ['GET'], requirements: ['tax_rule_id' => '\d+'])]
    public function update(int $tax_rule_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $taxRule = TaxRuleQuery::create()->findPk($tax_rule_id);

        if ($taxRule === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $taxRule->setLocale($locale);

        $form = $this->createUpdateForm([
            'id' => $taxRule->getId(),
            'locale' => $locale,
            'title' => $taxRule->getTitle(),
            'description' => $taxRule->getDescription(),
        ]);

        $taxRuleId = (int) ($taxRule->getId() ?? 0);
        $defaultCountryId = (int) (CountryQuery::create()->findOneByByDefault(1)?->getId() ?? 0);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'form' => $form->createView(),
            'tax_rule' => $taxRule,
            'edit_language_id' => (int) $editLang->getId(),
            'specification_rows' => $this->specificationRows($taxRuleId),
            'matrix_taxes' => $this->taxesByIdMap($locale),
            'matrix_countries_simple' => $this->countriesWithoutStates($locale),
            'matrix_countries_with_states' => $this->countriesWithStates($locale),
            'matrix_default_country_id' => $defaultCountryId,
            'matrix_initial_specification' => $this->buildSpecification($taxRuleId),
        ]));
    }

    #[Route('/add', name: 'add', methods: ['POST'])]
    public function add(): Response
    {
        $form = $this->formFactory->createNamed(self::CREATE_TAX_RULE_FORM_NAME, TaxRuleType::class, null, [
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::TAX_RULE_CREATE,
            eventFactory: $this->buildCreationEvent(...),
            actionLabel: 'Tax rule creation',
            successRoute: self::UPDATE_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
            successParametersResolver: $this->resolveCreatedTaxRuleId(...),
            describeForLog: $this->describeCreated(...),
        );
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $form = $this->createUpdateForm(null);
        $taxRuleId = (int) $request->request->get('tax_rule_id', 0);

        try {
            $validated = $this->validator->validate($form);
            $data = $validated->getData() ?? [];

            $event = new TaxRuleEvent();
            $event->setLocale((string) ($data['locale'] ?? ''));
            $event->setId((int) ($data['id'] ?? 0));
            $event->setTitle((string) ($data['title'] ?? ''));
            $event->setDescription((string) ($data['description'] ?? ''));

            $this->events->dispatch($event, TheliaEvents::TAX_RULE_UPDATE);

            $taxRule = $event->getTaxRule();
            if ($taxRule !== null) {
                $this->adminLogger->log(
                    self::RESOURCE,
                    AccessManager::UPDATE,
                    \sprintf('Tax rule %s (ID %d) modified', (string) $taxRule->getTitle(), (int) $taxRule->getId()),
                    (int) $taxRule->getId(),
                );
            }

            return new RedirectResponse($this->urls->generate(self::UPDATE_ROUTE, [
                'tax_rule_id' => $taxRule?->getId() ?? $taxRuleId,
            ]));
        } catch (\Throwable $exception) {
            $this->errorRenderer->fail(
                $this->translator->trans('Tax rule update'),
                $exception,
                $form,
            );

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }
    }

    #[Route('/saveTaxes', name: 'saveTaxes', methods: ['POST'])]
    public function saveTaxes(Request $request): JsonResponse
    {
        if ($this->access->check(self::RESOURCE, [], AccessManager::UPDATE) !== null) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('Access denied.')], 403);
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token'));
        } catch (TokenAuthenticationException $exception) {
            return new JsonResponse(['success' => false, 'message' => AdminFailureMessage::of($exception, $this->translator)], 403);
        }

        $taxRuleId = (int) $request->request->get('id', 0);
        $taxList = (string) $request->request->get('tax_list', '[]');
        $countryList = (string) $request->request->get('country_list', '[]');
        $countryDeletedList = (string) $request->request->get('country_deleted_list', '[]');

        try {
            $event = new TaxRuleEvent();
            $event->setId($taxRuleId);
            $event->setTaxList($taxList);
            $event->setCountryList($countryList);
            $event->setCountryDeletedList($countryDeletedList);

            $this->events->dispatch($event, TheliaEvents::TAX_RULE_TAXES_UPDATE);

            $taxRule = $event->getTaxRule();
            if ($taxRule !== null) {
                $this->adminLogger->log(
                    self::RESOURCE,
                    AccessManager::UPDATE,
                    \sprintf('Tax rule %s (ID %d) taxes updated', (string) $taxRule->getTitle(), (int) $taxRule->getId()),
                    (int) $taxRule->getId(),
                );
            }

            return new JsonResponse([
                'success' => true,
                'data' => $this->buildSpecification($taxRuleId),
            ]);
        } catch (\Throwable $exception) {
            return new JsonResponse(['success' => false, 'message' => AdminFailureMessage::of($exception, $this->translator)], 400);
        }
    }

    #[Route('/specs/{tax_rule_id}', name: 'get', methods: ['GET'], requirements: ['tax_rule_id' => '\d+'])]
    public function specs(int $tax_rule_id): JsonResponse
    {
        if ($this->access->check(self::RESOURCE, [], AccessManager::VIEW) !== null) {
            return new JsonResponse(['error' => 'denied'], 403);
        }

        return new JsonResponse($this->buildSpecification($tax_rule_id));
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $event = new TaxRuleEvent();
        $event->setId((int) ($request->query->get('tax_rule_id') ?? $request->request->get('tax_rule_id', 0)));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::TAX_RULE_DELETE,
            actionLabel: 'Tax rule deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/update/set_default/{tax_rule_id}', name: 'set-default', methods: ['POST'], requirements: ['tax_rule_id' => '\d+'])]
    public function setDefault(int $tax_rule_id, Request $request): Response
    {
        $event = new TaxRuleEvent();
        $event->setId($tax_rule_id);

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::TAX_RULE_SET_DEFAULT,
            actionLabel: 'Tax rule set default',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/delivery-modules', name: 'delivery.modules.update', methods: ['POST'])]
    public function updateDeliveryModulesTaxRule(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        ConfigQuery::write('taxrule_id_delivery_module', (string) (int) $request->request->get('delivery-module-tax-rule', 0));

        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
    }

    #[Route('/delivery-modules/postage', name: 'delivery.modules.postage.update', methods: ['POST'])]
    public function updatePostageTaxRulePerDeliveryModule(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        $submitted = $request->request->all('postage-tax-rule');
        $this->deliveryModuleTaxRules->save($submitted);

        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
    }

    #[Route('/postage-tax-strategy', name: 'postage.tax.strategy.update', methods: ['POST'])]
    public function updatePostageTaxStrategy(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        $strategy = PostageTaxStrategy::tryFrom((string) $request->request->get('postage-tax-strategy'));

        if (null === $strategy) {
            $this->errorRenderer->refuse(
                $this->translator->trans('Postage tax breakdown'),
                $this->translator->trans('Unknown postage tax strategy.'),
            );

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        ConfigQuery::write(PostageTaxStrategy::CONFIG_KEY, $strategy->value);

        $this->adminLogger->log(
            self::RESOURCE,
            AccessManager::UPDATE,
            \sprintf('Postage tax strategy set to %s', $strategy->value),
        );

        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    private function postageTaxStrategyOptions(): array
    {
        return [
            [
                'value' => PostageTaxStrategy::SINGLE_RULE->value,
                'label' => $this->translator->trans('One rule for the whole postage'),
                'description' => $this->translator->trans('The rule the delivery module resolved is applied to the whole postage. This is what Thelia has always done.'),
            ],
            [
                'value' => PostageTaxStrategy::PRO_RATA->value,
                'label' => $this->translator->trans('Split over the goods'),
                'description' => $this->translator->trans('The postage is shared between the tax rules of the cart, in proportion to the untaxed value each one accounts for.'),
            ],
            [
                'value' => PostageTaxStrategy::HIGHEST_RATE->value,
                'label' => $this->translator->trans('Highest rate of the cart'),
                'description' => $this->translator->trans('The whole postage follows the highest rate the cart carries.'),
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function createUpdateForm(?array $data): FormInterface
    {
        return $this->formFactory->createNamed(self::UPDATE_TAX_RULE_FORM_NAME, TaxRuleType::class, $data, [
            'include_id' => true,
        ]);
    }

    private function buildCreationEvent(FormInterface $validated): TaxRuleEvent
    {
        $data = $validated->getData() ?? [];
        $event = new TaxRuleEvent();
        $event->setLocale((string) ($data['locale'] ?? ''));
        $event->setTitle((string) ($data['title'] ?? ''));
        $event->setDescription((string) ($data['description'] ?? ''));

        return $event;
    }

    /**
     * @return array<string, scalar>
     */
    private function resolveCreatedTaxRuleId(TaxRuleEvent $event): array
    {
        $taxRule = $event->getTaxRule();
        if ($taxRule === null) {
            throw new \LogicException($this->translator->trans('No tax rule was created.'));
        }

        return ['tax_rule_id' => (int) $taxRule->getId()];
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function describeCreated(TaxRuleEvent $event): array
    {
        $taxRule = $event->getTaxRule();
        if ($taxRule === null) {
            throw new \LogicException($this->translator->trans('No tax rule was created.'));
        }

        return [
            \sprintf('Tax rule %s (ID %d) created', (string) $taxRule->getTitle(), (int) $taxRule->getId()),
            (int) $taxRule->getId(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function taxRows(string $locale, ListSort $sort): array
    {
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = TaxQuery::create();
        match ($sort->field) {
            'title' => $query->useTaxI18nQuery(null, Criteria::LEFT_JOIN)->filterByLocale($locale)->orderByTitle($criteria)->endUse(),
            default => $query->orderById($criteria),
        };

        $rows = [];
        foreach ($query->find() as $tax) {
            \assert($tax instanceof Tax);
            $tax->setLocale($locale);

            $rows[] = [
                'id' => $tax->getId(),
                'title' => $tax->getTitle() ?? \sprintf('#%d', (int) $tax->getId()),
                'description' => $tax->getDescription() ?? '',
                '_actions' => [
                    new RowAction(
                        kind: 'edit',
                        label: $this->translator->trans('Edit this tax'),
                        href: $this->urls->generate('admin.configuration.taxes.update', ['tax_id' => (int) $tax->getId()]),
                        grantedAttribute: AccessManager::UPDATE,
                        grantedSubject: 'admin.configuration.tax',
                    ),
                    new RowAction(
                        kind: 'delete',
                        label: $this->translator->trans('Delete this tax'),
                        modalTarget: '#tax-delete-modal',
                        grantedAttribute: AccessManager::DELETE,
                        grantedSubject: 'admin.configuration.tax',
                        dataAttributes: ['tax-id' => (int) $tax->getId(), 'tax-title' => $tax->getTitle() ?? ''],
                    ),
                ],
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function taxRuleRows(string $locale, ListSort $sort): array
    {
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = TaxRuleQuery::create();
        match ($sort->field) {
            'is_default' => $query->orderByIsDefault($criteria),
            'title' => $query->useTaxRuleI18nQuery(null, Criteria::LEFT_JOIN)->filterByLocale($locale)->orderByTitle($criteria)->endUse(),
            default => $query->orderById($criteria),
        };

        $rows = [];
        foreach ($query->find() as $taxRule) {
            \assert($taxRule instanceof TaxRule);
            $taxRule->setLocale($locale);
            $id = (int) $taxRule->getId();
            $isDefault = (bool) $taxRule->getIsDefault();

            $actions = [
                new RowAction(
                    kind: 'edit',
                    label: $this->translator->trans('Edit this tax rule'),
                    href: $this->urls->generate(self::UPDATE_ROUTE, ['tax_rule_id' => $id]),
                    grantedAttribute: AccessManager::UPDATE,
                    grantedSubject: 'admin.configuration.tax',
                ),
            ];

            if (!$isDefault) {
                $actions[] = new RowAction(
                    kind: 'set-default',
                    label: $this->translator->trans('Set as default tax rule'),
                    href: $this->urls->generate(
                        'admin.configuration.taxes-rules.set-default',
                        ['tax_rule_id' => $id],
                    ),
                    grantedAttribute: AccessManager::UPDATE,
                    grantedSubject: 'admin.configuration.tax',
                    dataAttributes: ['bo-post' => ''],
                    inlineFrom: 'md',
                );
                $actions[] = new RowAction(
                    kind: 'delete',
                    label: $this->translator->trans('Delete this tax rule'),
                    modalTarget: '#tax-rule-delete-modal',
                    grantedAttribute: AccessManager::DELETE,
                    grantedSubject: 'admin.configuration.tax',
                    dataAttributes: ['tax-rule-id' => $id, 'tax-rule-title' => $taxRule->getTitle() ?? ''],
                    inlineFrom: 'md',
                );
            }

            $rows[] = [
                'id' => $id,
                'title' => $taxRule->getTitle() ?? \sprintf('#%d', $id),
                'is_default' => $isDefault,
                '_actions' => $actions,
            ];
        }

        return $rows;
    }

    /**
     * @return array{taxRules: list<string>, specifications: list<array{country: int|null, state: int, specification: string}>}
     */
    private function buildSpecification(int $taxRuleId): array
    {
        $taxRuleCountries = TaxRuleCountryQuery::create()
            ->filterByTaxRuleId($taxRuleId)
            ->orderByCountryId()
            ->orderByStateId()
            ->orderByPosition()
            ->orderByTaxId()
            ->find();

        if ($taxRuleCountries->isEmpty()) {
            return ['taxRules' => [], 'specifications' => []];
        }

        $specifications = [];
        $taxRules = [];
        $currentCountryId = null;
        $currentStateId = 0;
        $specKey = [];

        foreach ($taxRuleCountries as $entry) {
            $countryId = $entry->getCountryId();
            $stateId = (int) $entry->getStateId();

            if ($currentCountryId !== null && ($countryId !== $currentCountryId || $stateId !== $currentStateId)) {
                $spec = implode(',', $specKey);
                $specifications[] = [
                    'country' => $currentCountryId,
                    'state' => $currentStateId,
                    'specification' => $spec,
                ];
                if (!\in_array($spec, $taxRules, true)) {
                    $taxRules[] = $spec;
                }
                $specKey = [];
            }

            $currentCountryId = $countryId;
            $currentStateId = $stateId;
            $specKey[] = $entry->getTaxId().'-'.$entry->getPosition();
        }

        $spec = implode(',', $specKey);
        $specifications[] = [
            'country' => $currentCountryId,
            'state' => $currentStateId,
            'specification' => $spec,
        ];
        if (!\in_array($spec, $taxRules, true)) {
            $taxRules[] = $spec;
        }

        return ['taxRules' => $taxRules, 'specifications' => $specifications];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function specificationRows(int $taxRuleId): array
    {
        $locale = $this->defaultLocale();
        $rows = [];
        $entries = TaxRuleCountryQuery::create()
            ->filterByTaxRuleId($taxRuleId)
            ->orderByCountryId()
            ->orderByStateId()
            ->orderByPosition()
            ->find();

        foreach ($entries as $entry) {
            $country = $entry->getCountry();
            $country?->setLocale($locale);
            $tax = $entry->getTax();
            $tax?->setLocale($locale);
            $state = $entry->getState();
            $state?->setLocale($locale);

            $rows[] = [
                'country' => $country?->getTitle() ?? '-',
                'state' => $state?->getTitle() ?? '-',
                'tax' => $tax?->getTitle() ?? '-',
                'position' => $entry->getPosition(),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function taxesByIdMap(string $locale): array
    {
        $map = [];
        foreach (TaxQuery::create()->orderById()->find() as $tax) {
            \assert($tax instanceof Tax);
            $tax->setLocale($locale);
            $map[(int) $tax->getId()] = $tax->getTitle() ?? \sprintf('#%d', (int) $tax->getId());
        }

        return $map;
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    private function countriesWithoutStates(string $locale): array
    {
        $rows = [];
        $countries = CountryQuery::create()
            ->filterByVisible(1)
            ->filterByHasStates(0)
            ->find();

        foreach ($countries as $country) {
            \assert($country instanceof Country);
            $country->setLocale($locale);
            $rows[] = [
                'id' => (int) $country->getId(),
                'title' => $country->getTitle() ?? \sprintf('#%d', (int) $country->getId()),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['title'], (string) $b['title']));

        return $rows;
    }

    /**
     * @return array<int, array{id: int, title: string, states: list<array{id: int, title: string}>}>
     */
    private function countriesWithStates(string $locale): array
    {
        $rows = [];
        $countries = CountryQuery::create()
            ->filterByVisible(1)
            ->filterByHasStates(1)
            ->find();

        foreach ($countries as $country) {
            \assert($country instanceof Country);
            $country->setLocale($locale);
            $countryId = (int) $country->getId();

            $states = [];
            $stateRows = StateQuery::create()
                ->filterByCountryId($countryId)
                ->filterByVisible(1)
                ->find();
            foreach ($stateRows as $state) {
                \assert($state instanceof State);
                $state->setLocale($locale);
                $states[] = [
                    'id' => (int) $state->getId(),
                    'title' => $state->getTitle() ?? \sprintf('#%d', (int) $state->getId()),
                ];
            }
            usort($states, static fn (array $a, array $b): int => strcasecmp((string) $a['title'], (string) $b['title']));

            $rows[] = [
                'id' => $countryId,
                'title' => $country->getTitle() ?? \sprintf('#%d', $countryId),
                'states' => $states,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['title'], (string) $b['title']));

        return $rows;
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    private function deliveryTaxRuleOptions(string $locale): array
    {
        $options = [];
        foreach (TaxRuleQuery::create()->orderById()->find() as $taxRule) {
            \assert($taxRule instanceof TaxRule);
            $taxRule->setLocale($locale);
            $options[] = [
                'id' => (int) $taxRule->getId(),
                'title' => $taxRule->getTitle() ?? \sprintf('#%d', (int) $taxRule->getId()),
            ];
        }

        return $options;
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
