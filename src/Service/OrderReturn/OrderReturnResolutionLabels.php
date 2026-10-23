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

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\OrderReturn;

/**
 * What the customer expects in return for the goods sent back, and the exchange they
 * wish for, as the merchant reads them on the sheet, in the queue and in its filter.
 */
final readonly class OrderReturnResolutionLabels
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function label(string $resolution): string
    {
        return match ($resolution) {
            OrderReturn::RESOLUTION_REFUND => $this->translator->trans('Refund'),
            OrderReturn::RESOLUTION_CREDIT => $this->translator->trans('Credit note'),
            OrderReturn::RESOLUTION_EXCHANGE => $this->translator->trans('Exchange'),
            default => '',
        };
    }

    /**
     * What the customer wishes to receive, shown under an exchange only: a wish kept on a
     * return switched to a refund since is not something the shop sends.
     */
    public function wishedExchange(OrderReturn $orderReturn): ?string
    {
        if ($orderReturn->getExpectedResolution() !== OrderReturn::RESOLUTION_EXCHANGE) {
            return null;
        }

        $wish = trim((string) $orderReturn->getExpectedExchange());

        return $wish !== '' ? $wish : null;
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    public function options(): array
    {
        return array_map(
            fn (string $resolution): array => ['code' => $resolution, 'label' => $this->label($resolution)],
            OrderReturn::RESOLUTIONS,
        );
    }
}
