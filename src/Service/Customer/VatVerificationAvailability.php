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

use Thelia\Domain\Legal\Service\NullVatNumberVerifier;
use Thelia\Domain\Legal\Service\VatNumberVerifierInterface;

/**
 * Whether a module able to verify a VAT number is installed.
 *
 * The core always provides a verifier so the container builds, and that one
 * answers "undetermined" to everything: offering a re-verification button
 * against it would promise the merchant a check nobody performs.
 */
final readonly class VatVerificationAvailability
{
    public function __construct(
        private VatNumberVerifierInterface $verifier,
    ) {
    }

    public function isAvailable(): bool
    {
        return !$this->verifier instanceof NullVatNumberVerifier;
    }
}
