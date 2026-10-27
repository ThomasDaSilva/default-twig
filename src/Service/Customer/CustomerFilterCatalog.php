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

use BackOfficeDefaultTwigBundle\Repository\CountryRepository;
use BackOfficeDefaultTwigBundle\Repository\CustomerRepository;
use BackOfficeDefaultTwigBundle\Service\I18n\CountryFlag;
use BackOfficeDefaultTwigBundle\Service\Tag\TagSwatch;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\TagQuery;

/**
 * Locale-aware option lists for the customer filter form. Each list is fetched
 * via its dedicated Repository and memoised per locale for the request.
 */
final class CustomerFilterCatalog
{
    /** @var array<string, list<array{id: int, title: string, flag: string, iso: string}>> */
    private array $countriesByLocale = [];

    /** @var list<array{id: int, title: string, code: string}>|null */
    private ?array $langs = null;

    /** @var array<string, list<array{id: int, title: string}>> */
    private array $titlesByLocale = [];

    /** @var list<array{id: int, title: string, color: string}>|null */
    private ?array $tags = null;

    /** @var array{min: float, max: float, step: int}|null */
    private ?array $totalSpentBounds = null;

    /** @var array{min: int, max: int, step: int}|null */
    private ?array $orderCountBounds = null;

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CountryRepository $countries,
        private readonly TagSwatch $swatch,
    ) {
    }

    /**
     * Decorated with emoji flags. Restricted to countries actually used in at
     * least one customer address, so the dropdown stays meaningful.
     *
     * @return list<array{id: int, title: string, flag: string, iso: string}>
     */
    public function referencedCountries(string $locale): array
    {
        if (isset($this->countriesByLocale[$locale])) {
            return $this->countriesByLocale[$locale];
        }

        $ids = $this->customers->findReferencedCountryIds();
        $countries = $this->countries->findByIdsLocalized($ids, $locale);

        $items = array_map(
            static fn (array $country): array => [
                'id' => $country['id'],
                'title' => $country['title'],
                'flag' => CountryFlag::of($country['iso']),
                'iso' => $country['iso'],
            ],
            $countries,
        );

        return $this->countriesByLocale[$locale] = $items;
    }

    /**
     * @return list<array{id: int, title: string, code: string}>
     */
    public function langs(): array
    {
        return $this->langs ??= $this->customers->findLangs();
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    public function titles(string $locale): array
    {
        return $this->titlesByLocale[$locale] ??= $this->customers->findTitlesLocalized($locale);
    }

    /**
     * The whole tag vocabulary, not only the tags in use: an administrator
     * filtering on a tag nobody carries deserves an empty list rather than an
     * option that silently disappeared.
     *
     * @return list<array{id: int, title: string, color: string}>
     */
    public function tags(): array
    {
        if ($this->tags !== null) {
            return $this->tags;
        }

        $items = [];

        foreach (TagQuery::create()->orderByLabel(Criteria::ASC)->find() as $tag) {
            $items[] = [
                'id' => (int) $tag->getId(),
                'title' => (string) $tag->getLabel(),
                // Checked here so the template never has to: TagSwatch is the one
                // place that decides whether a tag colour may reach a style
                // attribute, and it drops anything that is not a colour.
                'color' => $this->swatch->color($tag->getColorCode()) ?? '',
            ];
        }

        return $this->tags = $items;
    }

    /**
     * @return array{min: float, max: float, step: int}
     */
    public function totalSpentBounds(): array
    {
        return $this->totalSpentBounds ??= $this->customers->getTotalSpentBounds();
    }

    /**
     * @return array{min: int, max: int, step: int}
     */
    public function orderCountBounds(): array
    {
        return $this->orderCountBounds ??= $this->customers->getOrderCountBounds();
    }
}
