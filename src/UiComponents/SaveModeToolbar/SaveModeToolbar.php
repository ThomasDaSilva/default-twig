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

namespace BackOfficeDefaultTwigBundle\UiComponents\SaveModeToolbar;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'BoSaveModeToolbar', template: '@BackOfficeDefaultTwig/components/SaveModeToolbar/SaveModeToolbar.html.twig')]
final class SaveModeToolbar
{
    public ?string $closeUrl = null;

    public string $closeLabel = 'Close';

    public string $closeVariant = 'outline-secondary';

    public ?string $closeIcon = null;

    /** @var array<string, string> */
    public array $closeAttributes = [];

    public bool $closeFirst = true;

    public string $saveModeField = 'save_mode';

    public string $saveLabel = 'Save';

    public string $saveCloseLabel = 'Save and close';

    public string $saveIcon = 'bi-check2';

    public string $saveCloseIcon = 'bi-check2-all';

    public string $saveVariant = 'outline-primary';

    public string $saveCloseVariant = 'primary';

    public bool $iconAfterLabel = false;

    public bool $showSave = true;

    public bool $showSaveClose = true;

    public string $align = 'end';

    public string $wrapperClass = 'mt-3';

    public ?string $testid = null;
}
