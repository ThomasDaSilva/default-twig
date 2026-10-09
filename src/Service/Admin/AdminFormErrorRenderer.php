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

use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Messenger\JobFailureMessage;

/**
 * Push a form validation error to the user through the session flash bag and attach a
 * matching FormError to the form so the Twig form theme highlights the offending fields.
 */
readonly class AdminFormErrorRenderer
{
    public function __construct(
        private RequestStack $requestStack,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Tells the administrator the action failed, in the words AdminFailureMessage lets
     * through, and logs it.
     */
    public function fail(string $actionLabel, \Throwable $exception, ?FormInterface $form = null): void
    {
        $this->refuse($actionLabel, AdminFailureMessage::of($exception, $this->translator), $form, $exception);
    }

    /**
     * Tells the administrator a refusal written for them, and logs it: the exception, if
     * any, by its class, code and place, never by a text that may quote a customer.
     */
    public function refuse(
        string $actionLabel,
        string $refusal,
        ?FormInterface $form = null,
        ?\Throwable $exception = null,
    ): void {
        $this->logger->error(\sprintf('Error during %s: %s', $actionLabel, null === $exception ? $refusal : JobFailureMessage::forLog($exception)));

        $session = $this->requestStack->getMainRequest()?->getSession();
        if ($session instanceof Session) {
            $session->getFlashBag()->add('danger', $refusal);
        }

        if (null === $form) {
            return;
        }

        $form->addError(new \Symfony\Component\Form\FormError($refusal));
    }
}
