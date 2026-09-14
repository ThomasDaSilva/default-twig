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

use BackOfficeDefaultTwigBundle\Form\Configuration\CheckoutDisplayModeType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormValidator;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\CheckoutStep\CheckoutStepSynchronizeEvent;
use Thelia\Core\Event\CheckoutStep\CheckoutStepToggleActiveEvent;
use Thelia\Core\Event\CheckoutStep\CheckoutStepUpdatePositionEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Checkout\Enum\CheckoutDisplayMode;
use Thelia\Domain\Checkout\Service\CheckoutStepTitleResolver;
use Thelia\Domain\Checkout\Service\Step\CheckoutStepProviderInterface;
use Thelia\Model\CheckoutStep;
use Thelia\Model\CheckoutStepQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The merchant's side of the checkout tunnel: which steps are asked for, in which
 * order, and whether the theme shows them one screen at a time or all on one page.
 *
 * There is no create, no edit and no delete here: the steps come from the code that
 * declares them — core for the four it ships, a module for its own — and the screen
 * only ever turns them on and off and reorders them. The shape the tunnel cannot sell
 * without is enforced by the core service, so a refusal reaches the merchant as a flash
 * message rather than as a check duplicated here.
 */
#[Route('/admin/configuration/checkout-step', name: 'admin.checkout-step.')]
final class CheckoutStepController
{
    private const RESOURCE = AdminResources::CHECKOUT_STEP;
    private const LIST_ROUTE = 'admin.checkout-step.list';
    private const TOGGLE_ACTIVE_ROUTE = 'admin.checkout-step.toggle-active';
    private const UPDATE_POSITION_ROUTE = 'admin.checkout-step.update-position';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/checkout-step/list.html.twig';
    private const FORM_NAME = 'thelia_checkout_display_mode';

    /**
     * Steps are addressed by code and never by id: that is what a theme, a module and a
     * route all know them by, and what survives a table rebuilt by a migration.
     */
    private const CODE_PARAMETER = 'checkout_step_code';

    /**
     * The state the click is asking for, and not "the other one from whatever is stored".
     *
     * A link the merchant opened twice — a double click, a page left open in another tab
     * — then asks for the state it already named instead of flipping the step back, and
     * the answer to a stale link is that nothing changes.
     */
    private const ACTIVE_PARAMETER = 'active';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly AdminFormValidator $validator,
        private readonly AdminFormErrorRenderer $errorRenderer,
        private readonly AdminLogger $adminLogger,
        private readonly EventDispatcherInterface $events,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TokenProvider $tokens,
        private readonly TranslatorInterface $translator,
        private readonly CheckoutStepTitleResolver $titles,
        /**
         * The steps the installed code declares, read here for one thing only: whether
         * any of them is missing its row. What to do about it stays the core service's.
         *
         * @var iterable<CheckoutStepProviderInterface>
         */
        #[AutowireIterator('thelia.checkout.step_provider')]
        private readonly iterable $stepProviders,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $this->giveEveryDeclaredStepARow();

