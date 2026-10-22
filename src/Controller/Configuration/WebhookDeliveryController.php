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

use BackOfficeDefaultTwigBundle\Repository\WebhookDeliveryRepository;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
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
use Thelia\Domain\Webhook\Event\WebhookDeliveryEvent;
use Thelia\Domain\Webhook\EventListener\WebhookSubscriptionListener;
use Thelia\Domain\Webhook\WebhookEventCatalog;
use Thelia\Domain\Webhook\WebhookStatus;
use Thelia\Model\WebhookDeliveryQuery;
use Thelia\Model\WebhookSubscriptionQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

#[Route('/admin/configuration/webhooks/deliveries', name: 'admin.configuration.webhooks.deliveries')]
final class WebhookDeliveryController
{
    private const RESOURCE = AdminResources::WEBHOOK;
    private const LIST_ROUTE = 'admin.configuration.webhooks.deliveries';
    private const SHOW_ROUTE = 'admin.configuration.webhooks.deliveries.show';
    private const SUBSCRIPTIONS_ROUTE = 'admin.configuration.webhooks.default';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/webhook/deliveries.html.twig';
    private const SHOW_TEMPLATE = '@BackOfficeDefaultTwig/configuration/webhook/delivery.html.twig';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly WebhookEventCatalog $catalog,
        private readonly EventDispatcherInterface $events,
        private readonly TokenProvider $tokens,
        private readonly SecurityContext $securityContext,
        #[Target('webhook_replay')]
        private readonly RateLimiterFactoryInterface $replayLimiter,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $subscriptionFilter = (int) $request->query->get('subscription_id', 0);
        $eventFilter = (string) $request->query->get('event', '');
        $statusFilter = (string) $request->query->get('status', '');

        $titles = [];
        foreach (WebhookSubscriptionQuery::create()->orderByTitle()->find() as $subscription) {
            $titles[(int) $subscription->getId()] = (string) $subscription->getTitle();
        }

        $events = [...$this->catalog->names(), WebhookSubscriptionListener::TEST_EVENT];
        $result = $this->deliveries->search(
            $subscriptionFilter > 0 ? $subscriptionFilter : null,
            \in_array($eventFilter, $events, true) ? $eventFilter : null,
            \in_array($statusFilter, $this->deliveries->knownStatuses(), true) ? $statusFilter : null,
            (int) $request->query->get('page', 1),
        );

        $rows = array_map(fn (array $row): array => $this->deliveryToRow($row, $titles), $result['rows']);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'total' => $result['total'],
            'subscriptions' => $titles,
            'events' => $events,
            'statuses' => $this->deliveries->knownStatuses(),
            'current_subscription' => $subscriptionFilter,
            'current_event' => $eventFilter,
            'current_status' => $statusFilter,
            'current_page' => $result['page'],
            'last_page' => $result['lastPage'],
        ]));
    }

    #[Route('/{delivery_id}', name: '.show', methods: ['GET'], requirements: ['delivery_id' => '\d+'])]
    public function show(int $delivery_id): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $delivery = WebhookDeliveryQuery::create()->findPk($delivery_id);
        if (null === $delivery) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $subscription = $delivery->getWebhookSubscription();

        return new Response($this->twig->render(self::SHOW_TEMPLATE, [
            'delivery' => $delivery,
            'subscription' => $subscription,
            'payload' => $this->prettyPayload((string) $delivery->getPayload()),
            'replayable' => WebhookStatus::DELIVERY_FAILED === $delivery->getStatus() && WebhookStatus::SUBSCRIPTION_ACTIVE === $subscription?->getStatus(),
            'list_url' => $this->urls->generate(self::LIST_ROUTE, ['subscription_id' => (int) $delivery->getSubscriptionId()]),
            'subscriptions_url' => $this->urls->generate(self::SUBSCRIPTIONS_ROUTE),
        ]));
    }

    #[Route('/replay', name: '.replay', methods: ['POST'])]
    public function replay(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $delivery = WebhookDeliveryQuery::create()->findPk((int) $request->request->get('delivery_id', 0));
        if (null === $delivery) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $deliveryId = (int) $delivery->getId();
        $back = new RedirectResponse($this->urls->generate(self::SHOW_ROUTE, ['delivery_id' => $deliveryId]));

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (\Throwable) {
            $this->flash($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $back;
        }

        $adminUser = $this->securityContext->getAdminUser();

        if (!$this->replayLimiter->create($adminUser instanceof UserInterface ? $adminUser->getUsername() : null)->consume()->isAccepted()) {
            $this->flash($request, 'warning', $this->translator->trans('Too many replays in a row. Try again in a few minutes.'));

            return $back;
        }

        try {
            $this->events->dispatch((new WebhookDeliveryEvent())->setDelivery($delivery), TheliaEvents::WEBHOOK_DELIVERY_REPLAY);
        } catch (\Throwable $exception) {
            $this->flash($request, 'danger', $exception instanceof \LogicException ? $exception->getMessage() : $this->translator->trans('The delivery could not be replayed.'));

            return $back;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, \sprintf('Webhook delivery %d replayed', $deliveryId), $deliveryId);
        $this->flash($request, 'success', $this->translator->trans('The delivery was queued again: its result appears in the log.'));

        return $back;
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string>   $titles
     *
     * @return array<string, mixed>
     */
    private function deliveryToRow(array $row, array $titles): array
    {
        $id = (int) $row['Id'];
        $status = (string) $row['Status'];

        return [
            'id' => $id,
            'subscription' => $titles[(int) $row['SubscriptionId']] ?? '#'.(int) $row['SubscriptionId'],
            'event' => (string) $row['Event'],
            'status' => $status,
            'status_label' => match ($status) {
                WebhookStatus::DELIVERY_SUCCEEDED => $this->translator->trans('Succeeded'),
                WebhookStatus::DELIVERY_FAILED => $this->translator->trans('Failed'),
                WebhookStatus::DELIVERY_CANCELLED => $this->translator->trans('Not sent'),
                default => $this->translator->trans('Pending'),
            },
            'response_code' => null === $row['ResponseCode'] ? '-' : (string) $row['ResponseCode'],
            'attempts' => (int) $row['Attempts'],
            'duration' => null === $row['DurationMs'] ? '-' : $row['DurationMs'].' ms',
            'created_at' => (string) $row['CreatedAt'],
            '_actions' => [
                new RowAction(kind: 'view', label: $this->translator->trans('Details'), href: $this->urls->generate(self::SHOW_ROUTE, ['delivery_id' => $id]), grantedAttribute: AccessManager::VIEW, grantedSubject: self::RESOURCE),
            ],
        ];
    }

    private function prettyPayload(string $payload): string
    {
        try {
            return json_encode(
                json_decode($payload, false, 512, \JSON_THROW_ON_ERROR),
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return $payload;
        }
    }
}
