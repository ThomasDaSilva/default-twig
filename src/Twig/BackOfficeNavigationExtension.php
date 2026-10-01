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

namespace BackOfficeDefaultTwigBundle\Twig;

use Thelia\Core\Template\BackOffice\BackOfficeNavigation;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `backoffice_section_visible(section)`: whether a navigation section is shown,
 * as the modules installed decide (a CMS hides the folders, for one). The permission to
 * view the section is checked apart, with is_granted().
 */
final class BackOfficeNavigationExtension extends AbstractExtension
{
    public function __construct(private readonly BackOfficeNavigation $backOfficeNavigation)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('backoffice_section_visible', $this->backOfficeNavigation->isSectionVisible(...)),
        ];
    }
}
