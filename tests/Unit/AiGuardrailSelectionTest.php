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

namespace BackOfficeDefaultTwigBundle\Tests\Unit;

use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiGuardrailSelection;
use PHPUnit\Framework\TestCase;

/**
 * The core reads the map a merchant stored as a whole: not empty, it replaces the guardrails the module declared.
 * The screen gives one choice per guardrail and must write the map that keeps the other choices.
 */
final class AiGuardrailSelectionTest extends TestCase
{
    private const DECLARED = ['topic' => 'block'];
    private const INSTALLED = ['topic', 'mistral_moderation'];

    public function testAUsageThatFollowsItsModuleShowsEveryGuardrailOnFollow(): void
    {
        $rows = AiGuardrailSelection::rows(self::DECLARED, [], self::INSTALLED);

        self::assertSame(['topic', 'mistral_moderation'], array_column($rows, 'name'));
        self::assertSame(['follow', 'follow'], array_column($rows, 'current'));
        self::assertSame(['block', null], array_column($rows, 'declared'));
    }

    public function testOnceTheMerchantChoseAGuardrailNotInTheMapIsNotApplied(): void
    {
        $rows = AiGuardrailSelection::rows(self::DECLARED, ['mistral_moderation' => 'annotate'], self::INSTALLED);

        self::assertSame(['mistral_moderation', 'topic'], array_column($rows, 'name'), 'the order they run');
        self::assertSame(['annotate', 'off'], array_column($rows, 'current'));
    }

    public function testAGuardrailOfAModuleThatIsGoneStaysListedAndMarkedNotInstalled(): void
    {
        $rows = AiGuardrailSelection::rows([], ['gone_moderation' => 'block'], self::INSTALLED);

        self::assertSame('gone_moderation', $rows[0]['name']);
        self::assertFalse($rows[0]['installed']);
        self::assertSame('block', $rows[0]['current']);
    }

    public function testEveryChoiceOnFollowGivesAnEmptyMapSoTheModuleDecides(): void
    {
        self::assertSame([], AiGuardrailSelection::map(self::DECLARED, ['topic' => 'annotate'], self::INSTALLED, ['topic' => 'follow', 'mistral_moderation' => 'follow']));
    }

    public function testChoosingOneGuardrailKeepsTheModuleModeOfTheOthersThatFollow(): void
    {
        $map = AiGuardrailSelection::map(self::DECLARED, [], self::INSTALLED, ['topic' => 'follow', 'mistral_moderation' => 'annotate']);

        self::assertSame(['topic' => 'block', 'mistral_moderation' => 'annotate'], $map);
    }

    public function testAGuardrailTheFormDidNotSendKeepsWhatItHasNow(): void
    {
        $map = AiGuardrailSelection::map(self::DECLARED, ['topic' => 'annotate', 'mistral_moderation' => 'block'], self::INSTALLED, ['mistral_moderation' => 'annotate']);

        self::assertSame(['topic' => 'annotate', 'mistral_moderation' => 'annotate'], $map);
    }

    public function testNotAppliedLeavesTheGuardrailOutOfTheMap(): void
    {
        $map = AiGuardrailSelection::map(self::DECLARED, [], self::INSTALLED, ['topic' => 'off', 'mistral_moderation' => 'block']);

        self::assertSame(['mistral_moderation' => 'block'], $map);
    }

    public function testTurningEverythingOffIsRefusedWhenTheModuleDeclaredGuardrails(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AiGuardrailSelection::map(self::DECLARED, [], self::INSTALLED, ['topic' => 'off', 'mistral_moderation' => 'off']);
    }

    public function testTurningEverythingOffIsAnEmptyMapWhenTheModuleDeclaredNone(): void
    {
        self::assertSame([], AiGuardrailSelection::map([], [], self::INSTALLED, ['topic' => 'off', 'mistral_moderation' => 'off']));
    }
}
