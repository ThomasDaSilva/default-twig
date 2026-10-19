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

namespace BackOfficeDefaultTwigBundle\Service;

/**
 * Lets a screen use what a recent core brings and still run on an older one. A
 * method_exists() guard written on a typed object reads "always true" to the static
 * analysis of a recent core and "always false" to that of an older one; on the same object
 * seen as any object, it reads the same on both.
 *
 * Its guards go once the theme requires a core that estimates deliveries (thelia/core 3.3):
 * meanwhile, a misspelt method name in one of them fails silently, unseen by the analysis.
 */
final class CoreCompatibility
{
    public static function anyObject(object $object): object
    {
        return $object;
    }

    public static function anyClass(string $class): string
    {
        return $class;
    }
}
