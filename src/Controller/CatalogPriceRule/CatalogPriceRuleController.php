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

namespace BackOfficeDefaultTwigBundle\Controller\CatalogPriceRule;

use BackOfficeDefaultTwigBundle\Form\CatalogPriceRule\CatalogPriceRuleCreateType;
use BackOfficeDefaultTwigBundle\Form\CatalogPriceRule\CatalogPriceRuleType;
use BackOfficeDefaultTwigBundle\Repository\SaleRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFailureMessage;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\CatalogPriceRule\CatalogPriceRuleEditContextBuilder;
use BackOfficeDefaultTwigBundle\Service\CatalogPriceRule\CatalogPriceRuleEventFactory;
use BackOfficeDefaultTwigBundle\Service\CatalogPriceRule\CatalogPriceRuleListPresenter;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\CatalogPriceRule\CatalogPriceRuleCreateEvent;
use Thelia\Core\Event\CatalogPriceRule\CatalogPriceRuleDeleteEvent;
use Thelia\Core\Event\CatalogPriceRule\CatalogPriceRuleRecomputeEvent;
use Thelia\Core\Event\CatalogPriceRule\CatalogPriceRuleToggleActivityEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Pricing\Rule\Conversion\SaleToPriceRuleConverter;
use Thelia\Domain\Pricing\Rule\Preview\RulePreviewService;
use Thelia\Model\CatalogPriceRuleQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\LangQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The catalog price rules: the list with what each rule covers, the edit screen with
 * its scope, audience and effect, and the preview of the price before and after on a
 * sample of the products covered.
 *
 * Every write goes through the core events: the action materializes the scope and
 * rewrites the stored prices, so nothing here knows how a price is computed.
 */
