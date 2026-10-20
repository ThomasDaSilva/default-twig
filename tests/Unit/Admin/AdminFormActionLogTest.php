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

use BackOfficeDefaultTwigBundle\Form\Legacy\LegacyFormEventBridge;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormValidator;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Tools\TokenProvider;

/**
 * The administration log tells what was done; it never decides whether it was. An action
 * whose event went through is reported done even when its log line cannot be written.
 */
final class AdminFormActionLogTest extends TestCase
{
    public function testAnActionThatWentThroughIsReportedDoneWhenItsLogLineFails(): void
    {
        $errorRenderer = $this->createMock(AdminFormErrorRenderer::class);
        $errorRenderer->expects(self::never())->method('setup');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('could not be written'));

        $response = $this->action($errorRenderer, $logger)->tokenAction(
            resource: 'admin.order',
            access: 'UPDATE',
            request: new Request(request: ['_token' => 'valid']),
            event: new \stdClass(),
            eventName: 'action.done',
            actionLabel: 'Done',
            successRoute: 'done',
            describeForLog: static function (): never {
                throw new \RuntimeException('The log table is gone.');
            },
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/done', $response->getTargetUrl());
    }

    public function testAFailedActionIsWrittenWithTheReasonTheAdministratorRead(): void
    {
        $adminLogger = $this->createMock(AdminLogger::class);
        $adminLogger->expects(self::once())->method('log')->with('admin.order', 'UPDATE', 'Failed: Declined by the bank', 7);
        $errorRenderer = $this->createMock(AdminFormErrorRenderer::class);
        $errorRenderer->method('isShown')->willReturn(true);

        $this->failingAction($errorRenderer, $this->createMock(LoggerInterface::class), $adminLogger, new \RuntimeException('Declined by the bank'), static fn (object $event, string $reason): array => ['Failed: '.$reason, 7]);
    }

    public function testAFailureTheAdministratorDoesNotReadIsWrittenWithoutItsMessage(): void
    {
        $adminLogger = $this->createMock(AdminLogger::class);
        $adminLogger->expects(self::once())->method('log')->with(self::anything(), self::anything(), self::logicalAnd(self::stringContains('technical failure'), self::logicalNot(self::stringContains('SQLSTATE'))));
        $errorRenderer = $this->createMock(AdminFormErrorRenderer::class);
        $errorRenderer->method('isShown')->willReturn(false);

        $this->failingAction($errorRenderer, $this->createMock(LoggerInterface::class), $adminLogger, new \RuntimeException('SQLSTATE[23000] on order 7'), static fn (object $event, string $reason): array => ['Failed: '.$reason, 7]);
    }

    public function testARequestWithoutTheFormTokenIsNotWritten(): void
    {
        $adminLogger = $this->createMock(AdminLogger::class);
        $adminLogger->expects(self::never())->method('log');

        $this->failingAction($this->createMock(AdminFormErrorRenderer::class), $this->createMock(LoggerInterface::class), $adminLogger, new TokenAuthenticationException('Bad token'), static fn (object $event, string $reason): array => ['Failed', 7]);
    }

    public function testALogLineThatCannotBeWrittenLeavesTheFailureAsItWas(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('could not be written'));
        $errorRenderer = $this->createMock(AdminFormErrorRenderer::class);
        $errorRenderer->method('isShown')->willReturn(true);

        $response = $this->failingAction($errorRenderer, $logger, $this->createMock(AdminLogger::class), new \RuntimeException('Declined'), static function (): never {
            throw new \LogicException('The log table is gone.');
        });

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    private function failingAction(AdminFormErrorRenderer $errorRenderer, LoggerInterface $logger, AdminLogger $adminLogger, \Throwable $failure, callable $describeFailureForLog): \Symfony\Component\HttpFoundation\Response
    {
        $access = $this->createMock(AdminAccessChecker::class);
        $access->method('check')->willReturn(null);
        $tokens = $this->createMock(TokenProvider::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        if ($failure instanceof TokenAuthenticationException) {
            $tokens->method('checkToken')->willThrowException($failure);
        } else {
            $tokens->method('checkToken')->willReturn(true);
            $events->method('dispatch')->willThrowException($failure);
        }

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/sheet');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $action = new AdminFormAction($access, $this->createMock(AdminFormValidator::class), $adminLogger, $errorRenderer, $events, $urls, $translator, $tokens, new LegacyFormEventBridge(new EventDispatcher(), new RequestStack()), $logger);

        return $action->tokenAction(
            resource: 'admin.order',
            access: 'UPDATE',
            request: new Request(request: ['_token' => 'valid']),
            event: new \stdClass(),
            eventName: 'action.fail',
            actionLabel: 'Fail',
            successRoute: 'sheet',
            describeFailureForLog: $describeFailureForLog,
        );
    }

    private function action(AdminFormErrorRenderer $errorRenderer, LoggerInterface $logger): AdminFormAction
    {
        $access = $this->createMock(AdminAccessChecker::class);
        $access->method('check')->willReturn(null);
        $tokens = $this->createMock(TokenProvider::class);
        $tokens->method('checkToken')->willReturn(true);
        $events = $this->createMock(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnArgument(0);
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/done');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new AdminFormAction(
            $access,
            $this->createMock(AdminFormValidator::class),
            $this->createMock(AdminLogger::class),
            $errorRenderer,
            $events,
            $urls,
            $translator,
            $tokens,
            new LegacyFormEventBridge(new EventDispatcher(), new RequestStack()),
            $logger,
        );
    }
}
