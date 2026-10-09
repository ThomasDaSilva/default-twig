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

use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use BackOfficeDefaultTwigBundle\Form\Legacy\LegacyFormEventBridge;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\ActionEvent;
use Thelia\Tools\TokenProvider;

/**
 * Orchestrates the recurring admin action pipeline:
 *
 *  1. ACL check        - early-return 403 when denied
 *  2. Input check      - Symfony Form validation OR CSRF token verification
 *  3. Event dispatch   - pre-built event or one created from the validated form
 *  4. Audit log        - optional success message + resource id
 *  5. Response         - redirect on success, caller-supplied render on failure
 *
 * Two entry points cover the BO Twig actions:
 *   - {@see submit()}  for form-driven endpoints (create/update/save/configure)
 *   - {@see tokenAction()} for CSRF-token endpoints (toggle/delete/position/setDefault)
 */
readonly class AdminFormAction
{
    public function __construct(
        private AdminAccessChecker $access,
        private AdminFormValidator $validator,
        private AdminLogger $adminLogger,
        private AdminFormErrorRenderer $errorRenderer,
        private EventDispatcherInterface $events,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private TokenProvider $tokens,
        private LegacyFormEventBridge $legacyFormBridge,
    ) {
    }

    /**
     * @param callable(FormInterface): object                       $eventFactory             build the event from the validated form
     * @param callable(\Throwable): Response                        $renderError              build the error response (typically a re-render with errors)
     * @param callable(object): array{0: string, 1: int|null}|null  $describeForLog           Optional. Build [message, resourceId] from the dispatched event.
     * @param array<string, scalar>                                 $successParameters
     * @param callable(object): array<string, scalar>|null          $successParametersResolver Optional. Resolve the redirect parameters from the dispatched event (e.g. a freshly created entity id). Overrides $successParameters when provided.
     */
    public function submit(
        string $resource,
        string $access,
        FormInterface $form,
        string $eventName,
        callable $eventFactory,
        string $actionLabel,
        string $successRoute,
        callable $renderError,
        array $successParameters = [],
        ?callable $describeForLog = null,
        ?callable $successParametersResolver = null,
    ): Response {
        if ($denied = $this->access->check($resource, [], $access)) {
            return $denied;
        }

        try {
            $validated = $this->validator->validate($form);
            $event = $eventFactory($validated);

            if ($event instanceof ActionEvent) {
                $this->legacyFormBridge->bindUnmappedFields($event, $validated);
            }

            $this->events->dispatch($event, $eventName);

            $this->logSuccess($resource, $access, $event, $describeForLog);

            if ($successParametersResolver !== null) {
                $successParameters = $successParametersResolver($event);
            }

            return new RedirectResponse($this->urls->generate($successRoute, $successParameters));
        } catch (\Throwable $exception) {
            $this->errorRenderer->setup(
                $this->translator->trans($actionLabel),
                $exception->getMessage(),
                $form,
                $exception,
            );

            return $renderError($exception);
        }
    }

    /**
     * @param callable(object): array{0: string, 1: int|null}|null $describeForLog
     * @param callable(\Throwable): Response|null                  $renderError               Optional. When omitted, errors redirect to $successRoute.
     * @param array<string, scalar>                                $successParameters
     * @param callable(object): array<string, scalar>|null          $successParametersResolver Optional. Resolve the redirect parameters from the dispatched event (e.g. the id of an entity the action just created). Overrides $successParameters when provided.
     * @param list<class-string<\Throwable>>                       $trustedFailures           Optional. The failures whose message the administrator reads; any other is reported as an internal error. Empty shows every message but the technical ones.
     */
    public function tokenAction(
        string $resource,
        string $access,
        Request $request,
        object $event,
        string $eventName,
        string $actionLabel,
        string $successRoute,
        array $successParameters = [],
        ?callable $describeForLog = null,
        ?callable $renderError = null,
        ?callable $successParametersResolver = null,
        array $trustedFailures = [],
    ): Response {
        if ($denied = $this->access->check($resource, [], $access)) {
            return $denied;
        }

        try {
            // The token is read from the request body only (hidden _token field of the
            // forms, body of the POST links and of the drag-and-drop requests): a token
            // in the URL would end up in the browser history and in server logs.
            $token = (string) $request->request->get('_token', '');
            $this->tokens->checkToken($token);
            $this->events->dispatch($event, $eventName);

            $this->logSuccess($resource, $access, $event, $describeForLog);

            if ($successParametersResolver !== null) {
                $successParameters = $successParametersResolver($event);
            }

            return new RedirectResponse($this->urls->generate($successRoute, $successParameters));
        } catch (\Throwable $exception) {
            $this->errorRenderer->setup(
                $this->translator->trans($actionLabel),
                $exception->getMessage(),
                null,
                $exception,
                $trustedFailures,
            );

            return $renderError !== null
                ? $renderError($exception)
                : new RedirectResponse($this->urls->generate($successRoute, $successParameters));
        }
    }

    /**
     * @param callable(object): array{0: string, 1: int|null}|null $describeForLog
     */
    private function logSuccess(string $resource, string $access, object $event, ?callable $describeForLog): void
    {
        if ($describeForLog === null) {
            return;
        }

        [$message, $resourceId] = $describeForLog($event);
        $this->adminLogger->log($resource, $access, $message, $resourceId);
    }
}
