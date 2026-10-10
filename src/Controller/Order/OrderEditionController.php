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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Order\Edition\InvalidOrderEditException;
use Thelia\Domain\Order\Edition\OrderEdit;
use Thelia\Domain\Order\Edition\OrderEditLine;
use Thelia\Domain\Order\Edition\OrderEditor;
use Thelia\Domain\Order\Edition\OrderEditOutcome;
use Thelia\Domain\Order\Exception\OrderException;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * Edits the lines, the discount and the postage of an order. The page is a form of the
 * whole order: "Preview" asks the core for the totals it would give, "Save" applies it,
 * refused when the order changed since the page was opened.
 */
final class OrderEditionController
{
    private const RESOURCE = AdminResources::ORDER_EDIT;
    private const TEMPLATE = '@BackOfficeDefaultTwig/order/edit_lines.html.twig';
    private const DETAIL_ROUTE = 'admin.order.update.view';

    /**
     * Empty rows offered to add products; more can be added by saving and coming back.
     */
    private const NEW_ROWS = 3;

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly OrderEditor $editor,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[Route('/admin/order/{order_id}/edit-lines', name: 'admin.order.edit-lines', methods: ['GET'], requirements: ['order_id' => '\d+'])]
    public function edit(int $order_id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $order = OrderQuery::create()->findPk($order_id);

        if (null === $order) {
            return new RedirectResponse($this->urls->generate('admin.order.list'));
        }

        return $this->render($order, $this->editor->fingerprint($order), $this->formOf($order));
    }

