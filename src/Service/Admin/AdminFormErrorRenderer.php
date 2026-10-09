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

    public function setup(
        string $actionLabel,
        string $errorMessage,
        ?FormInterface $form = null,
        ?\Throwable $exception = null,
    ): void {
        $this->logger->error(
            $this->translator->trans(
                'Error during %action process: %error. Exception was %exc',
                [
                    '%action' => $actionLabel,
                    '%error' => $errorMessage,
                    '%exc' => $exception?->getMessage() ?? 'no exception',
                ],
            ),
        );

        // The administrator reads what a rule refused, worded by the rule; the inside of
        // a database driver, an HTTP client or PHP itself — a table name, a query, a URL
        // with its key, a file path — only goes to the log written above.
        $shownMessage = $this->isTechnical($exception)
            ? $this->translator->trans('The action failed on an internal error. The details are in the log.')
            : $errorMessage;

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