#[Route('/admin/catalog-price-rule', name: 'admin.catalog-price-rule.')]
final class CatalogPriceRuleController
{
    private const RESOURCE = AdminResources::CATALOG_PRICE_RULE;
    private const LIST_ROUTE = 'admin.catalog-price-rule.list';
    private const EDIT_ROUTE = 'admin.catalog-price-rule.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/catalog-price-rule/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/catalog-price-rule/edit.html.twig';
    private const PREVIEW_TEMPLATE = '@BackOfficeDefaultTwig/catalog-price-rule/_preview.html.twig';
    private const FORM_NAME = 'thelia_catalog_price_rule';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly CatalogPriceRuleListPresenter $listPresenter,
        private readonly CatalogPriceRuleEditContextBuilder $editContextBuilder,
        private readonly CatalogPriceRuleEventFactory $eventFactory,
        private readonly RulePreviewService $preview,
        private readonly SaleToPriceRuleConverter $saleConverter,
        private readonly SaleRepository $sales,
        private readonly EditLocaleResolver $editLocale,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $locale = $request->getLocale();
        $sort = ListSort::fromRequest($request, ['priority', 'title', 'state', 'start_date', 'end_date', 'products'], 'priority', 'asc');

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $this->listPresenter->build($locale, $sort->field, $sort->direction),
            'global_actions' => $this->listPresenter->globalActions(),
            'create_form' => $this->buildCreateForm($locale)->createView(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $locale = $request->getLocale();

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $this->buildCreateForm($locale),
            eventName: TheliaEvents::CATALOG_PRICE_RULE_CREATE,
            eventFactory: fn (FormInterface $validated): CatalogPriceRuleCreateEvent => $this->eventFactory->createEvent((array) $validated->getData(), $locale),
            actionLabel: 'Catalog price rule creation',
            successRoute: self::EDIT_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
            successParametersResolver: static fn (CatalogPriceRuleCreateEvent $event): array => ['rule_id' => (int) $event->getCatalogPriceRule()?->getId()],
            describeForLog: static fn (CatalogPriceRuleCreateEvent $event): array => ['Catalog price rule created', $event->getCatalogPriceRule()?->getId()],
        );
    }

    #[Route('/update/{rule_id}', name: 'update', methods: ['GET'], requirements: ['rule_id' => '\d+'])]
    public function updateView(int $rule_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $rule = CatalogPriceRuleQuery::create()->findPk($rule_id);

        if (null === $rule) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $rule->setLocale($locale);
        $context = $this->editContextBuilder->build($rule, $locale);
        $form = $this->buildEditForm($context['form_data'], $context['can_target_customers']);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, array_merge(
            $context,
            ['rule' => $rule, 'form' => $form->createView(), 'edit_language_id' => (int) $editLang->getId()],
        )));
    }

    #[Route('/save/{rule_id}', name: 'save', methods: ['POST'], requirements: ['rule_id' => '\d+'])]
    public function save(int $rule_id, Request $request): Response
    {
        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $this->buildEditForm(null, $this->editContextBuilder->canTargetCustomers()),
            eventName: TheliaEvents::CATALOG_PRICE_RULE_UPDATE,
            eventFactory: fn (FormInterface $validated) => $this->eventFactory->updateEvent($rule_id, (array) $validated->getData(), $request, $this->defaultLocale()),
            actionLabel: 'Catalog price rule update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['rule_id' => $rule_id],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['rule_id' => $rule_id])),
        );
    }

    /**
     * The price before and after, on a sample of what the definition being edited
     * covers - as posted, not as stored, so the merchant sees the effect of what they
     * are about to save. Nothing is written.
     */
    #[Route('/preview/{rule_id}', name: 'preview', methods: ['POST'], requirements: ['rule_id' => '\d+'])]
    public function preview(int $rule_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $form = $this->buildEditForm(null, $this->editContextBuilder->canTargetCustomers());
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $definition = $this->eventFactory->definitionForPreview($rule_id, (array) $form->getData(), $request, $this->defaultLocale());
        $currency = CurrencyQuery::create()->findOneByByDefault(1) ?? CurrencyQuery::create()->findOne();

        try {
            $preview = $this->preview->preview($definition, $currency, editedRuleId: $rule_id);
        } catch (\Throwable $exception) {
            return new Response($this->twig->render(self::PREVIEW_TEMPLATE, [
                'error' => AdminFailureMessage::of($exception, $this->translator),
                'preview' => null,
                'currency' => $currency,
            ]), Response::HTTP_BAD_REQUEST);
        }

        return new Response($this->twig->render(self::PREVIEW_TEMPLATE, [
            'error' => null,
            'preview' => $preview,
            'currency' => $currency,
        ]));
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new CatalogPriceRuleDeleteEvent((int) ($request->query->get('rule_id') ?? $request->request->get('rule_id', 0))),
            eventName: TheliaEvents::CATALOG_PRICE_RULE_DELETE,
            actionLabel: 'Catalog price rule deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/toggle/{rule_id}', name: 'toggle', methods: ['POST'], requirements: ['rule_id' => '\d+'])]
    public function toggle(int $rule_id, Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new CatalogPriceRuleToggleActivityEvent($rule_id),
            eventName: TheliaEvents::CATALOG_PRICE_RULE_TOGGLE_ACTIVITY,
            actionLabel: 'Catalog price rule activity toggled',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/recompute', name: 'recompute', methods: ['POST'])]
    public function recompute(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new CatalogPriceRuleRecomputeEvent(),
            eventName: TheliaEvents::CATALOG_PRICE_RULE_RECOMPUTE,
            actionLabel: 'Catalog price rules recomputed',
            successRoute: self::LIST_ROUTE,
        );
    }

    /**
     * Turns a flash sale into rules, turned off, and opens the first one. The sale is
     * left as it is: the merchant turns it off when the rule is ready.
     */
    #[Route('/convert-sale/{sale_id}', name: 'convert-sale', methods: ['POST'], requirements: ['sale_id' => '\d+'])]
    public function convertSale(int $sale_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        if (!$this->tokens->checkToken((string) $request->request->get('_token', ''))) {
            return new Response($this->translator->trans('Invalid security token'), Response::HTTP_FORBIDDEN);
        }

        $sale = $this->sales->findById($sale_id);

        if (null === $sale) {
            return new RedirectResponse($this->urls->generate('admin.sale.default'));
        }

        $result = $this->saleConverter->convert($sale);
        $first = $result->rules[0] ?? null;

        $this->adminLogger->log(self::RESOURCE, AccessManager::CREATE, \sprintf('Sale %d converted into %d catalog price rule(s)', $sale_id, \count($result->rules)), $first?->getId());

        if (null === $first) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['rule_id' => (int) $first->getId()]));
    }

    #[Route('/products-by-categories.{_format}', name: 'products-by-categories', methods: ['GET'], defaults: ['_format' => 'json'])]
    public function productsByCategories(Request $request): JsonResponse
    {
        if ($this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return new JsonResponse([], Response::HTTP_FORBIDDEN);
        }

        $categoryIds = array_values(array_filter(array_map('intval', explode(',', (string) $request->query->get('categories', '')))));

        return new JsonResponse(['products' => $this->sales->findProductsInCategories($categoryIds, $this->defaultLocale())]);
    }

    private function buildCreateForm(string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_catalog_price_rule_creation', CatalogPriceRuleCreateType::class, [
            'locale' => $locale,
        ]);
    }

    private function buildEditForm(?array $data, bool $canTargetCustomers): FormInterface
    {
        return $this->formFactory->createNamed(self::FORM_NAME, CatalogPriceRuleType::class, $data, [
            'can_target_customers' => $canTargetCustomers,
        ]);
    }

    private function defaultLocale(): string
    {
        return LangQuery::create()->findOneByByDefault(1)?->getLocale() ?? 'en_US';
    }
}
