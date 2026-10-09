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
use Thelia\Domain\Accounting\AccountingChart;
use Thelia\Domain\Accounting\InvalidAccountingChartException;
use Thelia\Model\ConfigQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The chart of accounts of the sales journal: the journal, the customer and shipping
 * accounts, and the accounts of each tax rate. The accounting exports refuse to run until
 * it is set.
 */
#[Route('/admin/configuration/accounting', name: 'admin.accounting.')]
final class AccountingController
{
    private const RESOURCE = AdminResources::CONFIG;
    private const EDIT_ROUTE = 'admin.accounting.edit';
    private const TEMPLATE = '@BackOfficeDefaultTwig/configuration/accounting/edit.html.twig';

    /**
     * Rows the page offers for the tax rates; empty ones are ignored.
     */
    private const RATE_ROWS = 6;

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[Route('', name: 'edit', methods: ['GET'])]
    public function edit(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $values = [
            'journal_code' => (string) ConfigQuery::read(AccountingChart::JOURNAL_CODE_KEY, ''),
            'journal_label' => (string) ConfigQuery::read(AccountingChart::JOURNAL_LABEL_KEY, ''),
            'customer_account' => (string) ConfigQuery::read(AccountingChart::CUSTOMER_ACCOUNT_KEY, ''),
            'shipping_account' => (string) ConfigQuery::read(AccountingChart::SHIPPING_ACCOUNT_KEY, ''),
        ];
        $rates = [];

        foreach (array_filter(explode(',', (string) ConfigQuery::read(AccountingChart::RATE_ACCOUNTS_KEY, ''))) as $rawRate) {
            $parts = array_map('trim', explode(':', $rawRate));
            $rates[] = ['rate' => $parts[0], 'product' => $parts[1] ?? '', 'tax' => $parts[2] ?? ''];
        }

        return $this->render($values, $rates);
    }

    #[Route('', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (TokenAuthenticationException) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $values = [];

        foreach (['journal_code', 'journal_label', 'customer_account', 'shipping_account'] as $field) {
            $values[$field] = trim((string) $request->request->get($field, ''));
        }

        $rates = [];

        foreach ((array) $request->request->all('rates') as $row) {
            $rate = str_replace(',', '.', trim((string) ($row['rate'] ?? '')));
            $product = trim((string) ($row['product'] ?? ''));
            $tax = trim((string) ($row['tax'] ?? ''));

            if ('' !== $rate || '' !== $product || '' !== $tax) {
                $rates[] = ['rate' => $rate, 'product' => $product, 'tax' => $tax];
            }
        }

        try {
            $chart = AccountingChart::fromValues(
                $values['journal_code'],
                $values['journal_label'],
                $values['customer_account'],
                $values['shipping_account'],
                implode(',', array_map(static fn (array $row): string => $row['rate'].':'.$row['product'].('' !== $row['tax'] ? ':'.$row['tax'] : ''), $rates)),
            );
        } catch (InvalidAccountingChartException $exception) {
            return $this->render($values, $rates, $exception->getMessage());
        }

        ConfigQuery::write(AccountingChart::JOURNAL_CODE_KEY, $chart->journalCode);
        ConfigQuery::write(AccountingChart::JOURNAL_LABEL_KEY, $chart->journalLabel);
        ConfigQuery::write(AccountingChart::CUSTOMER_ACCOUNT_KEY, $chart->customerAccount);
        ConfigQuery::write(AccountingChart::SHIPPING_ACCOUNT_KEY, $chart->shippingAccount);
        ConfigQuery::write(AccountingChart::RATE_ACCOUNTS_KEY, $chart->rateAccountsSetting());
        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, 'Chart of accounts of the sales journal updated');

        $session = $this->requestStack->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $this->translator->trans('The chart of accounts has been saved.'));
        }

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE));
    }

    /**
     * @param array<string, string>                                  $values
     * @param list<array{rate: string, product: string, tax: string}> $rates
     */
    private function render(array $values, array $rates, ?string $error = null): Response
    {
        return new Response($this->twig->render(self::TEMPLATE, [
            'values' => $values,
            'rates' => array_pad($rates, max(self::RATE_ROWS, \count($rates)), ['rate' => '', 'product' => '', 'tax' => '']),
            'missing' => $this->missing($values, $rates),
            'error' => $error,
            'token' => $this->tokens->assignToken(),
        ]));
    }

    /**
     * What the accounting exports still need, read the way they read it.
     *
     * @param array<string, string>                                  $values
     * @param list<array{rate: string, product: string, tax: string}> $rates
     *
     * @return list<string>
     */
    private function missing(array $values, array $rates): array
    {
        $labels = [
            AccountingChart::MISSING_CUSTOMER_ACCOUNT => $this->translator->trans('the customer account'),
            AccountingChart::MISSING_SHIPPING_ACCOUNT => $this->translator->trans('the shipping account'),
            AccountingChart::MISSING_RATE_ACCOUNTS => $this->translator->trans('the accounts of the tax rates'),
        ];
        $missing = [];

        if ('' === ($values['customer_account'] ?? '')) {
            $missing[] = $labels[AccountingChart::MISSING_CUSTOMER_ACCOUNT];
        }

        if ('' === ($values['shipping_account'] ?? '')) {
            $missing[] = $labels[AccountingChart::MISSING_SHIPPING_ACCOUNT];
        }

        if ([] === $rates) {
            $missing[] = $labels[AccountingChart::MISSING_RATE_ACCOUNTS];
        }

        return $missing;
    }
}
