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

use BackOfficeDefaultTwigBundle\Form\Configuration\GiftWrappingType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\I18n\EditLocaleResolver;
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
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\GiftWrapping\GiftWrappingCreateEvent;
use Thelia\Core\Event\GiftWrapping\GiftWrappingDeleteEvent;
use Thelia\Core\Event\GiftWrapping\GiftWrappingToggleActiveEvent;
use Thelia\Core\Event\GiftWrapping\GiftWrappingUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\GiftWrapping;
use Thelia\Model\GiftWrappingQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\TaxRuleI18nTableMap;
use Thelia\Model\TaxRuleQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The gift wrapping services the shop offers at checkout.
 *
 * Written against the consent screen, which is the same shape: a short list the merchant
 * orders by hand, each row turned on and off without being deleted, and a wording edited
 * one language at a time.
 */
#[Route('/admin/configuration/gift-wrapping', name: 'admin.gift-wrapping.')]
final class GiftWrappingController
{
    private const RESOURCE = AdminResources::GIFT_WRAPPING;
    private const LIST_ROUTE = 'admin.gift-wrapping.default';
    private const EDIT_ROUTE = 'admin.gift-wrapping.update';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/gift-wrapping/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/gift-wrapping/edit.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly EditLocaleResolver $editLocale,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, $this->buildListContext($request)));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $locale = $request->getLocale();
        $form = $this->formFactory->createNamed('thelia_gift_wrapping_creation', GiftWrappingType::class, [
            'locale' => $locale,
            'active' => true,
            'price' => 0,
        ], [
            'tax_rule_choices' => $this->taxRuleChoiceMap($locale),
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::GIFT_WRAPPING_CREATE,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Gift wrapping creation',
            successRoute: self::LIST_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
        );
    }

    #[Route('/update/{gift_wrapping_id}', name: 'update', methods: ['GET'], requirements: ['gift_wrapping_id' => '\d+'])]
    public function updateView(int $gift_wrapping_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $giftWrapping = GiftWrappingQuery::create()->findPk($gift_wrapping_id);
        if ($giftWrapping === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $editLang = $this->editLocale->resolveFromRequest($request);
        $locale = $editLang->getLocale() ?? 'en_US';
        $giftWrapping->setLocale($locale);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'gift_wrapping' => $giftWrapping,
            'form' => $this->buildUpdateForm($giftWrapping, $locale)->createView(),
            'edit_language_id' => (int) $editLang->getId(),
        ]));
    }

    #[Route('/save/{gift_wrapping_id}', name: 'save', methods: ['POST'], requirements: ['gift_wrapping_id' => '\d+'])]
    public function processUpdate(int $gift_wrapping_id, Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_gift_wrapping_modification', GiftWrappingType::class, null, [
            'include_id' => true,
            'tax_rule_choices' => $this->taxRuleChoiceMap($request->getLocale()),
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::GIFT_WRAPPING_UPDATE,
            eventFactory: $this->updateEvent(...),
            actionLabel: 'Gift wrapping update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['gift_wrapping_id' => $gift_wrapping_id],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['gift_wrapping_id' => $gift_wrapping_id])),
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $giftWrappingId = (int) ($request->query->get('gift_wrapping_id') ?? $request->request->get('gift_wrapping_id', 0));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new GiftWrappingDeleteEvent($giftWrappingId),
            eventName: TheliaEvents::GIFT_WRAPPING_DELETE,
            actionLabel: 'Gift wrapping deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/toggle-active', name: 'toggle-active', methods: ['POST'])]
    public function toggleActive(Request $request): Response
    {
        $giftWrappingId = (int) ($request->query->get('gift_wrapping_id') ?? $request->request->get('gift_wrapping_id', 0));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new GiftWrappingToggleActiveEvent($giftWrappingId),
            eventName: TheliaEvents::GIFT_WRAPPING_TOGGLE_ACTIVE,
            actionLabel: 'Gift wrapping activation toggle',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/update-position', name: 'update-position', methods: ['POST'])]
    public function updatePosition(Request $request): Response
    {
        $event = new UpdatePositionEvent(
            (int) ($request->query->get('gift_wrapping_id') ?? $request->request->get('gift_wrapping_id', 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::GIFT_WRAPPING_UPDATE_POSITION,
            actionLabel: 'Gift wrapping reorder',
            successRoute: self::LIST_ROUTE,
        );
    }

    private function createEvent(FormInterface $validated): GiftWrappingCreateEvent
    {
        $data = $validated->getData() ?? [];

        $event = new GiftWrappingCreateEvent();
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setCode((string) ($data['code'] ?? ''))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setDescription($this->nullableString($data['description'] ?? null))
            ->setPrice($this->decimal($data['price'] ?? 0))
            ->setTaxRuleId((int) ($data['tax_rule_id'] ?? 0))
            ->setActive((int) (bool) ($data['active'] ?? true));

        return $event;
    }

    private function updateEvent(FormInterface $validated): GiftWrappingUpdateEvent
    {
        $data = $validated->getData() ?? [];

        $event = new GiftWrappingUpdateEvent((int) ($data['id'] ?? 0));
        $event->setLocale((string) ($data['locale'] ?? $this->defaultLocale()))
            ->setTitle((string) ($data['title'] ?? ''))
            ->setDescription($this->nullableString($data['description'] ?? null))
            ->setPrice($this->decimal($data['price'] ?? 0))
            ->setTaxRuleId((int) ($data['tax_rule_id'] ?? 0))
            ->setActive((int) (bool) ($data['active'] ?? false));

        return $event;
    }

    /**
     * The column is a DECIMAL and its generated setter wants a string: a float handed
     * straight over comes back through PHP's own formatting, which is not what the column
     * stores.
     */
    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = (string) ($value ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildListContext(Request $request): array
    {
        $locale = $request->getLocale();
        $sort = ListSort::fromRequest($request, ['id', 'code', 'title', 'price', 'position'], 'position');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = GiftWrappingQuery::create();
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            'code' => $query->orderByCode($criteria),
            'price' => $query->orderByPrice($criteria),
            'title' => $query->useGiftWrappingI18nQuery(null, Criteria::LEFT_JOIN)->filterByLocale($locale)->orderByTitle($criteria)->endUse(),
            default => $query->orderByPosition($criteria),
        };
        $giftWrappings = $query->find();
        $rows = [];
        foreach ($giftWrappings as $giftWrapping) {
            \assert($giftWrapping instanceof GiftWrapping);
            $giftWrapping->setLocale($locale);
            $rows[] = $this->giftWrappingToRow($giftWrapping);
        }

        $createForm = $this->formFactory->createNamed('thelia_gift_wrapping_creation', GiftWrappingType::class, [
            'locale' => $locale,
            'active' => true,
            'price' => 0,
        ], [
            'tax_rule_choices' => $this->taxRuleChoiceMap($locale),
        ]);

        return [
            'rows' => $rows,
            'create_form' => $createForm->createView(),
            'update_position_url' => $this->urls->generate('admin.gift-wrapping.update-position'),
            'update_position_token' => $this->tokens->assignToken(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function giftWrappingToRow(GiftWrapping $giftWrapping): array
    {
        $id = (int) $giftWrapping->getId();

        return [
            'id' => $id,
            'code' => (string) $giftWrapping->getCode(),
            'title' => (string) $giftWrapping->getTitle(),
            // Rendered as typed rather than as money: the list states the shop's own
            // figure, and the currency it is charged in is the buyer's, not the merchant's.
            'price' => rtrim(rtrim(number_format((float) $giftWrapping->getPrice(), 2, '.', ''), '0'), '.') ?: '0',
            'free' => $giftWrapping->isFree(),
            'tax_rule' => (string) $giftWrapping->getTaxRule()->setLocale((string) $giftWrapping->getLocale())->getTitle(),
            'active' => $giftWrapping->isActive(),
            'toggle_active_url' => $this->urls->generate('admin.gift-wrapping.toggle-active', ['gift_wrapping_id' => $id]),
            'position' => (int) $giftWrapping->getPosition(),
            '_actions' => [
                new RowAction(
                    kind: 'edit',
                    label: $this->translator->trans('Edit'),
                    href: $this->urls->generate(self::EDIT_ROUTE, ['gift_wrapping_id' => $id]),
                    grantedAttribute: AccessManager::UPDATE,
                    grantedSubject: self::RESOURCE,
                ),
                new RowAction(
                    kind: 'delete',
                    label: $this->translator->trans('Delete'),
                    modalTarget: '#gift-wrapping-delete-modal',
                    grantedAttribute: AccessManager::DELETE,
                    grantedSubject: self::RESOURCE,
                    dataAttributes: ['gift-wrapping-id' => $id, 'gift-wrapping-label' => (string) $giftWrapping->getTitle()],
                ),
            ],
        ];
    }

    private function buildUpdateForm(GiftWrapping $giftWrapping, string $locale): FormInterface
    {
        return $this->formFactory->createNamed('thelia_gift_wrapping_modification', GiftWrappingType::class, [
            'id' => $giftWrapping->getId(),
            'locale' => $locale,
            'code' => $giftWrapping->getCode(),
            'title' => $giftWrapping->getTitle(),
            'description' => $giftWrapping->getDescription(),
            'price' => (float) $giftWrapping->getPrice(),
            'tax_rule_id' => $giftWrapping->getTaxRuleId(),
            'active' => $giftWrapping->isActive(),
        ], [
            'include_id' => true,
            'tax_rule_choices' => $this->taxRuleChoiceMap($locale),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function taxRuleChoiceMap(string $locale): array
    {
        $taxRules = TaxRuleQuery::create()
            ->joinWithI18n($locale)
            ->orderBy(TaxRuleI18nTableMap::COL_TITLE)
            ->find();

        $map = [];

        foreach ($taxRules as $taxRule) {
            $taxRule->setLocale($locale);
            $title = (string) $taxRule->getTitle();

            if ($title === '') {
                continue;
            }

            $map[$title] = (int) $taxRule->getId();
        }

        return $map;
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
