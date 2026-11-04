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

namespace BackOfficeDefaultTwigBundle\Tests\Unit\OrderStatus;

use BackOfficeDefaultTwigBundle\Service\OrderStatus\StockRules;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Thelia\Model\Order;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusAction;

/**
 * The theme also runs on a core that has neither stock service: every question then gets
 * the answer that makes the screens hide what they would have shown.
 */
final class StockRulesTest extends TestCase
{
    public function testACoreWithoutTheStockServicesAnswersNothing(): void
    {
        $rules = new StockRules(new ServiceLocator([]));

        self::assertFalse($rules->canSimulate());
        self::assertSame([], $rules->overriddenActions());
        self::assertSame([], $rules->missingModules(new OrderStatusAction()));
        self::assertNull($rules->simulate(new Order(), new OrderStatus()));
    }
}
