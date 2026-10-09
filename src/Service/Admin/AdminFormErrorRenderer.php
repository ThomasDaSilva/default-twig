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
 * Tells the administrator an action failed or was refused: a flash in the session, a
 * FormError on the form so the Twig form theme highlights the offending fields, and a
 * line in the log.
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
     * Tells the administrator a refusal written for them, and logs it: an exception as an
     * error, by its class, code and place, never by a text that may quote a customer; a
     * refusal without one as a warning, since nothing broke.
     */
    public function refuse(
        string $actionLabel,
        string $refusal,
        ?FormInterface $form = null,
        ?\Throwable $exception = null,
    ): void {
        if (null === $exception) {
            $this->logger->warning(\sprintf('%s refused: %s', $actionLabel, $refusal));
        } else {
            $this->logger->error(\sprintf('Error during %s: %s', $actionLabel, JobFailureMessage::forLog($exception)));
        }

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
