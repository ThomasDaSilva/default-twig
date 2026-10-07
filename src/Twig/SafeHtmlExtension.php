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
 * reading it, or reach out of it: only text formatting, lists and links are kept.
 * No style sheet, no image or medium loaded from elsewhere, no id or name that
 * would shadow the scripts of the back office.
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
        $this->sanitizer ??= new HtmlSanitizer(self::config());

        return $this->sanitizer->sanitize((string) $html);
    }

    private static function config(): HtmlSanitizerConfig
    {
        $config = (new HtmlSanitizerConfig())
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowElement('a', ['href', 'title']);

        foreach (['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'code', 'pre', 'blockquote', 'span'] as $element) {
            $config = $config->allowElement($element);
        }

        return $config;
    }
}
