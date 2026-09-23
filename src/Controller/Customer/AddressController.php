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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormValidator;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Customer\VatVerificationAvailability;
use BackOfficeDefaultTwigBundle\Service\I18n\CountryStateProvider;
use BackOfficeDefaultTwigBundle\Service\I18n\StateChoiceProvider;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Address\AddressCreateOrUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Customer\Service\CustomerTitleService;
use Thelia\Model\Address;
use Thelia\Model\AddressQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Event\AddressEvent;
use Twig\Environment;

#[Route('/admin/address', name: 'admin.address.')]
final class AddressController
{
    private const RESOURCE = AdminResources::ADDRESS;
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/customer/address/edit.html.twig';
    private const CUSTOMER_EDIT_ROUTE = 'admin.customer.update.view';
    private const CREATE_FORM_NAME = 'thelia_address_create';
    private const UPDATE_FORM_NAME = 'thelia_address_update';

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
        private readonly StateChoiceProvider $stateChoices,
        private readonly CountryStateProvider $countryStates,
        private readonly VatVerificationAvailability $vatVerification,
    ) {
    }

    #[Route('/update', name: 'update.view', methods: ['GET'])]
    public function view(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $addressId = (int) $request->query->get('address_id', 0);
        $address = AddressQuery::create()->findPk($addressId);

        if ($address === null) {
            return new RedirectResponse($this->urls->generate('admin.customers'));
        }

        $form = $this->buildUpdateForm($this->addressToFormData($address));

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'form' => $form->createView(),
            'address' => $address,
            'vat_verification_available' => $this->vatVerification->isAvailable(),
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        $form = $this->buildCreateForm();
        $customerId = (int) ($request->request->all(self::CREATE_FORM_NAME)['customer_id'] ?? 0);

        try {
            $validated = $this->validator->validate($form);
            $customer = CustomerQuery::create()->findPk($customerId)
                ?? throw new \LogicException($this->translator->trans('Customer not found.'));

            // Customers registered through the minimal front-office registration have no address:
            // their first one must become the default, or they would keep none at all.
            $isDefault = $customer->getDefaultAddress() === null ? 1 : 0;

            $event = $this->buildEvent($validated, $isDefault);
            $event->setCustomer($customer);

            $this->events->dispatch($event, TheliaEvents::ADDRESS_CREATE);

            $address = $event->getAddress();
            if ($address !== null) {
                $this->adminLogger->log(
                    self::RESOURCE,
                    AccessManager::CREATE,
                    \sprintf('Address %s (ID %d) created for customer %d', (string) $address->getLabel(), (int) $address->getId(), $customerId),
                    (int) $address->getId(),
                );
            }
        } catch (\Throwable $exception) {
            $this->errorRenderer->setup(
                $this->translator->trans('Address creation'),
                $exception->getMessage(),
                $form,
                $exception,
            );
        }

        return new RedirectResponse($this->urls->generate(self::CUSTOMER_EDIT_ROUTE, ['customer_id' => $customerId]));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $addressId = (int) ($request->request->all(self::UPDATE_FORM_NAME)['id'] ?? 0);
        $address = AddressQuery::create()->findPk($addressId);
        $customerId = (int) $address?->getCustomerId();
        $form = $this->buildUpdateForm(null);

        try {
            $validated = $this->validator->validate($form);
            $event = $this->buildEvent($validated);
            $event->setAddress($address ?? throw new \LogicException($this->translator->trans('Address not found.')));

            $this->events->dispatch($event, TheliaEvents::ADDRESS_UPDATE);

            $updated = $event->getAddress();
            if ($updated !== null) {
                $this->adminLogger->log(
                    self::RESOURCE,
                    AccessManager::UPDATE,
                    \sprintf('Address %s (ID %d) modified', (string) $updated->getLabel(), (int) $updated->getId()),
                    (int) $updated->getId(),
                );
            }
        } catch (\Throwable $exception) {
            $this->errorRenderer->setup(
                $this->translator->trans('Address update'),
                $exception->getMessage(),
                $form,
                $exception,
            );
        }

        return new RedirectResponse($this->urls->generate(self::CUSTOMER_EDIT_ROUTE, ['customer_id' => $customerId]));
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $addressId = (int) $request->request->get('address_id', 0);
        $address = AddressQuery::create()->findPk($addressId);
        $customerId = (int) $address?->getCustomerId();

        $response = $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: new AddressEvent($address),
            eventName: TheliaEvents::ADDRESS_DELETE,
            actionLabel: 'Address deletion',
            successRoute: self::CUSTOMER_EDIT_ROUTE,
            successParameters: ['customer_id' => $customerId],
        );

        return $response;
    }

    #[Route('/use', name: 'makeItDefault', methods: ['POST'])]
    public function makeItDefault(Request $request): Response
    {
        $addressId = (int) $request->request->get('address_id', 0);
        $address = AddressQuery::create()->findPk($addressId);
        $customerId = (int) $address?->getCustomerId();

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new AddressEvent($address),
            eventName: TheliaEvents::ADDRESS_DEFAULT,
            actionLabel: 'Address set default',
            successRoute: self::CUSTOMER_EDIT_ROUTE,
            successParameters: ['customer_id' => $customerId],
        );
    }

    private function buildCreateForm(): FormInterface
    {
        return $this->formFactory->createNamed(self::CREATE_FORM_NAME, AddressType::class, null, array_merge($this->formOptions(), [
        ]));
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function buildUpdateForm(?array $data): FormInterface
    {
        return $this->formFactory->createNamed(self::UPDATE_FORM_NAME, AddressType::class, $data, array_merge($this->formOptions(), [
            'include_id' => true,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'title_choices' => $this->titleService->getTitleAsFormChoices(),
            'country_choices' => $this->countryChoices(),
            'states' => $this->stateChoices->forLocale(),
        ];
    }

    private function buildEvent(FormInterface $validated, int $isDefault = 0): AddressCreateOrUpdateEvent
    {
        $data = $validated->getData() ?? [];

        return new AddressCreateOrUpdateEvent(
            label: (string) ($data['label'] ?? ''),
            title: (int) ($data['title'] ?? 0),
            firstname: (string) ($data['firstname'] ?? ''),
            lastname: (string) ($data['lastname'] ?? ''),
            address1: (string) ($data['address1'] ?? ''),
            address2: (string) ($data['address2'] ?? ''),
            address3: (string) ($data['address3'] ?? ''),
            zipcode: (string) ($data['zipcode'] ?? ''),
            city: (string) ($data['city'] ?? ''),
            country: (int) ($data['country'] ?? 0),
            cellphone: $this->stringOrNull($data['cellphone'] ?? null),
            phone: $this->stringOrNull($data['phone'] ?? null),
            company: $this->stringOrNull($data['company'] ?? null),
            isDefault: $isDefault,
            state: isset($data['state']) && $data['state'] !== '' ? (int) $data['state'] : null,
            siret: $this->stringOrNull($data['siret'] ?? null),
            vatNumber: $this->stringOrNull($data['vat_number'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function addressToFormData(Address $address): array
    {
        return [
            'id' => $address->getId(),
            'customer_id' => $address->getCustomerId(),
            'label' => $address->getLabel(),
            'title' => $address->getTitleId(),
            'firstname' => $address->getFirstname(),
            'lastname' => $address->getLastname(),
            'company' => $address->getCompany(),
            'siret' => $address->getSiret(),
            'vat_number' => $address->getVatNumber(),
            'address1' => $address->getAddress1(),
            'address2' => $address->getAddress2(),
            'address3' => $address->getAddress3(),
            'zipcode' => $address->getZipcode(),
            'city' => $address->getCity(),
            'country' => $address->getCountryId(),
            'state' => $address->getStateId(),
            'phone' => $address->getPhone(),
            'cellphone' => $address->getCellphone(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function countryChoices(): array
    {
        $choices = [];
        foreach ($this->countryStates->countries() as $country) {
            if ($country['title'] === '') {
                continue;
            }
            $choices[$country['title']] = $country['id'];
        }
        ksort($choices);

        return $choices;
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
