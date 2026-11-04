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

namespace BackOfficeDefaultTwigBundle\Service\OrderStatus;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Thelia\Model\Order;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusAction;

/**
 * The stock rules of the status actions, as far as the installed core knows them: the
 * warnings of the actions list and the simulation of a status change. A core without
 * them answers nothing, and the screens that ask hide what they would have shown.
 */
final readonly class StockRules
{
    private const DIAGNOSTICS = 'Thelia\\Domain\\Order\\StatusAction\\StockActionDiagnostics';
    private const SIMULATOR = 'Thelia\\Domain\\Order\\StatusAction\\StockOperationSimulator';

    public function __construct(
        #[AutowireLocator(['diagnostics' => '?'.self::DIAGNOSTICS, 'simulator' => '?'.self::SIMULATOR])]
        private ContainerInterface $services,
    ) {
    }

    public function canSimulate(): bool
    {
        return null !== $this->service('simulator', 'simulate');
    }

    /**
     * @return array<int, int> id of a stock action that never applies => id of the action that wins over it
     */
    public function overriddenActions(): array
    {
        $diagnostics = $this->service('diagnostics', 'overriddenActions');

        if (null === $diagnostics) {
            return [];
        }

        $overridden = [];

        foreach ((array) $diagnostics->overriddenActions() as $action => $winner) {
            $overridden[(int) $action] = (int) $winner;
        }

        return $overridden;
    }

    /**
     * @return list<string>
     */
    public function missingModules(OrderStatusAction $action): array
    {
        $diagnostics = $this->service('diagnostics', 'missingModules');

        if (null === $diagnostics) {
            return [];
        }

        return array_values(array_map(strval(...), (array) $diagnostics->missingModules($action)));
    }

    /**
     * @return array{operation: string, action_id: ?int}|null null when the core cannot simulate
     */
    public function simulate(Order $order, OrderStatus $newStatus): ?array
    {
        $simulator = $this->service('simulator', 'simulate');

        if (null === $simulator) {
            return null;
        }

        $decision = $simulator->simulate($order, $newStatus);

        if (!\is_object($decision) || !method_exists($decision, 'operationName')) {
            return null;
        }

        $actionId = $decision->actionId ?? null;

        return ['operation' => (string) $decision->operationName(), 'action_id' => \is_int($actionId) ? $actionId : null];
    }

    /**
     * The service of the installed core, only when it carries the method asked for.
     * Widened to a plain object so that the analysis holds on a core with and without it.
     */
    private function service(string $name, string $method): ?object
    {
        if (!$this->services->has($name)) {
            return null;
        }

        $service = $this->services->get($name);

        return \is_object($service) && method_exists($service, $method) ? $service : null;
    }
}
