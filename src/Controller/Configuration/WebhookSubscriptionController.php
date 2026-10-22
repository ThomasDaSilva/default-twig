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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration;

use BackOfficeDefaultTwigBundle\Form\Configuration\WebhookSubscriptionType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Security\User\UserInterface;
use Thelia\Domain\Webhook\Event\WebhookSubscriptionEvent;
use Thelia\Domain\Webhook\EventListener\WebhookSubscriptionListener;
use Thelia\Domain\Webhook\WebhookDeliveryTransport;
use Thelia\Domain\Webhook\WebhookStatus;
use Thelia\Model\WebhookDeliveryQuery;
use Thelia\Model\WebhookSubscription;
use Thelia\Model\WebhookSubscriptionQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin/configuration/webhooks', name: 'admin.configuration.webhooks.')]
final class WebhookSubscriptionController
{
    private const RESOURCE = AdminResources::WEBHOOK;
    private const LIST_ROUTE = 'admin.configuration.webhooks.default';
    private const EDIT_ROUTE = 'admin.configuration.webhooks.update';
    private const JOURNAL_ROUTE = 'admin.configuration.webhooks.deliveries';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/webhook/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/webhook/edit.html.twig';
    private const ISSUED_SECRET_KEY = 'bo.webhook.issued_secret';
    private const ISSUED_SECRET_LIFETIME = 120;

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly EventDispatcherInterface $events,
        private readonly TokenProvider $tokens,
        private readonly SecurityContext $securityContext,
        private readonly WebhookDeliveryTransport $deliveryTransport,
        #[Target('webhook_test')]
        private readonly RateLimiterFactoryInterface $testLimiter,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $sort = ListSort::fromRequest($request, ['id', 'title', 'status'], 'id', 'desc');
        $criteria = 'DESC' === strtoupper($sort->direction) ? Criteria::DESC : Criteria::ASC;
        $query = WebhookSubscriptionQuery::create();
        match ($sort->field) {
            'title' => $query->orderByTitle($criteria),
            'status' => $query->orderByStatus($criteria)->orderById(Criteria::DESC),
            default => $query->orderById($criteria),
        };

        $lastSuccess = $this->lastSuccessBySubscription();
        $rows = [];
        foreach ($query->find() as $subscription) {
            \assert($subscription instanceof WebhookSubscription);
            $rows[] = $this->subscriptionToRow($subscription, $lastSuccess[(int) $subscription->getId()] ?? null);
        }

