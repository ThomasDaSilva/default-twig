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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * The log of a failed action names the exception by its class, code and place: the
 * text of a database error quotes the values of a customer, and is kept longer.
 */
final class AdminFormErrorRendererTest extends TestCase
{
    public function testTheLogOfAFailureNeverQuotesTheDatabase(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };

        (new AdminFormErrorRenderer(new RequestStack(), new IdentityTranslator(), $logger))
            ->fail('Customer update', new \PDOException("SQLSTATE[23000]: Duplicate entry 'buyer@example.com' for key 'email'"));

        self::assertCount(1, $logger->lines);
        self::assertStringNotContainsString('buyer@example.com', $logger->lines[0]);
        self::assertStringContainsString('PDOException', $logger->lines[0]);
    }
}
