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

namespace BackOfficeDefaultTwigBundle\Controller\Customer;

use BackOfficeDefaultTwigBundle\Form\Customer\AddressType;
use BackOfficeDefaultTwigBundle\Form\Customer\CustomerType;
use BackOfficeDefaultTwigBundle\Repository\CountryRepository;
use BackOfficeDefaultTwigBundle\Repository\CustomerRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormValidator;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerFilterPresenter;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerFilters;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerListRowPresenter;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerOverviewProvider;
use BackOfficeDefaultTwigBundle\Service\I18n\CountryStateProvider;
use BackOfficeDefaultTwigBundle\Service\I18n\StateChoiceProvider;
use BackOfficeDefaultTwigBundle\Service\Tag\TagSwatch;
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
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Customer\Service\CustomerTitleService;
use Thelia\Domain\Tagging\Service\TagService;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Event\CustomerEvent;
use Thelia\Model\LangQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\Tag;
use Thelia\Model\TagElement;
use Thelia\Model\TagQuery;
use Thelia\Tools\Password;
use Twig\Environment;

#[Route('/admin', name: 'admin.')]
final class CustomerController
{
    private const RESOURCE = AdminResources::CUSTOMER;
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/customer/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/customer/edit.html.twig';
    private const LIST_ROUTE = 'admin.customers';
    private const EDIT_ROUTE = 'admin.customer.update.view';
    private const CREATE_FORM_NAME = 'thelia_customer_create';
    private const UPDATE_FORM_NAME = 'thelia_customer_update';
    private const PAGE_SIZE = 25;

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
        private readonly CustomerTitleService $titleService,
        private readonly CustomerRepository $customerRepository,
        private readonly CountryRepository $countryRepository,
        private readonly CustomerFilterPresenter $filterPresenter,
        private readonly CustomerListRowPresenter $rowPresenter,
        private readonly OrderRepository $orderRepository,
        private readonly StateChoiceProvider $stateChoices,
        private readonly CountryStateProvider $countryStates,
        private readonly MailerFactory $mailer,
        private readonly TagService $tags,
        private readonly TagSwatch $swatch,
        private readonly CustomerOverviewProvider $overviewProvider,
    ) {
    }

    #[Route('/customers', name: 'customers', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $locale = $request->getLocale();
        $filters = CustomerFilters::fromRequest($request);

        $paginated = $this->customerRepository->findPaginated($filters, $page, self::PAGE_SIZE);

        $customerIds = [];
        foreach ($paginated['rows'] as $customer) {
            $customerIds[] = (int) $customer->getId();
        }

        $orderCounts = $this->customerRepository->findOrderCounts($customerIds);
        $totalsSpent = $this->customerRepository->findTotalSpentByCustomer($customerIds);
        $lastOrders = $this->customerRepository->findLastOrderDateByCustomer($customerIds);
        $phones = $this->customerRepository->findPrimaryPhones($customerIds);
        $primaryCountryIds = $this->customerRepository->findPrimaryCountryIds($customerIds);
        $countriesIndex = $this->buildCountriesIndex($primaryCountryIds, $locale);
        $tagsByCustomer = $this->tags->findTagsForMany(TagElement::ELEMENT_KEY_CUSTOMER, $customerIds);

        $rows = [];
        foreach ($paginated['rows'] as $customer) {
            $customerId = (int) $customer->getId();
            $countryId = $primaryCountryIds[$customerId] ?? 0;
            $country = $countriesIndex[$countryId] ?? ['flag' => '', 'title' => ''];

            $rows[] = $this->rowPresenter->present(
                customer: $customer,
                locale: $locale,
                orderCount: $orderCounts[$customerId] ?? 0,
                totalSpent: $totalsSpent[$customerId] ?? 0.0,
                lastOrderAt: $lastOrders[$customerId] ?? null,
                phone: $phones[$customerId] ?? '',
                countryFlag: $country['flag'],
                countryTitle: $country['title'],
                tags: $tagsByCustomer[$customerId] ?? [],
            );
        }

        $filtersPresented = $this->filterPresenter->present($filters, $locale);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'total' => $paginated['total'],
            'pages' => $paginated['lastPage'],
            'current_page' => $page,
            'filters' => $filtersPresented,
            'query_params' => $filters->toQueryParams(),
            'create_form' => $this->buildCreateForm($locale)->createView(),
        ]));
    }

    /**
     * @param array<int, int> $primaryCountryIds
     *
     * @return array<int, array{flag: string, title: string}>
     */
    private function buildCountriesIndex(array $primaryCountryIds, string $locale): array
    {
        $uniqueIds = array_values(array_unique(array_filter($primaryCountryIds, static fn (int $id): bool => $id > 0)));
        if ($uniqueIds === []) {
            return [];
        }

        $countries = $this->countryRepository->findByIdsLocalized($uniqueIds, $locale);

        $index = [];
        foreach ($countries as $country) {
            $index[$country['id']] = [
                'flag' => self::countryFlag($country['iso']),
                'title' => $country['title'],
            ];
        }

        return $index;
    }

    private static function countryFlag(string $iso): string
    {
        if (\strlen($iso) !== 2) {
            return '';
        }

        $upper = strtoupper($iso);
        $offset = 0x1F1E6 - \ord('A');
        $flag = '';
        for ($i = 0, $len = \strlen($upper); $i < $len; ++$i) {
            $flag .= mb_chr(\ord($upper[$i]) + $offset);
        }

        return $flag;
    }

    #[Route('/customer/update', name: 'customer.update.view', methods: ['GET'])]
    public function view(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $customerId = (int) $request->query->get('customer_id', 0);
        $customer = CustomerQuery::create()->findPk($customerId);
        if ($customer === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $hasAddress = $customer->getDefaultAddress() !== null;
        $form = $this->buildUpdateForm($this->defaultLocale(), $this->customerToFormData($customer), $hasAddress);
        $addressCreateForm = $this->formFactory->createNamed(
            'thelia_address_create',
            AddressType::class,
            ['customer_id' => $customer->getId()],
            [
                'title_choices' => $this->titleService->getTitleAsFormChoices(),
                'country_choices' => $this->countryChoices($this->defaultLocale()),
                'states' => $this->stateChoices->forLocale($this->defaultLocale()),
            ],
        );

        $customerId = (int) $customer->getId();
        $navigation = $this->customerRepository->findPreviousNext($customer);
        $overview = $this->overviewProvider->compute($customer, new \DateTimeImmutable());

        // The orders are not even read for an administrator who may not see them.
        $ordersTotal = $overview->canViewOrders ? $this->orderRepository->countByCustomer($customerId) : 0;
        $ordersPage = max(1, (int) $request->query->get('orders_page', 1));
        $ordersPerPage = 20;
        $ordersLastPage = max(1, (int) ceil($ordersTotal / $ordersPerPage));

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'form' => $form->createView(),
            'customer' => $customer,
            'has_address' => $hasAddress,
            'addresses' => $this->addressRows($customer),
            'address_create_form' => $addressCreateForm->createView(),
            'overview' => $overview,
            'recent_orders' => $overview->canViewOrders ? $this->customerOrdersPage($customerId, $ordersPage, $ordersPerPage) : [],
            'orders_total' => $ordersTotal,
            'orders_page' => $ordersPage,
            'orders_last_page' => $ordersLastPage,
            'prev_url' => $navigation['previous'] !== null ? $this->urls->generate(self::EDIT_ROUTE, ['customer_id' => $navigation['previous']]) : null,
            'next_url' => $navigation['next'] !== null ? $this->urls->generate(self::EDIT_ROUTE, ['customer_id' => $navigation['next']]) : null,
        ]));
    }

    #[Route('/customer/create', name: 'customer.create', methods: ['POST'])]
    public function create(): Response
    {
        $form = $this->buildCreateForm($this->defaultLocale());

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::CUSTOMER_CREATEACCOUNT,
            eventFactory: $this->createEvent(...),
            actionLabel: 'Customer creation',
            successRoute: self::EDIT_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
            describeForLog: $this->describeCreated(...),
            successParametersResolver: $this->createdCustomerParameters(...),
        );
    }

    #[Route('/customer/save', name: 'customer.update.process', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $customerId = (int) $request->request->get('id', $request->request->get('customer_id', 0));
        $customer = CustomerQuery::create()->findPk($customerId);
        if ($customer === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        // Decided from the database, never from the submitted payload: an address may have been
        // added between the GET and this POST, and the form shape must follow the current state.
        $hasAddress = $customer->getDefaultAddress() !== null;
        $form = $this->buildUpdateForm($this->defaultLocale(), null, $hasAddress);

        try {
            $validated = $this->validator->validate($form);
            $data = $validated->getData() ?? [];

            $event = $this->buildEvent($data);
            $event->setCustomer($customer);
            $event->setEmailUpdateAllowed(true);

            if ($hasAddress) {
                $this->events->dispatch($event, TheliaEvents::CUSTOMER_UPDATEACCOUNT);
            } else {
                $this->updateProfileOnly($customer, $event, $data);
            }

            $updated = $event->getCustomer() ?? $customer;
            if ($updated !== null) {
                $this->adminLogger->log(
                    self::RESOURCE,
                    AccessManager::UPDATE,
                    \sprintf('Customer %s %s (ID %d) modified', (string) $updated->getFirstname(), (string) $updated->getLastname(), (int) $updated->getId()),
                    (int) $updated->getId(),
                );
            }

            // Outside the customer save on purpose, and it cannot be folded into it: the
            // update travels through the event dispatcher, which opens no transaction this
            // call could join. The customer is already written when we get here, so a
            // failure has to say so rather than read as "nothing was saved".
            $savedCustomerId = (int) ($updated?->getId() ?? $customerId);

            $tagFailure = $this->applySubmittedTags($savedCustomerId, $data);
            if ($tagFailure !== null) {
                $this->errorRenderer->refuse(
                    $this->translator->trans('Customer update'),
                    $this->translator->trans('The customer was saved, but its tags could not be updated.'),
                    $form,
                    $tagFailure,
                );
            }

            return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['customer_id' => $savedCustomerId]));
        } catch (\Throwable $exception) {
            $this->errorRenderer->fail(
                $this->translator->trans('Customer update'),
                $exception,
                $form,
            );

            return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['customer_id' => $customerId]));
        }
    }

    #[Route('/customer/delete', name: 'customer.delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $customerId = (int) ($request->request->get('customer_id') ?? $request->query->get('customer_id') ?? 0);
        $customer = CustomerQuery::create()->findPk($customerId);
        if ($customer === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        if (OrderQuery::create()->filterByCustomerId((int) $customer->getId())->count() > 0) {
            $this->flashError($request, $this->translator->trans(
                'This customer has existing orders and cannot be deleted.',
            ));

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $event = new CustomerEvent($customer);

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::CUSTOMER_DELETEACCOUNT,
            actionLabel: 'Customer deletion',
            successRoute: self::LIST_ROUTE,
        );
    }

    private function flashError(Request $request, string $message): void
    {
        try {
            $session = $request->getSession();
            if (method_exists($session, 'getFlashBag')) {
                $session->getFlashBag()->add('danger', $message);
            }
        } catch (\Throwable) {
        }
    }

    private function buildCreateForm(string $locale): FormInterface
    {
        return $this->formFactory->createNamed(self::CREATE_FORM_NAME, CustomerType::class, [
            'discount' => 0,
        ], array_merge($this->formOptions($locale), [
            'require_email_confirm' => (bool) ConfigQuery::read('customer_confirm_email', false),
        ]));
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function buildUpdateForm(string $locale, ?array $data, bool $includeAddress): FormInterface
    {
        $tagChoices = $this->tagChoices();

        return $this->formFactory->createNamed(self::UPDATE_FORM_NAME, CustomerType::class, $data, array_merge($this->formOptions($locale), [
            'include_id' => true,
            'include_password' => true,
            'password_required' => false,
            'include_address' => $includeAddress,
            'include_tags' => true,
            'tag_choices' => array_keys($tagChoices),
            'tag_colors' => array_filter($tagChoices),
        ]));
    }

    /**
     * Customers registered through the minimal front-office registration have no address yet.
     * CUSTOMER_UPDATEACCOUNT would create one from the submitted fields, which the admin does
     * not know, so only the customer's own columns are updated here.
     *
     * @param array<string, mixed> $data
     */
    private function updateProfileOnly(Customer $customer, CustomerCreateOrUpdateEvent $event, array $data): void
    {
        $plainPassword = $event->getPassword();
        $emailChanged = $customer->getEmail() !== $event->getEmail();

        // updateProfile() ignores null values, while createOrUpdate() resets a blank discount
        // to zero. Coalesce so that clearing the field means the same on both branches.
        $event->setDiscount((float) ($data['discount'] ?? 0));

        $this->events->dispatch($event, TheliaEvents::CUSTOMER_UPDATEPROFILE);

        if (($plainPassword !== null && $plainPassword !== '' && $plainPassword !== '0') || $emailChanged) {
            $this->mailer->sendEmailToCustomer('customer_account_changed', $customer, ['password' => $plainPassword]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(string $locale): array
    {
        return [
            'title_choices' => $this->titleService->getTitleAsFormChoices(),
            'country_choices' => $this->countryChoices($locale),
            'lang_choices' => $this->langChoices(),
            'states' => $this->stateChoices->forLocale($locale),
        ];
    }

    private function createEvent(FormInterface $validated): CustomerCreateOrUpdateEvent
    {
        $event = $this->buildEvent($validated->getData() ?? []);
        $event->setPassword(Password::generateRandom());
        $event->setNotifyCustomerOfAccountCreation(true);

        return $event;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildEvent(array $data): CustomerCreateOrUpdateEvent
    {
        return new CustomerCreateOrUpdateEvent(
            title: isset($data['title']) ? (int) $data['title'] : null,
            firstname: $this->stringOrNull($data['firstname'] ?? null),
            lastname: $this->stringOrNull($data['lastname'] ?? null),
            address1: $this->stringOrNull($data['address1'] ?? null),
            address2: $this->stringOrNull($data['address2'] ?? null) ?? '',
            address3: $this->stringOrNull($data['address3'] ?? null) ?? '',
            phone: $this->stringOrNull($data['phone'] ?? null),
            cellphone: $this->stringOrNull($data['cellphone'] ?? null),
            zipcode: $this->stringOrNull($data['zipcode'] ?? null),
            city: $this->stringOrNull($data['city'] ?? null),
            country: $this->stringOrNull($data['country'] ?? null),
            email: $this->stringOrNull($data['email'] ?? null),
            password: !empty($data['password']) ? (string) $data['password'] : null,
            langId: isset($data['lang_id']) && $data['lang_id'] !== '' ? (int) $data['lang_id'] : null,
            reseller: (bool) ($data['reseller'] ?? false),
            sponsor: null,
            discount: isset($data['discount']) && $data['discount'] !== '' ? (float) $data['discount'] : null,
            company: $this->stringOrNull($data['company'] ?? null),
            ref: null,
            state: isset($data['state']) && $data['state'] !== '' ? (int) $data['state'] : null,
            siret: $this->stringOrNull($data['siret'] ?? null),
            vatNumber: $this->stringOrNull($data['vat_number'] ?? null),
        );
    }

    /**
     * @return array<string, int>
     */
    private function createdCustomerParameters(CustomerCreateOrUpdateEvent $event): array
    {
        return ['customer_id' => (int) ($event->getCustomer()?->getId() ?? 0)];
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function describeCreated(CustomerCreateOrUpdateEvent $event): array
    {
        $customer = $event->getCustomer();
        if ($customer === null) {
            throw new \LogicException($this->translator->trans('No customer was created.'));
        }

        return [
            \sprintf('Customer %s %s (ID %d) created', (string) $customer->getFirstname(), (string) $customer->getLastname(), (int) $customer->getId()),
            (int) $customer->getId(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @return list<array{id: int, ref: string, created_at: \DateTimeInterface|null, status_title: string, status_color: string, amount: float, currency_symbol: string, edit_url: string}>
     */
    private function customerOrdersPage(int $customerId, int $page, int $perPage): array
    {
        $orders = $this->orderRepository->findByCustomerPage($customerId, $page, $perPage);
        $amounts = $this->orderRepository->findTotalAmountByOrder(
            array_map(static fn (Order $order): int => (int) $order->getId(), iterator_to_array($orders)),
        );

        $rows = [];
        foreach ($orders as $order) {
            $status = $order->getOrderStatus();
            $currency = $order->getCurrency();
            $rows[] = [
                'id' => (int) $order->getId(),
                'ref' => (string) $order->getRef(),
                'created_at' => $order->getCreatedAt(),
                'status_title' => $status !== null ? (string) $status->getTitle() : '',
                'status_color' => $status !== null ? (string) $status->getColor() : '#6c757d',
                'amount' => $amounts[(int) $order->getId()] ?? (float) $order->getTotalAmount(),
                'currency_symbol' => $currency !== null ? (string) $currency->getSymbol() : '',
                'edit_url' => $this->urls->generate('admin.order.update.view', ['order_id' => (int) $order->getId()]),
            ];
        }

        return $rows;
    }

    private function addressRows(Customer $customer): array
    {
        $rows = [];
        foreach ($customer->getAddresses() as $address) {
            $rows[] = [
                'id' => (int) $address->getId(),
                'label' => $address->getLabel() ?? '',
                'firstname' => $address->getFirstname() ?? '',
                'lastname' => $address->getLastname() ?? '',
                'address' => trim(($address->getAddress1() ?? '').' '.($address->getZipcode() ?? '').' '.($address->getCity() ?? '')),
                'is_default' => (bool) $address->getIsDefault(),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * The whole tag vocabulary, label => colour, in label order.
     *
     * The colour is checked by TagSwatch and not by the template: it is the one
     * place that decides whether a tag colour may reach a style attribute.
     *
     * @return array<string, string>
     */
    private function tagChoices(): array
    {
        $choices = [];

        foreach (TagQuery::create()->orderByLabel(Criteria::ASC)->find() as $tag) {
            $choices[(string) $tag->getLabel()] = $this->swatch->color($tag->getColorCode()) ?? '';
        }

        return $choices;
    }

    /**
     * @return list<string>
     */
    private function currentTagLabels(int $customerId): array
    {
        return array_map(
            static fn (Tag $tag): string => (string) $tag->getLabel(),
            $this->tags->findTagsFor(TagElement::ELEMENT_KEY_CUSTOMER, $customerId),
        );
    }

    /**
     * Writes the submitted tags and hands back what stopped it, or null on success.
     *
     * @param array<string, mixed> $data
     */
    private function applySubmittedTags(int $customerId, array $data): ?\Throwable
    {
        $picked = \is_array($data['tags'] ?? null) ? $data['tags'] : [];
        $typed = explode(',', (string) ($data['new_tags'] ?? ''));

        // Passed on as typed: setLabelsFor() normalises every label and drops the
        // ones that reduce to nothing, so a second pass here would be a guard no
        // test can tell apart from the one the service already holds.
        $labels = array_map(static fn (mixed $label): string => (string) $label, [...$picked, ...$typed]);

        // Attaching a tag is customer data; adding a word to the shop vocabulary is
        // not. The admin API refuses the creation to a profile holding no grant on
        // the tag resource, so the customer sheet refuses it too instead of being
        // the way around that check. The known labels are still attached.
        $refused = $this->labelsRefusedToCreate($labels);

        if ($refused !== []) {
            $labels = array_values(array_filter(
                $labels,
                static fn (string $label): bool => !\in_array(Tag::normalizeLabel($label), $refused, true),
            ));
        }

        try {
            $this->tags->setLabelsFor(TagElement::ELEMENT_KEY_CUSTOMER, $customerId, $labels);
        } catch (\Throwable $exception) {
            return $exception;
        }

        if ($refused !== []) {
            return new \RuntimeException($this->translator->trans(
                'Creating a tag needs the tag configuration permission: %labels% not created.',
                ['%labels%' => implode(', ', array_map(static fn (string $label): string => '"'.$label.'"', $refused))],
            ));
        }

        return null;
    }

    /**
     * The labels the vocabulary does not hold yet, when the profile may not add to it.
     *
     * The grant is only checked once an unknown label is actually submitted: the
     * check writes an audit entry on refusal, and an administrator who only picks
     * existing tags has attempted nothing.
     *
     * @param list<string> $labels
     *
     * @return list<string> normalised labels
     */
    private function labelsRefusedToCreate(array $labels): array
    {
        $unknown = [];

        foreach ($labels as $label) {
            $normalized = Tag::normalizeLabel($label);

            if ($normalized === '' || isset($unknown[$normalized])) {
                continue;
            }

            if (TagQuery::create()->findOneByLabel($normalized) === null) {
                $unknown[$normalized] = $normalized;
            }
        }

        if ($unknown === [] || $this->access->check(AdminResources::TAG, [], AccessManager::CREATE) === null) {
            return [];
        }

        return array_values($unknown);
    }

    private function customerToFormData(Customer $customer): array
    {
        $data = [
            'id' => $customer->getId(),
            'title' => $customer->getTitleId(),
            'firstname' => $customer->getFirstname(),
            'lastname' => $customer->getLastname(),
            'email' => $customer->getEmail(),
            'lang_id' => $customer->getLangId(),
            'discount' => $customer->getDiscount(),
            'reseller' => (bool) $customer->getReseller(),
            'tags' => $this->currentTagLabels((int) $customer->getId()),
            'new_tags' => '',
        ];

        $address = $customer->getDefaultAddress();
        if ($address === null) {
            return $data;
        }

        return array_merge($data, [
            'company' => $address->getCompany(),
            'siret' => $address->getSiret(),
            'vat_number' => $address->getVatNumber(),
            'address1' => $address->getAddress1(),
            'address2' => $address->getAddress2(),
            'address3' => $address->getAddress3(),
            'phone' => $address->getPhone(),
            'cellphone' => $address->getCellphone(),
            'zipcode' => $address->getZipcode(),
            'city' => $address->getCity(),
            'country' => $address->getCountryId(),
            'state' => $address->getStateId(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function countryChoices(string $locale): array
    {
        $choices = [];
        foreach ($this->countryStates->countries($locale) as $country) {
            if ($country['title'] === '') {
                continue;
            }
            $choices[$country['title']] = $country['id'];
        }
        ksort($choices);

        return $choices;
    }

    /**
     * @return array<string, int>
     */
    private function langChoices(): array
    {
        $choices = [];
        foreach (LangQuery::create()->find() as $lang) {
            $choices[$lang->getTitle() ?? $lang->getCode() ?? '-'] = (int) $lang->getId();
        }

        return $choices;
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!\is_scalar($value) && $value !== null) {
            return null;
        }

        $cast = (string) $value;

        return $cast === '' ? null : $cast;
    }
}
