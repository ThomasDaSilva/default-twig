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

use Symfony\Component\HttpFoundation\Request;

/**
 * How long the page of an export or an import job reloads itself: every three seconds
 * while the job runs, five minutes at most, and not at all once the administrator
 * stops it.
 */
final class JobPageRefresh
{
    public const MAX_ROUNDS = 100;

    /**
     * @return array{auto_refresh: bool, refresh_round: int, max_refresh_rounds: int}
     */
    public static function of(bool $finished, Request $request): array
    {
        $round = max(0, min(self::MAX_ROUNDS, $request->query->getInt('round')));

        return [
            'auto_refresh' => !$finished && $round < self::MAX_ROUNDS,
            'refresh_round' => $round,
            'max_refresh_rounds' => self::MAX_ROUNDS,
        ];
    }
}
