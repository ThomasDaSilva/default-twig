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

namespace BackOfficeDefaultTwigBundle\Service\Shipping;

use Symfony\Component\HttpFoundation\Request;

/**
 * A postal code rule the zone refused, kept in the session across the redirect to the zone
 * page, so that the merchant corrects what they typed instead of typing it again. The page
 * of the zone takes it back once.
 */
final readonly class ZipCodeRuleDraft
{
    private const SESSION_KEY = 'backoffice.zip_code_rule_draft';

    public function keep(Request $request, int $areaId): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $request->getSession()->set(self::SESSION_KEY, [
            'area_id' => $areaId,
            'pattern' => (string) $request->request->get('pattern', ''),
            'excluded' => '1' === $request->request->get('excluded'),
            'country_id' => (int) $request->request->get('country_id', 0),
        ]);
    }

    /**
     * @return array{pattern: string, excluded: bool, country_id: int}|null
     */
    public function takeBack(Request $request, int $areaId): ?array
    {
        if (!$request->hasSession()) {
            return null;
        }

        $draft = $request->getSession()->remove(self::SESSION_KEY);

        if (!\is_array($draft) || ($draft['area_id'] ?? null) !== $areaId) {
            return null;
        }

        return [
            'pattern' => (string) ($draft['pattern'] ?? ''),
            'excluded' => true === ($draft['excluded'] ?? null),
            'country_id' => (int) ($draft['country_id'] ?? 0),
        ];
    }
}