        $createForm = $this->formFactory->createNamed('thelia_webhook_create', WebhookSubscriptionType::class, [
            'events' => [],
            'payload_mode' => WebhookStatus::PAYLOAD_FULL,
            'status' => WebhookStatus::SUBSCRIPTION_ACTIVE,
        ]);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'create_form' => $createForm->createView(),
            'runs_inline' => $this->deliveryTransport->runsInline(),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $this->formFactory->createNamed('thelia_webhook_create', WebhookSubscriptionType::class);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::CREATE,
            form: $form,
            eventName: TheliaEvents::WEBHOOK_SUBSCRIPTION_CREATE,
            eventFactory: fn (FormInterface $validated): WebhookSubscriptionEvent => $this->subscriptionEvent($validated, null),
            actionLabel: 'Webhook subscription creation',
            successRoute: self::EDIT_ROUTE,
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::LIST_ROUTE)),
            describeForLog: fn (WebhookSubscriptionEvent $event): array => [\sprintf('Webhook subscription %s created', (string) $event->getSubscription()?->getTitle()), $event->getSubscription()?->getId()],
            successParametersResolver: fn (WebhookSubscriptionEvent $event): array => $this->issueSecret($request, $event),
        );
    }

    #[Route('/update/{webhook_id}', name: 'update', methods: ['GET'], requirements: ['webhook_id' => '\d+'])]
    public function updateView(int $webhook_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $subscription = WebhookSubscriptionQuery::create()->findPk($webhook_id);
        if (null === $subscription) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $response = new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'subscription' => $subscription,
            'form' => $this->updateForm($subscription)->createView(),
            'issued_secret' => $this->takeIssuedSecret($request, $webhook_id),
            'journal_url' => $this->urls->generate(self::JOURNAL_ROUTE, ['subscription_id' => $webhook_id]),
            'failed_deliveries' => WebhookDeliveryQuery::create()->filterBySubscriptionId($webhook_id)->filterByEvent(WebhookSubscriptionListener::TEST_EVENT, Criteria::NOT_EQUAL)->filterByStatus(WebhookStatus::DELIVERY_FAILED)->count(),
        ]));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function processUpdate(Request $request): Response
    {
        $webhookId = (int) $request->request->get('webhook_id', 0);
        $form = $this->formFactory->createNamed('thelia_webhook_update', WebhookSubscriptionType::class, null, [
            'include_id' => true,
            'include_error' => WebhookStatus::SUBSCRIPTION_ERROR === WebhookSubscriptionQuery::create()->findPk($webhookId)?->getStatus(),
        ]);

        return $this->action->submit(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            form: $form,
            eventName: TheliaEvents::WEBHOOK_SUBSCRIPTION_UPDATE,
            eventFactory: fn (FormInterface $validated): WebhookSubscriptionEvent => $this->subscriptionEvent($validated, $this->findOfThePage($webhookId, (int) ($validated->getData()['id'] ?? 0))),
            actionLabel: 'Webhook subscription update',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['webhook_id' => $webhookId],
            renderError: fn (): RedirectResponse => new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['webhook_id' => $webhookId])),
            describeForLog: fn (WebhookSubscriptionEvent $event): array => [\sprintf('Webhook subscription %s modified', (string) $event->getSubscription()?->getTitle()), $event->getSubscription()?->getId()],
        );
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $subscription = WebhookSubscriptionQuery::create()->findPk((int) $request->request->get('webhook_id', 0));
        if (null === $subscription) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $title = (string) $subscription->getTitle();

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: (new WebhookSubscriptionEvent())->setSubscription($subscription),
            eventName: TheliaEvents::WEBHOOK_SUBSCRIPTION_DELETE,
            actionLabel: 'Webhook subscription deletion',
            successRoute: self::LIST_ROUTE,
            describeForLog: static fn (): array => [\sprintf('Webhook subscription %s deleted', $title), null],
        );
    }

    #[Route('/rotate-secret', name: 'rotate_secret', methods: ['POST'])]
    public function rotateSecret(Request $request): Response
    {
        $subscription = WebhookSubscriptionQuery::create()->findPk((int) $request->request->get('webhook_id', 0));
        if (null === $subscription) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: (new WebhookSubscriptionEvent())->setSubscription($subscription),
            eventName: TheliaEvents::WEBHOOK_SUBSCRIPTION_ROTATE_SECRET,
            actionLabel: 'Webhook secret regeneration',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['webhook_id' => (int) $subscription->getId()],
            describeForLog: fn (WebhookSubscriptionEvent $event): array => [\sprintf('Secret of webhook subscription %s regenerated', (string) $event->getSubscription()?->getTitle()), $event->getSubscription()?->getId()],
            successParametersResolver: fn (WebhookSubscriptionEvent $event): array => $this->issueSecret($request, $event),
        );
    }

    #[Route('/test', name: 'test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $subscription = WebhookSubscriptionQuery::create()->findPk((int) $request->request->get('webhook_id', 0));
        if (null === $subscription) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $back = new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['webhook_id' => (int) $subscription->getId()]));

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (\Throwable) {
            $this->flash($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $back;
        }

        $adminUser = $this->securityContext->getAdminUser();

        if (!$this->testLimiter->create($adminUser instanceof UserInterface ? $adminUser->getUsername() : null)->consume()->isAccepted()) {
            $this->flash($request, 'warning', $this->translator->trans('Too many test requests in a row. Try again in a few minutes.'));

            return $back;
        }

        $event = (new WebhookSubscriptionEvent())->setSubscription($subscription);

        try {
            $this->events->dispatch($event, TheliaEvents::WEBHOOK_SUBSCRIPTION_TEST);
        } catch (\Throwable) {
            $this->flash($request, 'danger', $this->translator->trans('The test request could not be sent.'));

            return $back;
        }

        $delivery = $event->getTestDelivery();
        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Test request sent to webhook subscription %s', (string) $subscription->getTitle()), (int) $subscription->getId());

        if (WebhookStatus::DELIVERY_SUCCEEDED === $delivery?->getStatus()) {
            $this->flash($request, 'success', $this->translator->trans('The receiver answered %code%: the configuration works.', ['%code%' => (int) $delivery->getResponseCode()]));
        } else {
            $this->flash($request, 'danger', null === $delivery?->getResponseCode()
                ? $this->translator->trans('The receiver did not answer. The test request is in the delivery log.')
                : $this->translator->trans('The receiver answered %code%. The test request is in the delivery log.', ['%code%' => (int) $delivery->getResponseCode()]));
        }

        return $back;
    }

    private function subscriptionEvent(FormInterface $validated, ?WebhookSubscription $subscription): WebhookSubscriptionEvent
    {
        $data = $validated->getData() ?? [];
        $event = (new WebhookSubscriptionEvent())
            ->setTitle((string) ($data['title'] ?? ''))
            ->setUrl((string) ($data['url'] ?? ''))
            ->setEvents(array_values(array_map('strval', (array) ($data['events'] ?? []))))
            ->setPayloadMode((string) ($data['payload_mode'] ?? ''))
            ->setStatus((string) ($data['status'] ?? ''));

        return null === $subscription ? $event : $event->setSubscription($subscription);
    }

    private function findOfThePage(int $pageId, int $formId): WebhookSubscription
    {
        if ($pageId !== $formId) {
            throw new \InvalidArgumentException($this->translator->trans('The subscription of the form is not the one of the page.'));
        }

        return $this->findOrFail($pageId);
    }

    private function findOrFail(int $id): WebhookSubscription
    {
        return WebhookSubscriptionQuery::create()->findPk($id) ?? throw new \LogicException('Webhook subscription not found');
    }

    private function updateForm(WebhookSubscription $subscription): FormInterface
    {
        return $this->formFactory->createNamed('thelia_webhook_update', WebhookSubscriptionType::class, [
            'id' => $subscription->getId(),
            'title' => $subscription->getTitle(),
            'url' => $subscription->getUrl(),
            'events' => $this->eventNames($subscription),
            'payload_mode' => $subscription->getPayloadMode(),
            'status' => $subscription->getStatus(),
        ], [
            'include_id' => true,
            'include_error' => WebhookStatus::SUBSCRIPTION_ERROR === $subscription->getStatus(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function eventNames(WebhookSubscription $subscription): array
    {
        try {
            $names = json_decode((string) $subscription->getEvents(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($names) ? array_values(array_map('strval', $names)) : [];
    }

    /**
     * @return array<int, string>
     */
    private function lastSuccessBySubscription(): array
    {
        $last = WebhookDeliveryQuery::create()
            ->filterByEvent(WebhookSubscriptionListener::TEST_EVENT, Criteria::NOT_EQUAL)
            ->filterByStatus(WebhookStatus::DELIVERY_SUCCEEDED)
            ->groupBySubscriptionId()
            ->withColumn('MAX(webhook_delivery.delivered_at)', 'last_success')
            ->select(['SubscriptionId', 'last_success'])
            ->find();

        $bySubscription = [];
        foreach ($last as $row) {
            $bySubscription[(int) $row['SubscriptionId']] = (string) $row['last_success'];
        }

        return $bySubscription;
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionToRow(WebhookSubscription $subscription, ?string $lastSuccess): array
    {
        $id = (int) $subscription->getId();
        $status = (string) $subscription->getStatus();
        $host = parse_url((string) $subscription->getUrl(), \PHP_URL_HOST);

        return [
            'id' => $id,
            'title' => (string) $subscription->getTitle(),
            'url' => \is_string($host) ? $host : '',
            'events' => implode('', array_map(static fn (string $name): string => '<span class="d-block text-wrap">'.htmlspecialchars($name, \ENT_QUOTES).'</span>', $this->eventNames($subscription))),
            'status' => $status,
            'status_label' => match ($status) {
                WebhookStatus::SUBSCRIPTION_ACTIVE => $this->translator->trans('Active'),
                WebhookStatus::SUBSCRIPTION_ERROR => $this->translator->trans('In error'),
                default => $this->translator->trans('Inactive'),
            },
            'last_success' => null === $lastSuccess ? $this->translator->trans('Never') : $lastSuccess,
            'failure' => WebhookStatus::SUBSCRIPTION_ERROR === $status ? '<span class="d-block text-wrap text-break">'.htmlspecialchars((string) $subscription->getFailureReason(), \ENT_QUOTES).'</span>' : '',
            '_actions' => [
                new RowAction(kind: 'edit', label: $this->translator->trans('Edit'), href: $this->urls->generate(self::EDIT_ROUTE, ['webhook_id' => $id]), grantedAttribute: AccessManager::UPDATE, grantedSubject: self::RESOURCE),
                new RowAction(kind: 'view', label: $this->translator->trans('Delivery log'), href: $this->urls->generate(self::JOURNAL_ROUTE, ['subscription_id' => $id]), grantedAttribute: AccessManager::VIEW, grantedSubject: self::RESOURCE),
                new RowAction(kind: 'delete', label: $this->translator->trans('Delete'), modalTarget: '#webhook-delete-modal', grantedAttribute: AccessManager::DELETE, grantedSubject: self::RESOURCE, dataAttributes: ['webhook-id' => $id, 'webhook-label' => (string) $subscription->getTitle()]),
            ],
        ];
    }

    /**
     * @return array<string, scalar>
     */
    private function issueSecret(Request $request, WebhookSubscriptionEvent $event): array
    {
        $id = (int) $event->getSubscription()?->getId();

        if (null !== $event->getGeneratedSecret() && $request->hasSession()) {
            $request->getSession()->set(self::ISSUED_SECRET_KEY, [
                'id' => $id,
                'secret' => $event->getGeneratedSecret(),
                'expires' => time() + self::ISSUED_SECRET_LIFETIME,
            ]);
        }

        return ['webhook_id' => $id];
    }

    private function takeIssuedSecret(Request $request, int $webhookId): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();
        $issued = $session->get(self::ISSUED_SECRET_KEY);

        if (\is_array($issued) && time() > (int) ($issued['expires'] ?? 0)) {
            $session->remove(self::ISSUED_SECRET_KEY);

            return null;
        }

        if (!\is_array($issued) || (int) ($issued['id'] ?? 0) !== $webhookId) {
            return null;
        }

        $session->remove(self::ISSUED_SECRET_KEY);

        return time() <= (int) ($issued['expires'] ?? 0) ? (string) ($issued['secret'] ?? '') : null;
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
