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

namespace BackOfficeDefaultTwigBundle\Service\Customer;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Flattens a CustomerFilters value object into a Twig-friendly array (chips,
 * option lists, rehydration values). Keeps the template declarative.
 */
final readonly class CustomerFilterPresenter
{
    private const LIST_ROUTE = 'admin.customers';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private CustomerFilterCatalog $catalog,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(CustomerFilters $filters, string $locale): array
    {
        $countries = $this->catalog->referencedCountries($locale);
        $langs = $this->catalog->langs();
        $titles = $this->catalog->titles($locale);
        $tags = $this->catalog->tags();

        $countryIndex = $this->indexById($countries);
        $langIndex = $this->indexById($langs);
        $titleIndex = $this->indexById($titles);
        $tagIndex = $this->indexById($tags);

        return [
            'is_empty' => $filters->isEmpty(),
            'has_any_filter' => !$filters->isEmpty() || $filters->search !== '' || $filters->period !== CustomerFilters::PERIOD_ALL,
            'advanced_count' => $this->countAdvancedFilters($filters),
            'active_chips' => $this->buildActiveChips($filters, $countryIndex, $langIndex, $titleIndex, $tagIndex),
            'clear_all_url' => $this->urls->generate(self::LIST_ROUTE),

            'country_options' => $countries,
            'lang_options' => $langs,
            'title_options' => $titles,
            'tag_options' => $tags,
            'tristate_options' => $this->tristateOptions(),
            'newsletter_options' => $this->newsletterOptions(),
            'guest_options' => $this->guestOptions(),
            'vat_verified_options' => $this->vatVerifiedOptions(),

            'selected_country_id' => $filters->countryId,
            'selected_lang_ids' => $filters->langIds,
            'selected_title_ids' => $filters->titleIds,
            'selected_tag_ids' => $filters->tagIds,
            'newsletter_value' => $this->triStateValue($filters->newsletter),
            'guest_value' => $this->triStateValue($filters->guest),
            'vat_verified_value' => $this->triStateValue($filters->vatVerified),

            'created_from_input' => $filters->createdFrom?->format('Y-m-d') ?? '',
            'created_to_input' => $filters->createdTo?->format('Y-m-d') ?? '',
            'min_total_input' => $this->formatNumber($filters->minTotalSpent),
            'max_total_input' => $this->formatNumber($filters->maxTotalSpent),
            'min_orders_input' => $filters->minOrderCount !== null ? (string) $filters->minOrderCount : '',
            'max_orders_input' => $filters->maxOrderCount !== null ? (string) $filters->maxOrderCount : '',
            'total_slider' => $this->catalog->totalSpentBounds(),
            'orders_slider' => $this->catalog->orderCountBounds(),
            'phone_input' => $filters->phone,
            'search_input' => $filters->search,

            'sort_field' => $filters->sort,
            'sort_direction' => $filters->direction,
            'period' => $filters->period,

            'quick_chips' => $this->buildQuickChips($filters),
        ];
    }

    /**
     * @param array<int, array{id: int, title: string, flag: string, iso: string}> $countryIndex
     * @param array<int, array{id: int, title: string, code: string}>              $langIndex
     * @param array<int, array{id: int, title: string}>                            $titleIndex
     *
     * @return list<array{key: string, label: string, value: string, remove_url: string}>
     */
    private function buildActiveChips(
        CustomerFilters $filters,
        array $countryIndex,
        array $langIndex,
        array $titleIndex,
        array $tagIndex,
    ): array {
        $chips = [];

        if ($filters->newsletter !== null) {
            $chips[] = $this->chip(
                $filters,
                CustomerFilters::KEY_NEWSLETTER,
                $this->translator->trans('Newsletter'),
                $filters->newsletter
                    ? $this->translator->trans('Subscribed')
                    : $this->translator->trans('Unsubscribed'),
            );
        }

        if ($filters->guest !== null) {
            $chips[] = $this->chip(
                $filters,
                CustomerFilters::KEY_GUEST,
                $this->translator->trans('Guest checkout'),
                $filters->guest
                    ? $this->translator->trans('Guest')
                    : $this->translator->trans('Registered customer'),
            );
        }

        if ($filters->vatVerified !== null) {
            $chips[] = $this->chip(
                $filters,
                CustomerFilters::KEY_VAT_VERIFIED,
                $this->translator->trans('VAT verification'),
                $filters->vatVerified
                    ? $this->translator->trans('Verified address')
                    : $this->translator->trans('No verified address'),
            );
        }

        $rangeLabel = $this->dateRangeLabel($filters->createdFrom, $filters->createdTo);
        if ($rangeLabel !== '') {
            $chips[] = $this->chip(
                $filters,
                CustomerFilters::KEY_CREATED_RANGE,
                $this->translator->trans('Registered'),
                $rangeLabel,
            );
        }

        $spentLabel = $this->amountRangeLabel($filters->minTotalSpent, $filters->maxTotalSpent);
        if ($spentLabel !== '') {
            $chips[] = $this->chip(
                $filters,
                CustomerFilters::KEY_TOTAL_SPENT,
                $this->translator->trans('Total spent'),
                $spentLabel,
            );
        }

        $orderCountLabel = $this->intRangeLabel($filters->minOrderCount, $filters->maxOrderCount);
        if ($orderCountLabel !== '') {
            $chips[] = $this->chip(
                $filters,
                CustomerFilters::KEY_ORDER_COUNT,
                $this->translator->trans('Orders'),
                $orderCountLabel,
            );
        }

        if ($filters->countryId !== null && isset($countryIndex[$filters->countryId])) {
            $country = $countryIndex[$filters->countryId];
            $chips[] = $this->chip(
                $filters,
                CustomerFilters::KEY_COUNTRY,
                $this->translator->trans('Country'),
                trim($country['flag'].' '.$country['title']),
            );
        }

        if ($filters->langIds !== []) {
            $chips[] = $this->chip(
                $filters,
                CustomerFilters::KEY_LANG_IDS,
                $this->translator->trans('Language'),
                $this->joinTitles($filters->langIds, $langIndex),
            );
        }

        if ($filters->titleIds !== []) {
            $chips[] = $this->chip(
                $filters,
                CustomerFilters::KEY_TITLE_IDS,
                $this->translator->trans('Customer title'),
                $this->joinTitles($filters->titleIds, $titleIndex),
            );
        }

        if ($filters->tagIds !== []) {
            $chips[] = $this->chip(
                $filters,
                CustomerFilters::KEY_TAG_IDS,
                $this->translator->trans('Tags'),
                $this->joinTitles($filters->tagIds, $tagIndex),
            );
        }

        if ($filters->phone !== '') {
            $chips[] = $this->chip(
                $filters,
                CustomerFilters::KEY_PHONE,
                $this->translator->trans('Phone'),
                $filters->phone,
            );
        }

        if ($filters->search !== '') {
            $chips[] = $this->chip(
                $filters,
                CustomerFilters::KEY_SEARCH,
                $this->translator->trans('Search'),
                $filters->search,
            );
        }

        return $chips;
    }

    /**
     * @return array{key: string, label: string, value: string, remove_url: string}
     */
    private function chip(CustomerFilters $filters, string $key, string $label, string $value): array
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
     * @param list<int>                                 $ids
     * @param array<int, array{id: int, title: string}> $index
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
     * @template TItem of array{id: int}
     *
     * @param list<TItem> $items
     *
     * @return array<int, TItem>
     */
    private function indexById(array $items): array
    {
        $index = [];
        foreach ($items as $item) {
            $index[$item['id']] = $item;
        }

        return $index;
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

    private function amountRangeLabel(?float $min, ?float $max): string
    {
        if ($min === null && $max === null) {
            return '';
        }

        if ($min !== null && $max !== null) {
            return $this->formatMoney($min).' - '.$this->formatMoney($max);
        }

        if ($min !== null) {
            return '>= '.$this->formatMoney($min);
        }

        return '<= '.$this->formatMoney($max);
    }

    private function intRangeLabel(?int $min, ?int $max): string
    {
        if ($min === null && $max === null) {
            return '';
        }

        if ($min !== null && $max !== null) {
            return $min.' - '.$max;
        }

        return $min !== null ? '>= '.$min : '<= '.$max;
    }

    private function formatNumber(?float $value): string
    {
        if ($value === null) {
            return '';
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 2, ',', ' ').' €';
    }

    private function triStateValue(?bool $value): string
    {
        if ($value === null) {
            return '';
        }

        return $value ? CustomerFilters::TRISTATE_WITH : CustomerFilters::TRISTATE_WITHOUT;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function tristateOptions(): array
    {
        return [
            ['value' => '', 'label' => $this->translator->trans('All')],
            ['value' => CustomerFilters::TRISTATE_WITH, 'label' => $this->translator->trans('With')],
            ['value' => CustomerFilters::TRISTATE_WITHOUT, 'label' => $this->translator->trans('Without')],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function newsletterOptions(): array
    {
        return [
            ['value' => '', 'label' => $this->translator->trans('All')],
            ['value' => CustomerFilters::TRISTATE_WITH, 'label' => $this->translator->trans('Subscribed')],
            ['value' => CustomerFilters::TRISTATE_WITHOUT, 'label' => $this->translator->trans('Unsubscribed')],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function guestOptions(): array
    {
        return [
            ['value' => '', 'label' => $this->translator->trans('All')],
            ['value' => CustomerFilters::TRISTATE_WITH, 'label' => $this->translator->trans('Guest')],
            ['value' => CustomerFilters::TRISTATE_WITHOUT, 'label' => $this->translator->trans('Registered customer')],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function vatVerifiedOptions(): array
    {
        return [
            ['value' => '', 'label' => $this->translator->trans('All')],
            ['value' => CustomerFilters::TRISTATE_WITH, 'label' => $this->translator->trans('Verified address')],
            ['value' => CustomerFilters::TRISTATE_WITHOUT, 'label' => $this->translator->trans('No verified address')],
        ];
    }

    private function countAdvancedFilters(CustomerFilters $filters): int
    {
        $count = 0;

        if ($filters->newsletter !== null) {
            ++$count;
        }
        if ($filters->guest !== null) {
            ++$count;
        }
        if ($filters->vatVerified !== null) {
            ++$count;
        }
        if ($filters->createdFrom !== null || $filters->createdTo !== null) {
            ++$count;
        }
        if ($filters->minTotalSpent !== null || $filters->maxTotalSpent !== null) {
            ++$count;
        }
        if ($filters->minOrderCount !== null || $filters->maxOrderCount !== null) {
            ++$count;
        }
        if ($filters->countryId !== null) {
            ++$count;
        }
        if ($filters->langIds !== []) {
            ++$count;
        }
        if ($filters->titleIds !== []) {
            ++$count;
        }
        if ($filters->tagIds !== []) {
            ++$count;
        }
        if ($filters->phone !== '') {
            ++$count;
        }

        return $count;
    }

    /**
     * @return list<array{code: string, label: string, icon: string, url: string, active: bool}>
     */
    private function buildQuickChips(CustomerFilters $filters): array
    {
        $chips = [];
        foreach (CustomerFilters::QUICK_CHIPS as $definition) {
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