    #[Route('/admin/order/{order_id}/edit-lines', name: 'admin.order.edit-lines.save', methods: ['POST'], requirements: ['order_id' => '\d+'])]
    public function save(int $order_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (TokenAuthenticationException) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $order = OrderQuery::create()->findPk($order_id);

        if (null === $order) {
            return new RedirectResponse($this->urls->generate('admin.order.list'));
        }

        $form = $this->formFromRequest($request);
        $fingerprint = (string) $request->request->get('fingerprint', '');

        try {
            $edit = $this->editOf($order, $form);

            if (!$request->request->has('save')) {
                return $this->render($order, $fingerprint, $form, $this->editor->preview($order, $edit));
            }

            $outcome = $this->editor->apply($order, $edit, $fingerprint);
        } catch (OrderException $exception) {
            return $this->render($order, $fingerprint, $form, null, $exception->getMessage());
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Order %s edited: total %.2f to %.2f', (string) $order->getRef(), $outcome->totalBefore, $outcome->totalAfter), (int) $order->getId());
        $this->flashOutcome($outcome);

        return new RedirectResponse($this->urls->generate(self::DETAIL_ROUTE, ['order_id' => $order_id]));
    }

    /**
     * @param array{lines: array<int, array{quantity: string, price: string, remove: bool}>, new: list<array{reference: string, quantity: string, price: string}>, discount: string, postage: string} $form
     */
    private function editOf(Order $order, array $form): OrderEdit
    {
        // A line left out of the edit is removed: a form cut short (input limit, lost
        // request) must not remove the lines it lost on the way.
        foreach ($this->productLines($order) as $orderLine) {
            if (!isset($form['lines'][(int) $orderLine->getId()])) {
                throw new InvalidOrderEditException($this->translator->trans('The form did not send every line of the order: open it again.'));
            }
        }

        $lines = [];

        foreach ($form['lines'] as $orderProductId => $line) {
            if ($line['remove']) {
                continue;
            }

            $lines[] = OrderEditLine::keep($orderProductId, self::number($line['quantity']), '' === $line['price'] ? null : self::decimal($line['price']));
        }

        foreach ($form['new'] as $new) {
            if ('' === $new['reference']) {
                continue;
            }

            $pse = ProductSaleElementsQuery::create()->findOneByRef($new['reference'])
                ?? ProductSaleElementsQuery::create()->findOneByEanCode($new['reference'])
                ?? throw new InvalidOrderEditException($this->translator->trans('No product has the reference or the GTIN "%reference".', ['%reference' => $new['reference']]));

            $lines[] = OrderEditLine::add((int) $pse->getId(), self::number('' === $new['quantity'] ? '1' : $new['quantity']), '' === $new['price'] ? null : self::decimal($new['price']));
        }

        return new OrderEdit(
            $lines,
            '' === $form['discount'] ? null : self::decimal($form['discount']),
            '' === $form['postage'] ? null : self::decimal($form['postage']),
        );
    }

    /**
     * The form as the order stands: what the page shows before anything is typed.
     *
     * @return array{lines: array<int, array{quantity: string, price: string, remove: bool}>, new: list<array{reference: string, quantity: string, price: string}>, discount: string, postage: string}
     */
    private function formOf(Order $order): array
    {
        $lines = [];

        foreach ($this->productLines($order) as $line) {
            $lines[(int) $line->getId()] = ['quantity' => self::quantity((float) $line->getQuantity()), 'price' => '', 'remove' => false];
        }

        return [
            'lines' => $lines,
            'new' => array_fill(0, self::NEW_ROWS, ['reference' => '', 'quantity' => '', 'price' => '']),
            'discount' => number_format((float) $order->getDiscount(), 2, '.', ''),
            'postage' => number_format((float) $order->getPostage(), 2, '.', ''),
        ];
    }

    /**
     * @return array{lines: array<int, array{quantity: string, price: string, remove: bool}>, new: list<array{reference: string, quantity: string, price: string}>, discount: string, postage: string}
     */
    private function formFromRequest(Request $request): array
    {
        $lines = [];

        foreach ((array) $request->request->all('lines') as $id => $line) {
            $lines[(int) $id] = [
                'quantity' => trim((string) ($line['quantity'] ?? '')),
                'price' => trim((string) ($line['price'] ?? '')),
                'remove' => '' !== (string) ($line['remove'] ?? ''),
            ];
        }

        $new = [];

        foreach ((array) $request->request->all('new') as $row) {
            $new[] = [
                'reference' => trim((string) ($row['reference'] ?? '')),
                'quantity' => trim((string) ($row['quantity'] ?? '')),
                'price' => trim((string) ($row['price'] ?? '')),
            ];
        }

        return [
            'lines' => $lines,
            'new' => array_pad($new, self::NEW_ROWS, ['reference' => '', 'quantity' => '', 'price' => '']),
            'discount' => trim((string) $request->request->get('discount', '')),
            'postage' => trim((string) $request->request->get('postage', '')),
        ];
    }

    /**
     * @param array{lines: array<int, array{quantity: string, price: string, remove: bool}>, new: list<array{reference: string, quantity: string, price: string}>, discount: string, postage: string} $form
     */
    private function render(Order $order, string $fingerprint, array $form, ?OrderEditOutcome $preview = null, ?string $error = null): Response
    {
        $tax = 0.0;
        $total = $order->getTotalAmount($tax);
        $refusal = $this->editor->refusal($order);

        return new Response($this->twig->render(self::TEMPLATE, [
            'order' => $order,
            'lines' => array_map(static fn (OrderProduct $line): array => [
                'id' => (int) $line->getId(),
                'ref' => (string) $line->getProductRef(),
                'pse_ref' => (string) $line->getProductSaleElementsRef(),
                'title' => (string) $line->getTitle(),
                'unit_price' => 1 === (int) $line->getWasInPromo() ? (float) $line->getPromoPrice() : (float) $line->getPrice(),
            ], $this->productLines($order)),
            'form' => $form,
            'fingerprint' => $fingerprint,
            'total' => $total,
            'currency' => $order->getCurrency(),
            'preview' => $preview,
            'error' => $error,
            'refusal' => $refusal,
            'token' => $this->tokens->assignToken(),
        ]));
    }

    private function flashOutcome(OrderEditOutcome $outcome): void
    {
        $session = $this->requestStack->getSession();

        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add('success', $this->translator->trans('The order has been edited: its total went from %before to %after.', ['%before' => number_format($outcome->totalBefore, 2, '.', ''), '%after' => number_format($outcome->totalAfter, 2, '.', '')]));

        if ($outcome->amountToRefund() > 0) {
            $session->getFlashBag()->add('warning', $this->translator->trans('The order was paid: %amount is to be refunded to the customer.', ['%amount' => number_format($outcome->amountToRefund(), 2, '.', '')]));
        }

        if ($outcome->amountToCollect() > 0) {
            $session->getFlashBag()->add('warning', $this->translator->trans('The order was paid: %amount remains to be paid by the customer.', ['%amount' => number_format($outcome->amountToCollect(), 2, '.', '')]));
        }
    }

    /**
     * @return list<OrderProduct>
     */
    private function productLines(Order $order): array
    {
        return array_values(array_filter(
            iterator_to_array(OrderProductQuery::create()->filterByOrderId($order->getId())->orderById()->find(), false),
            static fn (OrderProduct $line): bool => !$line->isServiceLine(),
        ));
    }

    private static function number(string $value): float
    {
        return is_numeric(str_replace(',', '.', $value)) ? (float) str_replace(',', '.', $value) : 0.0;
    }

    /**
     * A decimal typed by hand, comma accepted; anything else is handed on as it is, for the
     * core to refuse with its own message.
     */
    private static function decimal(string $value): string
    {
        return str_replace([' ', ','], ['', '.'], $value);
    }

    private static function quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }
}
