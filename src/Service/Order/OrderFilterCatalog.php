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

namespace BackOfficeDefaultTwigBundle\Service\Order;

use BackOfficeDefaultTwigBundle\Repository\CountryRepository;
use BackOfficeDefaultTwigBundle\Repository\ModuleRepository;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Service\I18n\CountryFlag;
use Thelia\Module\BaseModule;

/**
 * Locale-aware option lists for the order filter form. Each list is fetched
 * via its dedicated Repository and memoised per locale for the request.
 */
final class OrderFilterCatalog
{
    /** @var array<string, list<array{id: int, title: string, code: string, color: string}>> */
    private array $statusesByLocale = [];

    /** @var array<string, list<array{id: int, title: string, code: string}>> */
    private array $paymentModulesByLocale = [];

    /** @var array<string, list<array{id: int, title: string, code: string}>> */
    private array $deliveryModulesByLocale = [];

    /** @var array<string, list<array{id: int, title: string, flag: string, iso: string}>> */
    private array $countriesByLocale = [];

    /** @var array{min: float, max: float, step: int}|null */
    private ?array $amountBounds = null;

    /** @var array{min: int, max: int, step: int}|null */
    private ?array $itemsBounds = null;

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ModuleRepository $modules,
        private readonly CountryRepository $countries,
        private readonly OrderSaleUnits $saleUnits,
    ) {
    }

    /**
     * Empty on a single shop: the filter and the column are then left out of the list.
     *
     * @return list<array{id: int, code: string, title: string, active: bool}>
     */
    public function saleUnits(string $locale): array
    {
        return $this->saleUnits->options($locale);
    }

    /**
     * @return list<array{id: int, title: string, code: string, color: string}>
     */
    public function statuses(string $locale): array
    {
        return $this->statusesByLocale[$locale] ??= $this->orders->findStatusesLocalized($locale);
    }

    /**
     * @return list<array{id: int, title: string, code: string}>
     */
    public function paymentModules(string $locale): array
    {
        return $this->paymentModulesByLocale[$locale]
            ??= $this->modules->findActiveByType(BaseModule::PAYMENT_MODULE_TYPE, $locale);
    }

    /**
     * @return list<array{id: int, title: string, code: string}>
     */
    public function deliveryModules(string $locale): array
    {
        return $this->deliveryModulesByLocale[$locale]
            ??= $this->modules->findActiveByType(BaseModule::DELIVERY_MODULE_TYPE, $locale);
    }

    /**
     * Decorated with emoji flags. Restricted to countries that actually appear
     * on at least one delivery address, so the dropdown stays meaningful.
     *
     * @return list<array{id: int, title: string, flag: string, iso: string}>
     */
    public function deliveryCountries(string $locale): array
    {
        if (isset($this->countriesByLocale[$locale])) {
            return $this->countriesByLocale[$locale];
        }

        $referencedIds = $this->orders->findReferencedDeliveryCountryIds();
        $countries = $this->countries->findByIdsLocalized($referencedIds, $locale);

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
     * @return array{min: float, max: float, step: int}
     */
    public function amountBounds(): array
    {
        return $this->amountBounds ??= $this->orders->getAmountBounds();
    }

    /**
     * @return array{min: int, max: int, step: int}
     */
    public function itemsBounds(): array
    {
        return $this->itemsBounds ??= $this->orders->getItemsBounds();
    }
}