        return new Response($this->twig->render(self::LIST_TEMPLATE, $this->buildListContext($request)));
    }

    #[Route('/toggle-active', name: 'toggle-active', methods: ['GET', 'POST'])]
    public function toggleActive(Request $request): Response
    {
        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new CheckoutStepToggleActiveEvent($this->stepCode($request), $this->wantedActiveState($request)),
            eventName: TheliaEvents::CHECKOUT_STEP_TOGGLE_ACTIVE,
            actionLabel: 'Checkout step activation toggle',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/update-position', name: 'update-position', methods: ['GET', 'POST'])]
    public function updatePosition(Request $request): Response
    {
        $position = (int) ($request->query->get('position') ?? $request->request->get('position', 0));

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new CheckoutStepUpdatePositionEvent($this->stepCode($request), $position),
            eventName: TheliaEvents::CHECKOUT_STEP_UPDATE_POSITION,
            actionLabel: 'Checkout step reorder',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/save-display-mode', name: 'save-display-mode', methods: ['POST'])]
    public function saveDisplayMode(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $form = $this->buildDisplayModeForm();

        try {
            $validated = $this->validator->validate($form);
            $mode = $validated->get('checkout_display_mode')->getData();

            if (!$mode instanceof CheckoutDisplayMode) {
                throw new \UnexpectedValueException($this->translator->trans('No checkout layout was chosen.'));
            }

            // Written straight to the configuration table, deliberately: a single shop
            // setting is what ConfigStore is for on this side of the back office, and
            // every other screen here writes its own the same way. An event and an
            // action of its own would buy nothing — there is no model to build, no
            // shape to keep and nothing for a module to listen for.
            ConfigQuery::write('checkout_display_mode', $mode->value, false);

            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, 'Checkout layout changed', null);

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        } catch (\Throwable $exception) {
            $this->errorRenderer->setup(
                $this->translator->trans('Checkout layout update'),
                $exception->getMessage(),
                $form,
                $exception,
            );

            return new Response(
                $this->twig->render(self::LIST_TEMPLATE, $this->buildListContext($request, $form)),
                Response::HTTP_BAD_REQUEST,
            );
        }
    }

    /**
     * What gives a module's step a line on this screen without a migration of its own.
     * Creates the missing rows only, so nothing a merchant configured is lost.
     *
     * Asked for only when a declared step actually has no row. Opening a list is a read,
     * and a screen that writes on every GET writes on every refresh, on every back
     * button and on every crawl of an administrator's session — in a shop where there is
     * nothing at all to create. The comparison itself reads the codes and no more.
     *
     * @throws PropelException
     */
    private function giveEveryDeclaredStepARow(): void
    {
        if (!$this->someDeclaredStepHasNoRow()) {
            return;
        }

        $this->events->dispatch(new CheckoutStepSynchronizeEvent(), TheliaEvents::CHECKOUT_STEP_SYNCHRONIZE);
    }

    /**
     * @throws PropelException
     */
    private function someDeclaredStepHasNoRow(): bool
    {
        /** @var list<string> $stored */
        $stored = array_map(
            strval(...),
            CheckoutStepQuery::create()->select('Code')->find()->getData(),
        );

        foreach ($this->stepProviders as $provider) {
            if (!\in_array($provider->code(), $stored, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PropelException
     */
    private function buildListContext(Request $request, ?FormInterface $displayModeForm = null): array
    {
        $locale = $request->getLocale();
        $rows = [];

        // Position order and nothing else: on this screen the order of the rows *is*
        // the configuration, so there are no sortable headers to fight the drag and drop.
        // The order is the tunnel's own, read the way the front office and the
        // progression read it, so the merchant is shown the walk the buyer makes.
        foreach (CheckoutStepQuery::create()->orderedByTunnel()->find() as $step) {
            $rows[] = $this->stepToRow($step, $locale);
        }

        return [
            'rows' => $rows,
            'display_mode_form' => ($displayModeForm ?? $this->buildDisplayModeForm())->createView(),
            'update_position_url' => $this->urls->generate(self::UPDATE_POSITION_ROUTE),
            'update_position_token' => $this->tokens->assignToken(),
            'position_param_name' => self::CODE_PARAMETER,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stepToRow(CheckoutStep $step, string $locale): array
    {
        $code = (string) $step->getCode();

        return [
            // The data table turns `id` into the row's data-row-id, which is what the
            // sortable controller posts back — hence the code and not the primary key.
            'id' => $code,
            'code' => $code,
            'title' => $this->titles->titleOf($step, $locale),
            'position' => (int) $step->getPosition(),
            'mandatory' => $step->isMandatory(),
            'active' => $step->isActive(),
            // A mandatory step is shown as a read-only state rather than as a switch
            // the server would refuse: the refusal still stands, it is just not offered.
            // The link carries the state it is asking for, worked out from the row being
            // drawn: what the merchant clicked is "turn this off", not "flip it".
            'toggle_active_url' => $step->isMandatory()
                ? null
                : $this->tokenizedUrl(self::TOGGLE_ACTIVE_ROUTE, [
                    self::CODE_PARAMETER => $code,
                    self::ACTIVE_PARAMETER => $step->isActive() ? 0 : 1,
                ]),
        ];
    }

    private function buildDisplayModeForm(): FormInterface
    {
        return $this->formFactory->createNamed(self::FORM_NAME, CheckoutDisplayModeType::class, [
            'checkout_display_mode' => CheckoutDisplayMode::fromStoredValue(ConfigQuery::getCheckoutDisplayMode()),
        ]);
    }

    private function stepCode(Request $request): string
    {
        return (string) ($request->query->get(self::CODE_PARAMETER) ?? $request->request->get(self::CODE_PARAMETER, ''));
    }

    /**
     * Read from the query string as well as from the body, the way the CSRF token is:
     * the switch is a link, and the screen posts nothing to turn a step on or off.
     */
    private function wantedActiveState(Request $request): bool
    {
        return $request->request->getBoolean(
            self::ACTIVE_PARAMETER,
            $request->query->getBoolean(self::ACTIVE_PARAMETER),
        );
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private function tokenizedUrl(string $route, array $parameters): string
    {
        $url = $this->urls->generate($route, $parameters);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'_token='.$this->tokens->assignToken();
    }
}
