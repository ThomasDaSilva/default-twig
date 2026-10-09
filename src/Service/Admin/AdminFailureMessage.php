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
use Propel\Runtime\Exception\ExceptionInterface as PropelExceptionInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Mailer\TransportCredentials;
use Twig\Error\LoaderError;

/**
 * What the administrator reads of a failure: what the shop says of a refusal, never the
 * words of the database, of the file system or of PHP, which quote the rows, values and
 * paths of the server they failed on.
 * The log keeps the detail.
 */
final class AdminFailureMessage
{
    public const SERVER_ERROR = 'The action failed because of a server error. The details are in the server log.';

    public static function of(\Throwable $exception, TranslatorInterface $translator): string
    {
        for ($cause = $exception; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException
                || $cause instanceof PropelExceptionInterface
                || $cause instanceof DbalException
                || $cause instanceof IOExceptionInterface
                || $cause instanceof FileException
                || $cause instanceof LoaderError
                // A warning or a notice PHP raised; the core raises refusals of its own
                // as \ErrorException too, at the error severity, meant to be read.
                || ($cause instanceof \ErrorException && \E_ERROR !== $cause->getSeverity())
                || $cause instanceof \Error
            ) {
                return $translator->trans(self::SERVER_ERROR);
            }
        }

        // A refusal of a module may quote the address of a mail server it failed on.
        return TransportCredentials::hide($exception->getMessage());
    }
}
