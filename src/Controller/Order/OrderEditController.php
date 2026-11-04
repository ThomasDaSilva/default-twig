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

namespace BackOfficeDefaultTwigBundle\Controller\Order;

use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Repository\SearchRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Order\OrderAccess;
use BackOfficeDefaultTwigBundle\Service\Order\OrderEditContextBuilder;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Security\User\UserInterface;
use Thelia\Domain\Order\DTO\AddedOrderLine;
use Thelia\Domain\Order\DTO\OrderEdit;
use Thelia\Domain\Order\Enum\OrderEditRefusal;
use Thelia\Domain\Order\Exception\OrderEditRefusedException;
use Thelia\Domain\Order\Exception\StockShortageException;
use Thelia\Domain\Order\Service\OrderEditor;
use Thelia\Log\Tlog;
use Thelia\Messenger\JobFailureMessage;
use Thelia\Model\LangQuery;
use Thelia\Model\Order;
use Thelia\Tools\TokenProvider;

final class OrderEditController
{
    private const RESOURCE = AdminResources::ORDER_EDIT;
    private const DETAIL_ROUTE = 'admin.order.update.view';
    private const SCALE = 6;
    private const MAX_ADDED_LINES = 50;
    private const AMOUNT_PATTERN = '/^\d{1,10}(\.\d{1,6})?$/D';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly AdminFlash $flash,
        private readonly AdminLogger $adminLogger,
        private readonly SecurityContext $securityContext,
        private readonly OrderRepository $orderRepository,
        private readonly OrderAccess $orderAccess,
        private readonly OrderEditContextBuilder $context,
        private readonly SearchRepository $search,
        #[Target('order_edit')]
        private readonly RateLimiterFactoryInterface $limiter,
        #[Target('order_edit_preview')]
        private readonly RateLimiterFactoryInterface $previewLimiter,
        private readonly ?OrderEditor $editor = null,
    ) {
    }

    #[Route('/admin/order/{order_id}/edit', name: 'admin.order.edit', methods: ['POST'], requirements: ['order_id' => '\d+'])]
    public function edit(Request $request, int $order_id): Response
    {
        if (null === $this->editor) {
            throw new NotFoundHttpException();
        }

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $order = $this->orderRepository->findById($order_id);

        if (null === $order) {
            return new RedirectResponse($this->urls->generate('admin.order.list'));
        }

        $this->orderAccess->assertReaches($order);

        $back = new RedirectResponse($this->urls->generate(self::DETAIL_ROUTE, ['order_id' => $order_id]));

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (\Throwable) {
            $this->flash->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $back;
        }

        $adminUser = $this->securityContext->getAdminUser();

        if (!$this->limiter->create($adminUser instanceof UserInterface ? $adminUser->getUsername() : null)->consume()->isAccepted()) {
            $this->flash->add($request, 'warning', $this->translator->trans('Too many order edits in a row. Try again in a few minutes.'));

            return $back;
        }

        if ($this->addsTooMany($request)) {
            $this->flash->add($request, 'danger', $this->tooManyAddedMessage());

            return $back;
        }

        try {
            $this->editor->edit($order, $this->readEdit($request, $order));
        } catch (OrderEditRefusedException $refusal) {
            $this->flash->add($request, 'danger', $this->refusalMessage($refusal->refusal));

            return $back;
        } catch (StockShortageException $shortage) {
            $this->flash->add($request, 'danger', $this->translator->trans('Not enough stock for product %ref%: the order was not edited.', ['%ref%' => (string) $shortage->productReference]));

            return $back;
        } catch (\Throwable $throwable) {
            Tlog::getInstance()->error(\sprintf('Order %d edit failed: %s', $order_id, JobFailureMessage::forLog($throwable)));

            $this->flash->add($request, 'danger', $this->translator->trans('The order could not be edited. See the system log for the details.'));

            return $back;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Order %s edited', (string) $order->getRef()), $order_id);
        $this->flash->add($request, 'success', $this->translator->trans('The order has been edited.'));

        return $back;
    }

    #[Route('/admin/order/{order_id}/edit/preview', name: 'admin.order.edit.preview', methods: ['POST'], requirements: ['order_id' => '\d+'])]
    public function preview(Request $request, int $order_id): Response
    {
        if (null === $this->editor) {
            throw new NotFoundHttpException();
        }

        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $order = $this->orderRepository->findById($order_id);

        if (null === $order) {
            throw new NotFoundHttpException();
        }

        $this->orderAccess->assertReaches($order);

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->translator->trans('Invalid security token, please try again.')], Response::HTTP_FORBIDDEN);
        }

        $adminUser = $this->securityContext->getAdminUser();

        if (!$this->previewLimiter->create($adminUser instanceof UserInterface ? $adminUser->getUsername() : null)->consume()->isAccepted()) {
            return new JsonResponse(['error' => $this->translator->trans('Too many order edits in a row. Try again in a few minutes.')], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if ($this->addsTooMany($request)) {
            return new JsonResponse(['error' => $this->tooManyAddedMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $preview = $this->editor->simulate($order, $this->readEdit($request, $order));
        } catch (OrderEditRefusedException $refusal) {
            return new JsonResponse(['error' => $this->refusalMessage($refusal->refusal), 'refusal' => $refusal->refusal->value], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (StockShortageException $shortage) {
            return new JsonResponse(['error' => $this->translator->trans('Not enough stock for product %ref%: the order was not edited.', ['%ref%' => (string) $shortage->productReference])], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $throwable) {
            Tlog::getInstance()->error(\sprintf('Order %d edit preview failed: %s', $order_id, JobFailureMessage::forLog($throwable)));

            return new JsonResponse(['error' => $this->translator->trans('The order could not be edited. See the system log for the details.')], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'before' => get_object_vars($preview->before),
            'after' => get_object_vars($preview->after),
        ]);
    }

    #[Route('/admin/order/{order_id}/edit/search', name: 'admin.order.edit.search', methods: ['GET'], requirements: ['order_id' => '\d+'])]
    public function search(Request $request, int $order_id): JsonResponse
    {
        if (null === $this->editor) {
            throw new NotFoundHttpException();
        }

        if (null !== $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return new JsonResponse([], Response::HTTP_FORBIDDEN);
        }

        $order = $this->orderRepository->findById($order_id);

        if (null === $order) {
            throw new NotFoundHttpException();
        }

        $this->orderAccess->assertReaches($order);

        $locale = LangQuery::create()->findOneByByDefault(1)?->getLocale() ?? 'en_US';

        return new JsonResponse($this->search->findSellableCombinations((string) $request->query->get('q', ''), $locale));
    }

    private function readEdit(Request $request, Order $order): OrderEdit
    {
        $current = $this->context->snapshot($order);

        $currentLines = [];
        foreach ($current['edit_lines'] as $line) {
            $currentLines[(int) $line['id']] = $line;
        }

        $removed = [];
        foreach ($request->request->all('remove') as $lineId => $flag) {
            if ('1' !== (string) $flag) {
                continue;
            }

            $this->assertLineBelongs((int) $lineId, $currentLines);
            $removed[] = (int) $lineId;
        }

        $quantities = [];
        foreach ($request->request->all('quantity') as $lineId => $submitted) {
            $id = (int) $lineId;
            $this->assertLineBelongs($id, $currentLines);

            if (\in_array($id, $removed, true) || !$this->differs((string) $submitted, $currentLines[$id]['quantity'])) {
                continue;
            }

            $quantity = $this->amount((string) $submitted);
            $quantities[$id] = null !== $quantity ? (float) $quantity : 0.0;
        }

        $prices = [];
        foreach ($request->request->all('price') as $lineId => $submitted) {
            $id = (int) $lineId;
            $this->assertLineBelongs($id, $currentLines);

            if (\in_array($id, $removed, true) || !$this->differs((string) $submitted, $currentLines[$id]['price'])) {
                continue;
            }

            $prices[$id] = $this->sent((string) $submitted);
        }

        $discount = $this->blankAsZero((string) $request->request->get('discount', $current['edit_discount']));
        $postage = $this->blankAsZero((string) $request->request->get('postage', $current['edit_postage']));

        return new OrderEdit(
            addedLines: $this->readAddedLines($request),
            removedLines: $removed,
            quantities: $quantities,
            unitPrices: $prices,
            discount: $this->differs($discount, $current['edit_discount']) ? $this->sent($discount) : null,
            untaxedPostage: $this->differs($postage, $current['edit_postage']) ? $this->sent($postage) : null,
            expectedState: (string) $request->request->get('expected_state', ''),
        );
    }

    private function addsTooMany(Request $request): bool
    {
        return \count($request->request->all('add')) > self::MAX_ADDED_LINES;
    }

    private function tooManyAddedMessage(): string
    {
        return $this->translator->trans('At most %max% products can be added at once.', ['%max%' => self::MAX_ADDED_LINES]);
    }

    /**
     * @return list<AddedOrderLine>
     */
    private function readAddedLines(Request $request): array
    {
        $added = [];

        foreach ($request->request->all('add') as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $quantity = $this->amount((string) ($row['quantity'] ?? ''));
            $price = trim((string) ($row['price'] ?? ''));

            $added[] = new AddedOrderLine(
                productSaleElementsId: (int) ($row['pse'] ?? 0),
                quantity: null !== $quantity ? (float) $quantity : 0.0,
                unitPrice: '' === $price ? null : $this->sent($price),
            );
        }

        return $added;
    }

    /**
     * @param array<int, mixed> $currentLines
     */
    private function assertLineBelongs(int $lineId, array $currentLines): void
    {
        if (!isset($currentLines[$lineId])) {
            throw new OrderEditRefusedException(OrderEditRefusal::UnknownLine, (string) $lineId);
        }
    }

    private function blankAsZero(string $submitted): string
    {
        return '' === trim($submitted) ? '0' : $submitted;
    }

    private function amount(string $submitted): ?string
    {
        $clean = preg_replace('/\s+/u', '', $submitted) ?? '';

        if (1 === preg_match('/^\d{1,3}(,\d{3})+$/D', $clean)) {
            return null;
        }

        $decimal = str_replace(',', '.', $clean);

        return 1 === preg_match(self::AMOUNT_PATTERN, $decimal) ? $decimal : null;
    }

    private function sent(string $submitted): string
    {
        return $this->amount($submitted) ?? trim($submitted);
    }

    private function differs(string $submitted, string $current): bool
    {
        $amount = $this->amount($submitted);

        if (null === $amount) {
            return true;
        }

        return number_format((float) $amount, self::SCALE, '.', '') !== number_format((float) $current, self::SCALE, '.', '');
    }

    private function refusalMessage(OrderEditRefusal $refusal): string
    {
        return match ($refusal) {
            OrderEditRefusal::StatusNotEditable => $this->translator->trans('The status of this order does not allow editing it.'),
            OrderEditRefusal::InvoiceIssued => $this->translator->trans('An invoice has been issued for this order: it can no longer be edited.'),
            OrderEditRefusal::NothingToEdit => $this->translator->trans('Nothing was changed.'),
            OrderEditRefusal::PaidOrderLineRemoval => $this->translator->trans('A line cannot be removed from a paid order.'),
            OrderEditRefusal::LastLine => $this->translator->trans('An order must keep at least one product line.'),
            OrderEditRefusal::UnknownLine => $this->translator->trans('One of the lines does not belong to this order.'),
            OrderEditRefusal::ServiceLine => $this->translator->trans('A service line cannot be edited.'),
            OrderEditRefusal::BundleLine => $this->translator->trans('A line of a bundle cannot be edited.'),
            OrderEditRefusal::LineInUse => $this->translator->trans('A line that has been shipped or returned cannot be edited.'),
            OrderEditRefusal::UnknownProduct => $this->translator->trans('The product of a line no longer exists in the catalogue.'),
            OrderEditRefusal::InvalidQuantity => $this->translator->trans('A quantity must be a number greater than zero.'),
            OrderEditRefusal::InvalidPrice => $this->translator->trans('A price must be a positive amount, without tax.'),
            OrderEditRefusal::InvalidDiscount => $this->translator->trans('The discount must be a positive amount that the order covers.'),
            OrderEditRefusal::InvalidPostage => $this->translator->trans('The postage must be a positive amount, without tax.'),
            OrderEditRefusal::PostageTaxUnknown => $this->translator->trans('The delivery module of this order cannot tell the tax of its postage: the postage cannot be changed.'),
            OrderEditRefusal::StaleState => $this->translator->trans('The order changed since you opened it. Check it and edit it again.'),
        };
    }
}
