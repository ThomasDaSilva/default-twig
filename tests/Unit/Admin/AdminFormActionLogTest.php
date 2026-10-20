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
