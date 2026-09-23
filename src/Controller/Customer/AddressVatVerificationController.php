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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Customer\VatVerificationAvailability;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Legal\VatNumberVerifiedEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Security\User\UserInterface;
use Thelia\Domain\Legal\Enum\VatVerificationStatus;
use Thelia\Domain\Legal\Service\VatNumberVerifierInterface;
use Thelia\Model\AddressQuery;
use Thelia\Tools\TokenProvider;

/**
 * Asks the installed verification module about the VAT number of one address.
 *
 * The back office never writes the verification state: it triggers a check and
 * the module's answer travels through VAT_NUMBER_VERIFIED like any other, so
 * the columns keep having a single writer.
 */
#[Route('/admin/address/vat', name: 'admin.address.vat.')]
final readonly class AddressVatVerificationController
{
    private const RESOURCE = AdminResources::ADDRESS;
    private const CUSTOMER_EDIT_ROUTE = 'admin.customer.update.view';

    public function __construct(
        private AdminAccessChecker $access,
        private AdminLogger $adminLogger,
        private EventDispatcherInterface $events,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private TokenProvider $tokens,
        private SecurityContext $securityContext,
        private RequestStack $requestStack,
        private VatNumberVerifierInterface $verifier,
        private VatVerificationAvailability $availability,
        #[Target('vat_reverification')]
        private RateLimiterFactoryInterface $rateLimiter,
    ) {
    }

    #[Route('/verify', name: 'verify', methods: ['POST'])]
    public function verify(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $address = AddressQuery::create()->findPk((int) $request->request->get('address_id', 0));

        if (null === $address) {
            return new RedirectResponse($this->urls->generate('admin.customers'));
        }

        $customerRoute = new RedirectResponse(
            $this->urls->generate(self::CUSTOMER_EDIT_ROUTE, ['customer_id' => (int) $address->getCustomerId()]),
        );

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (\Throwable) {
            $this->flash('danger', $this->translator->trans('Invalid security token, please try again.'));

            return $customerRoute;
        }

        // Everything above is free; from here on an outside authority is
        // reached, so the throttle sits between the token and the call.
        $adminUser = $this->securityContext->getAdminUser();

        if (!$this->rateLimiter->create($adminUser instanceof UserInterface ? $adminUser->getUsername() : null)->consume()->isAccepted()) {
            $this->flash('warning', $this->translator->trans('Too many verifications in a row. Try again in a few minutes.'));

            return $customerRoute;
        }

        $vatNumber = (string) $address->getVatNumber();

        if ('' === $vatNumber || !$this->availability->isAvailable()) {
            $this->flash('warning', $this->translator->trans('This address cannot be verified: it carries no VAT number, or no verification module is installed.'));

            return $customerRoute;
        }

        $result = $this->verifier->verify($vatNumber, (string) $address->getCountry()->getIsoalpha2());

        $this->events->dispatch(new VatNumberVerifiedEvent($address, $result), TheliaEvents::VAT_NUMBER_VERIFIED);

        $this->adminLogger->log(
            self::RESOURCE,
            AccessManager::UPDATE,
            \sprintf('VAT number of address ID %d verified, answer: %s', (int) $address->getId(), $result->status->value),
            (int) $address->getId(),
        );

        $this->flash(...match ($result->status) {
            VatVerificationStatus::VERIFIED => ['success', $this->translator->trans('The VAT number was confirmed.')],
            VatVerificationStatus::REFUSED => ['danger', $this->translator->trans('The VAT number was refused: this address no longer exempts.')],
            VatVerificationStatus::UNDETERMINED => ['warning', $this->translator->trans('The verification service could not answer. Nothing was changed.')],
        });

        return $customerRoute;
    }

    private function flash(string $type, string $message): void
    {
        $session = $this->requestStack->getSession();

        if (method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
