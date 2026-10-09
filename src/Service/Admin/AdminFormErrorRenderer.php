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

        // The administrator reads what a rule refused, worded by the rule; the inside of
        // a database driver, an HTTP client or PHP itself — a table name, a query, a URL
        // with its key, a file path — only goes to the log written above.
        $shownMessage = $this->isTechnical($exception)
            ? $this->translator->trans('The action failed on an internal error. The details are in the log.')
            : $refusal;

        $session = $this->requestStack->getMainRequest()?->getSession();
        if ($session instanceof Session) {
            $session->getFlashBag()->add('danger', $shownMessage);
        }

        if (null === $form) {
            return;
        }

        $form->addError(new \Symfony\Component\Form\FormError($shownMessage));
    }

    /**
     * Former name of refuse(), kept for the controllers and modules that still call it.
     */
    public function setup(
        string $actionLabel,
        string $errorMessage,
        ?FormInterface $form = null,
        ?\Throwable $exception = null,
    ): void {
        $this->refuse($actionLabel, $errorMessage, $form, $exception);
    }

    /**
     * Whether the failure, or one it wraps, comes from below the application: the
     * database, the network, or the PHP engine.
     */
    private function isTechnical(?\Throwable $exception): bool
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof \PDOException
                || $current instanceof \Error
                || $current instanceof \Propel\Runtime\Exception\PropelException
                || $current instanceof \Propel\Runtime\ActiveQuery\QueryExecutor\QueryExecutionException
                || $current instanceof \Symfony\Contracts\HttpClient\Exception\ExceptionInterface
                || (interface_exists(\GuzzleHttp\Exception\GuzzleException::class) && $current instanceof \GuzzleHttp\Exception\GuzzleException)
            ) {
                return true;
            }
        }

        return false;
    }
}
