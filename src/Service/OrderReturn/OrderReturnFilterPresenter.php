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

namespace BackOfficeDefaultTwigBundle\Service\OrderReturn;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\CustomerQuery;
use Thelia\Model\OrderQuery;

/**
 * Flattens an OrderReturnFilters value object into a Twig-friendly array
 * (chips, option lists, rehydration values). Keeps the template declarative.
 */
final readonly class OrderReturnFilterPresenter
{
    private const LIST_ROUTE = 'admin.order-return.list';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private OrderReturnFilterCatalog $catalog,
        private OrderReturnResolutionLabels $resolutionLabels,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(OrderReturnFilters $filters, string $locale): array
    {
        $statuses = $this->catalog->statuses($locale);

        return [
            'is_empty' => $filters->isEmpty(),
            'has_any_filter' => !$filters->isEmpty()
                || $filters->search !== ''
                || $filters->period !== OrderReturnFilters::PERIOD_ALL,
            'advanced_count' => $this->countAdvancedFilters($filters),
            'active_chips' => $this->buildActiveChips($filters, $this->indexById($statuses)),
            'clear_all_url' => $this->urls->generate(self::LIST_ROUTE),

            'status_options' => $statuses,
            'selected_status_ids' => $filters->statusIds,

            'created_from_input' => $filters->createdFrom?->format('Y-m-d') ?? '',
            'created_to_input' => $filters->createdTo?->format('Y-m-d') ?? '',
            'customer_id_input' => $filters->customerId !== null ? (string) $filters->customerId : '',
            'order_id_input' => $filters->orderId !== null ? (string) $filters->orderId : '',
            'resolution_options' => $this->resolutionLabels->options(),
            'selected_resolution' => $filters->resolution ?? '',
            'search_input' => $filters->search,

            'sort_field' => $filters->sort,
            'sort_direction' => $filters->direction,
            'period' => $filters->period,

            'quick_chips' => $this->buildQuickChips($filters),
        ];
    }

    /**
     * @param array<int, array{id: int, code: string, effective_code: string, title: string, color: string}> $statusIndex
     *
     * @return list<array{key: string, label: string, value: string, remove_url: string}>
     */
    private function buildActiveChips(OrderReturnFilters $filters, array $statusIndex): array
    {
        $chips = [];

        if ($filters->statusIds !== []) {
            $chips[] = $this->chip(
                $filters,
                OrderReturnFilters::KEY_STATUS_IDS,
                $this->translator->trans('Status'),
                $this->joinTitles($filters->statusIds, $statusIndex),
            );
        }

        $rangeLabel = $this->dateRangeLabel($filters->createdFrom, $filters->createdTo);
        if ($rangeLabel !== '') {
            $chips[] = $this->chip(
                $filters,
                OrderReturnFilters::KEY_CREATED_RANGE,
                $this->translator->trans('Date'),
                $rangeLabel,
            );
        }

        if ($filters->customerId !== null) {
            $chips[] = $this->chip(
                $filters,
                OrderReturnFilters::KEY_CUSTOMER_ID,
                $this->translator->trans('Customer'),
                $this->customerLabel($filters->customerId),
            );
        }

        if ($filters->orderId !== null) {
            $chips[] = $this->chip(
                $filters,
                OrderReturnFilters::KEY_ORDER_ID,
                $this->translator->trans('Order'),
                $this->orderLabel($filters->orderId),
            );
        }

        if ($filters->resolution !== null) {
            $chips[] = $this->chip(
                $filters,
                OrderReturnFilters::KEY_RESOLUTION,
                $this->translator->trans('Expected resolution'),
                $this->resolutionLabels->label($filters->resolution),
            );
        }

        if ($filters->search !== '') {
            $chips[] = $this->chip(
                $filters,
                OrderReturnFilters::KEY_SEARCH,
                $this->translator->trans('Search'),
                $filters->search,
            );
        }

        return $chips;
    }

    /**
     * @return array{key: string, label: string, value: string, remove_url: string}
     */
    private function chip(OrderReturnFilters $filters, string $key, string $label, string $value): array
    {
        $afterRemoval = $filters->withoutFilter($key);

        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'remove_url' => $this->urls->generate(self::LIST_ROUTE, $afterRemoval->toQueryParams()),
        ];
    }

    /**
     * @param list<int>                                                                                      $ids
     * @param array<int, array{id: int, code: string, effective_code: string, title: string, color: string}> $index
     */
    private function joinTitles(array $ids, array $index): string
    {
        $titles = [];
        foreach ($ids as $id) {
            if (isset($index[$id])) {
                $titles[] = $index[$id]['title'];
            }
        }

        return implode(', ', $titles);
    }

    /**
     * @param list<array{id: int, code: string, effective_code: string, title: string, color: string}> $items
     *
     * @return array<int, array{id: int, code: string, effective_code: string, title: string, color: string}>
     */
    private function indexById(array $items): array
    {
        $index = [];
        foreach ($items as $item) {
            $index[$item['id']] = $item;
        }

        return $index;
    }

    private function customerLabel(int $customerId): string
    {
        $customer = CustomerQuery::create()->findPk($customerId);

        if ($customer === null) {
            return '#'.$customerId;
        }

        return trim($customer->getFirstname().' '.$customer->getLastname()) ?: (string) $customer->getEmail();
    }

    private function orderLabel(int $orderId): string
    {
        $order = OrderQuery::create()->findPk($orderId);

        return $order !== null ? (string) $order->getRef() : '#'.$orderId;
    }

    private function dateRangeLabel(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): string
    {
        if ($from === null && $to === null) {
            return '';
        }

        if ($from !== null && $to !== null) {
            return $from->format('d/m/Y').' - '.$to->format('d/m/Y');
        }

        if ($from !== null) {
            return $this->translator->trans('from %date%', ['%date%' => $from->format('d/m/Y')]);
        }

        return $this->translator->trans('until %date%', ['%date%' => $to->format('d/m/Y')]);
    }

    private function countAdvancedFilters(OrderReturnFilters $filters): int
    {
        $count = 0;

        if ($filters->statusIds !== []) {
            ++$count;
        }
        if ($filters->createdFrom !== null || $filters->createdTo !== null) {
            ++$count;
        }
        if ($filters->customerId !== null) {
            ++$count;
        }
        if ($filters->orderId !== null) {
            ++$count;
        }
        if ($filters->resolution !== null) {
            ++$count;
        }

        return $count;
    }

    /**
     * @return list<array{code: string, label: string, icon: string, url: string, active: bool}>
     */
    private function buildQuickChips(OrderReturnFilters $filters): array
    {
        $chips = [];
        foreach (OrderReturnFilters::QUICK_CHIPS as $definition) {
            $code = $definition['code'];
            $afterApply = $filters->cloneForPeriodShortcut($code);
            $chips[] = [
                'code' => $code,
                'label' => $this->translator->trans($definition['label']),
                'icon' => $definition['icon'],
                'url' => $this->urls->generate(self::LIST_ROUTE, $afterApply->toQueryParams()),
                'active' => $filters->period === $code,
            ];
        }

        return $chips;
    }
}
