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
     *
     * @param list<class-string<\Throwable>> $trustedFailures when given, the only failures whose message is shown; any other is reported as an internal error
     */
    public function fail(string $actionLabel, \Throwable $exception, ?FormInterface $form = null, array $trustedFailures = []): void
    {
        // A screen that names its trusted failures shows their own words, even when they wrap
        // a technical failure; refuse() decides whether the message is shown at all.
        $message = [] !== $trustedFailures && $this->isShown($exception, $trustedFailures)
            ? $exception->getMessage()
            : AdminFailureMessage::of($exception, $this->translator);

        $this->refuse($actionLabel, $message, $form, $exception, $trustedFailures);
    }

    /**
     * Tells the administrator a refusal written for them, and logs it: an exception as an
     * error, by its class, code and place, never by a text that may quote a customer; a
     * refusal without one as a warning, since nothing broke.
     *
     * @param list<class-string<\Throwable>> $trustedFailures when given, the only failures whose message is shown; any other is reported as an internal error
     */
    public function refuse(
        string $actionLabel,
        string $refusal,
        ?FormInterface $form = null,
        ?\Throwable $exception = null,
        array $trustedFailures = [],
    ): void {
        if (null === $exception) {
            $this->logger->warning(\sprintf('%s refused: %s', $actionLabel, $refusal));
        } else {
            $this->logger->error(\sprintf('Error during %s: %s', $actionLabel, JobFailureMessage::forLog($exception)));
        }

        // The administrator reads what a rule refused, worded by the rule; the inside of
        // a database driver, an HTTP client or PHP itself — a table name, a query, a URL
        // with its key, a file path — only goes to the log written above.
        // A screen that names its trusted failures shows exactly those, worded by their
        // rule, whatever they wrap; the others keep the technical-failure filter.
        $shownMessage = $this->isShown($exception, $trustedFailures)
            ? $refusal
            : $this->translator->trans('The action failed on an internal error. The details are in the log.');

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
     *
     * @param list<class-string<\Throwable>> $trustedFailures
     */
    public function setup(
        string $actionLabel,
        string $errorMessage,
        ?FormInterface $form = null,
        ?\Throwable $exception = null,
        array $trustedFailures = [],
    ): void {
        $this->refuse($actionLabel, $errorMessage, $form, $exception, $trustedFailures);
    }

    /**
     * Whether the administrator reads the message of the failure: a screen that names its
     * trusted failures shows exactly those, whatever they wrap; the others keep the
     * technical-failure filter. The administration log follows the same rule.
     *
     * @param list<class-string<\Throwable>> $trustedFailures
     */
    public function isShown(?\Throwable $exception, array $trustedFailures = []): bool
    {
        return $trustedFailures === []
            ? !$this->isTechnical($exception)
            : $this->isTrusted($exception, $trustedFailures);
    }

    /**
     * An action whose failures may come from code the shop does not word — a module, a
     * provider — names the failures that are its own rules: only those are shown.
     *
     * @param list<class-string<\Throwable>> $trustedFailures
     */
    private function isTrusted(?\Throwable $exception, array $trustedFailures): bool
    {
        if ($exception === null || $trustedFailures === []) {
            return true;
        }

        foreach ($trustedFailures as $trustedFailure) {
            if ($exception instanceof $trustedFailure) {
                return true;
            }
        }

        return false;
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
