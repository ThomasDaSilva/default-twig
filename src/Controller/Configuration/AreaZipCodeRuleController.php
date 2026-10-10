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

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Shipping\ZipCode\AreaZipCodeRuleWriter;
use Thelia\Tools\TokenProvider;

/**
 * The postal code rules of a shipping zone, edited on the page of the zone.
 */
#[Route('/admin/configuration/shipping_configuration/{area_id}/zip-code-rules', name: 'admin.configuration.shipping-configuration.zip-code-rule.', requirements: ['area_id' => '\d+'])]
final class AreaZipCodeRuleController
{
    private const EDIT_ROUTE = 'admin.configuration.shipping-configuration.update.view';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AreaZipCodeRuleWriter $writer,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly TokenProvider $tokens,
    ) {
    }

    #[Route('', name: 'add', methods: ['POST'])]
    public function add(int $area_id, Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::AREA, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        $countryId = (int) $request->request->get('country_id', 0);

        try {
            $this->writer->add(
                $area_id,
                (string) $request->request->get('pattern', ''),
                '1' === $request->request->get('excluded'),
                0 === $countryId ? null : $countryId,
            );
            $this->flash($request, 'success', 'The postal code rule was added.');
        } catch (\InvalidArgumentException $exception) {
            $this->flash($request, 'danger', $exception->getMessage());
        }

        return $this->backToTheZone($area_id);
    }

    #[Route('/{rule_id}/delete', name: 'delete', methods: ['POST'], requirements: ['rule_id' => '\d+'])]
    public function delete(int $area_id, int $rule_id, Request $request): Response
    {
        if ($denied = $this->access->check(AdminResources::AREA, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        $this->writer->remove($area_id, $rule_id)
            ? $this->flash($request, 'success', 'The postal code rule was deleted.')
            : $this->flash($request, 'danger', 'This postal code rule does not exist anymore.');

        return $this->backToTheZone($area_id);
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $this->translator->trans($message));
        }
    }

    private function backToTheZone(int $areaId): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['area_id' => $areaId]).'#zip-code-rules');
    }
}
