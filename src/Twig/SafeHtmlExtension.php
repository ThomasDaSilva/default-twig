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

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `bo_safe_html`: HTML written by a module (the description of an export, of an
 * import) shown as HTML, without what would run in the page of the administrator
 * reading it (scripts, event attributes, javascript: links).
 */
final class SafeHtmlExtension extends AbstractExtension
{
    private ?HtmlSanitizer $sanitizer = null;

    public function getFilters(): array
    {
        return [
            new TwigFilter('bo_safe_html', $this->sanitize(...), ['is_safe' => ['html']]),
        ];
    }

    public function sanitize(?string $html): string
    {
        $this->sanitizer ??= new HtmlSanitizer((new HtmlSanitizerConfig())->allowSafeElements());

        return $this->sanitizer->sanitize((string) $html);
    }
}
