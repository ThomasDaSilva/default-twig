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

namespace BackOfficeDefaultTwigBundle\Service\Admin;

use Doctrine\DBAL\Exception as DbalException;
use Propel\Runtime\Exception\PropelException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the administrator reads of a failure: what the shop says of a refusal, never the
 * words of the database or of PHP, which quote the rows and the values they failed on.
 * The log keeps the detail.
 */
final class AdminFailureMessage
{
    public const SERVER_ERROR = 'The action failed because of a server error. The details are in the server log.';

    public static function of(\Throwable $exception, TranslatorInterface $translator): string
    {
        for ($cause = $exception; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException
                || $cause instanceof PropelException
                || $cause instanceof DbalException
                || $cause instanceof \Error
            ) {
                return $translator->trans(self::SERVER_ERROR);
            }
        }

        return $exception->getMessage();
    }
}
