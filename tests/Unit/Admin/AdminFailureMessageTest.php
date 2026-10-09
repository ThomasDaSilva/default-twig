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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminFailureMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Exception\InvalidArgumentException as DbalInvalidArgumentException;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Exception\RuntimeException as PropelRuntimeException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Translation\IdentityTranslator;

final class AdminFailureMessageTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function serverFailures(): iterable
    {
        yield 'a database error' => [new \PDOException("SQLSTATE[23000]: Duplicate entry 'buyer@example.com'")];
        yield 'a query that failed' => [new PropelException("Unable to execute INSERT statement [INSERT INTO customer (email) VALUES ('buyer@example.com')]")];
        yield 'an error of PHP' => [new \TypeError('Argument #1 ($email) must be of type string, array given')];
        yield 'a database error behind a refusal' => [new \RuntimeException('Could not save.', 0, new \PDOException("Duplicate entry 'buyer@example.com'"))];
        yield 'a DBAL error' => [new DbalInvalidArgumentException("Duplicate entry 'buyer@example.com'")];
        yield 'any failure of Propel' => [new PropelRuntimeException('Unable to find the TableMap of customer in /var/www/html/var/propel/prod')];
        yield 'a file the server could not handle' => [new IOException('Failed to remove directory "/var/www/html/var/cache/prod/twig": rmdir(): Directory not empty')];
        yield 'a warning of PHP turned into an exception' => [new \ErrorException('file_put_contents(/var/www/html/local/media/x.png): Failed to open stream')];
    }

    #[DataProvider('serverFailures')]
    public function testAServerFailureReadsAsAServerError(\Throwable $failure): void
    {
        self::assertSame(AdminFailureMessage::SERVER_ERROR, AdminFailureMessage::of($failure, new IdentityTranslator()));
    }

    /**
     * A module's refusal may still quote the address of the mail server it failed on.
     */
    public function testTheCredentialsARefusalQuotesAreHidden(): void
    {
        $message = AdminFailureMessage::of(new \RuntimeException('Connection refused by smtp://shop:secret@mail.example.com:587'), new IdentityTranslator());

        self::assertStringNotContainsString('secret', $message);
        self::assertStringContainsString('mail.example.com', $message);
    }

    public function testWhatTheShopSaysOfARefusalIsShown(): void
    {
        self::assertSame('The default currency cannot be deleted.', AdminFailureMessage::of(new \RuntimeException('The default currency cannot be deleted.'), new IdentityTranslator()));
    }
}
