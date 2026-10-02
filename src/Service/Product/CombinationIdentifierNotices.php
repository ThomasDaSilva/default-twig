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

namespace BackOfficeDefaultTwigBundle\Service\Product;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Catalog\Product\Identifier\GtinDuplicateFinder;
use Thelia\Domain\Catalog\Product\Identifier\GtinSharer;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * The messages a save of combinations leaves on the next page: a code the core refused,
 * and a GTIN another combination already carries. The duplicate is a warning only, the
 * combination is saved: two combinations of the same physical item share a code.
 */
final readonly class CombinationIdentifierNotices
{
    public function __construct(
        private GtinDuplicateFinder $duplicateFinder,
        private RequestStack $requestStack,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * The core already worded the refusal: which combination, which code, and why.
     */
    public function refused(\InvalidArgumentException $refusal): void
    {
        $this->flash('danger', $refusal->getMessage());
    }

    /**
     * @param list<int> $savedCombinationIds
     */
    public function reportSharedCodes(array $savedCombinationIds): void
    {
        $sharersById = $this->duplicateFinder->sharersAmong($savedCombinationIds);

        if ($sharersById === []) {
            return;
        }

        $codes = $this->codesOf(array_keys($sharersById));

        foreach ($sharersById as $combinationId => $sharers) {
            $this->flash('warning', $this->translator->trans(
                'The GTIN %code% of the combination %ref% is also carried by: %others%. It was saved; check it is not a typing mistake.',
                [
                    '%code%' => $codes[$combinationId]['code'] ?? '',
                    '%ref%' => $codes[$combinationId]['ref'] ?? '',
                    '%others%' => implode(', ', array_map(
                        static fn (GtinSharer $sharer): string => $sharer->productRef.' / '.$sharer->productSaleElementsRef,
                        $sharers,
                    )),
                ],
            ));
        }
    }

    /**
     * @param list<int> $combinationIds
     *
     * @return array<int, array{code: string, ref: string}>
     */
    private function codesOf(array $combinationIds): array
    {
        $codes = [];

        foreach (ProductSaleElementsQuery::create()->filterById($combinationIds)->find() as $combination) {
            $codes[(int) $combination->getId()] = ['code' => (string) $combination->getEanCode(), 'ref' => (string) $combination->getRef()];
        }

        return $codes;
    }

    private function flash(string $type, string $message): void
    {
        $session = $this->requestStack->getSession();
        if (method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
