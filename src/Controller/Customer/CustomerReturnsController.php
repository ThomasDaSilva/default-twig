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

use BackOfficeDefaultTwigBundle\Repository\OrderReturnRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnFilters;
use BackOfficeDefaultTwigBundle\Service\OrderReturn\OrderReturnListRowPresenter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\CustomerQuery;
use Twig\Environment;

/**
 * The returns section of the customer sheet, loaded when it is opened: the most recent
 * requests of the customer, laid out as the rows of the return queue, and a link to the
 * queue filtered on the customer when there are more. It needs the sheet's permission and
 * the returns permission, and does not exist while the shop has not turned returns on.
 */
#[Route('/admin', name: 'admin.')]
final readonly class CustomerReturnsController
{
    public const PAGE_SIZE = 25;

    private const TEMPLATE = '@BackOfficeDefaultTwig/customer/_returns_section.html.twig';
    private const LIST_ROUTE = 'admin.order-return.list';

    public function __construct(
        private AdminAccessChecker $access,
        private ReturnEligibilityChecker $eligibility,
        private OrderReturnRepository $returns,
        private OrderReturnListRowPresenter $rowPresenter,
        private UrlGeneratorInterface $urls,
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/customer/returns', name: 'customer.returns', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->eligibility->isFeatureEnabled()) {
            throw new NotFoundHttpException();
        }

        if ($denied = $this->access->check([AdminResources::CUSTOMER, AdminResources::ORDER_RETURN], [], AccessManager::VIEW)) {
            return $denied;
        }

        $customer = CustomerQuery::create()->findPk((int) $request->query->get('customer_id', 0));
        if ($customer === null) {
            return new Response($this->translator->trans('This customer does not exist.'), Response::HTTP_NOT_FOUND);
        }

        $customerId = (int) $customer->getId();
        $page = $this->returns->findPaginated(new OrderReturnFilters(customerId: $customerId), 1, self::PAGE_SIZE);

        return new Response($this->twig->render(self::TEMPLATE, [
            'rows' => $this->rowPresenter->presentAll($page['rows'], $request->getLocale(), withCustomer: false, linkProducts: $this->access->canView(AdminResources::PRODUCT)),
            'total' => $page['total'],
            'list_url' => $this->urls->generate(self::LIST_ROUTE, ['customer_id' => $customerId]),
        ]));
    }
}
