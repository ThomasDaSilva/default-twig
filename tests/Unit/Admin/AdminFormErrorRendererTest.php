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
        $logger = $this->logger();

        (new AdminFormErrorRenderer(new RequestStack(), new IdentityTranslator(), $logger))
            ->fail('Customer update', new \PDOException("SQLSTATE[23000]: Duplicate entry 'buyer@example.com' for key 'email'"));

        self::assertCount(1, $logger->lines);
        self::assertStringNotContainsString('buyer@example.com', $logger->lines[0]);
        self::assertStringContainsString('PDOException', $logger->lines[0]);
        self::assertSame(['error'], $logger->levels);
    }

    /**
     * A refusal written for the administrator, with nothing broken behind it, is a
     * warning in the log, not an error to wake someone for.
     */
    public function testARefusalWithoutAnExceptionIsAWarning(): void
    {
        $logger = $this->logger();

        (new AdminFormErrorRenderer(new RequestStack(), new IdentityTranslator(), $logger))
            ->refuse('Order status change', 'This status cannot follow the current one.');

        self::assertSame(['warning'], $logger->levels);
        self::assertStringContainsString('Order status change', $logger->lines[0]);
        self::assertStringContainsString('This status cannot follow the current one.', $logger->lines[0]);
    }

    /**
     * @return AbstractLogger&object{lines: list<string>, levels: list<string>}
     */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            /** @var list<string> */
            public array $levels = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
                $this->levels[] = (string) $level;
            }
        };
    }
}
