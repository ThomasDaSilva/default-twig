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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Twig;

use BackOfficeDefaultTwigBundle\Twig\BackOfficeNavigationExtension;
use PHPUnit\Framework\TestCase;
use Thelia\Core\Template\BackOffice\BackOfficeNavigation;
use Thelia\Core\Template\BackOffice\NavigationSectionVoterInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/** The navigation asks the voters of the installed modules whether to draw a section. */
final class BackOfficeNavigationExtensionTest extends TestCase
{
    public function testASectionNoVoterHidesIsVisible(): void
    {
        self::assertSame('folder:yes catalog:yes', $this->render([]));
    }

    public function testASectionAVoterHidesIsNotVisible(): void
    {
        $hidesFolders = new class implements NavigationSectionVoterInterface {
            public function hidesSection(string $section): bool
            {
                return 'folder' === $section;
            }
        };

        self::assertSame('folder:no catalog:yes', $this->render([$hidesFolders]));
    }

    /**
     * @param list<NavigationSectionVoterInterface> $voters
     */
    private function render(array $voters): string
    {
        $twig = new Environment(new ArrayLoader([
            'nav' => "{% for section in ['folder', 'catalog'] %}{{ section }}:{{ backoffice_section_visible(section) ? 'yes' : 'no' }}{{ loop.last ? '' : ' ' }}{% endfor %}",
        ]));
        $twig->addExtension(new BackOfficeNavigationExtension(new BackOfficeNavigation($voters)));

        return $twig->render('nav');
    }
}
