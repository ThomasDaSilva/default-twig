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

namespace BackOfficeDefaultTwigBundle\DTO\Customer;

/**
 * What the customer sheet shows around the customer's own record.
 *
 * `kpis` is null, and the orders section is left out, when the administrator may not see
 * the orders: the figures are computed from them. `returnCount` is null, and the returns
 * section is left out, while the shop has returns off or for an administrator who may not
 * see them.
 */
final readonly class CustomerOverview
{
    public function __construct(
        public ?CustomerKpis $kpis,
        public bool $canViewOrders,
        public ?\DateTimeImmutable $customerSince,
        public string $customerAge,
        public ?int $returnCount,
    ) {
    }
}
