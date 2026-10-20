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
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use Propel\Runtime\Exception\ExceptionInterface as PropelError;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Tools\TokenProvider;

/**
 * The postal code rules of a shipping zone, edited on the page of the zone.
 *
 * The writer comes with a core that knows postal code rules, through an optional locator:
 * on an older core, where the class does not exist, the container still compiles and these
 * actions answer 404.
 *
 * Each rule added or deleted is written to the administration log, as the other writes of
 * a zone are. A failure of the database is logged, and the merchant reads a plain sentence.
 */
#[Route('/admin/configuration/shipping_configuration/{area_id}/zip-code-rules', name: 'admin.configuration.shipping-configuration.zip-code-rule.', requirements: ['area_id' => '\d+'])]
final class AreaZipCodeRuleController
{
    private const RESOURCE = AdminResources::AREA;
    private const EDIT_ROUTE = 'admin.configuration.shipping-configuration.update.view';
    private const WRITER = 'Thelia\\Domain\\Shipping\\ZipCode\\AreaZipCodeRuleWriter';

    public function __construct(
        private readonly AdminAccessChecker $access,
        #[AutowireLocator(['writer' => '?'.self::WRITER])]
        private readonly ContainerInterface $zipCodeRules,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly TokenProvider $tokens,
        private readonly AdminLogger $adminLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'add', methods: ['POST'])]
    public function add(int $area_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $writer = $this->writer();
        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        $countryId = (int) $request->request->get('country_id', 0);

        try {
            $rule = $writer->add(
                $area_id,
                (string) $request->request->get('pattern', ''),
                '1' === $request->request->get('excluded'),
                0 === $countryId ? null : $countryId,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->flash($request, 'danger', $exception->getMessage());

            return $this->backToTheZone($area_id);
        } catch (PropelError $exception) {
            $this->logger->error('Postal code rule not saved.', ['exception' => $exception, 'area_id' => $area_id]);
            $this->flash($request, 'danger', 'The postal code rule could not be saved. Try again later.');

            return $this->backToTheZone($area_id);
        }

        $this->adminLogger->log(
            self::RESOURCE,
            AccessManager::UPDATE,
            \sprintf('Postal code rule "%s" (ID %d) added to shipping zone %d', (string) $rule->getPattern(), (int) $rule->getId(), $area_id),
            $area_id,
        );
        $this->flash($request, 'success', 'The postal code rule was added.');

        return $this->backToTheZone($area_id);
    }

    /**
     * The rule comes in the body, from the confirmation dialog of the card.
     */
    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(int $area_id, Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $writer = $this->writer();
        $this->tokens->checkToken((string) $request->request->get('_token', ''));

        $ruleId = (int) $request->request->get('rule_id', 0);

        try {
            $deleted = $writer->remove($area_id, $ruleId);
        } catch (PropelError $exception) {
            $this->logger->error('Postal code rule not deleted.', ['exception' => $exception, 'area_id' => $area_id, 'rule_id' => $ruleId]);
            $this->flash($request, 'danger', 'The postal code rule could not be deleted. Try again later.');

            return $this->backToTheZone($area_id);
        }

        if (!$deleted) {
            $this->flash($request, 'danger', 'This postal code rule does not exist anymore.');

            return $this->backToTheZone($area_id);
        }

        $this->adminLogger->log(
            self::RESOURCE,
            AccessManager::UPDATE,
            \sprintf('Postal code rule %d deleted from shipping zone %d', $ruleId, $area_id),
            $area_id,
        );
        $this->flash($request, 'success', 'The postal code rule was deleted.');

        return $this->backToTheZone($area_id);
    }

    private function writer(): mixed
    {
        if (!$this->zipCodeRules->has('writer')) {
            throw new NotFoundHttpException('This version of Thelia has no postal code rules.');
        }

        return $this->zipCodeRules->get('writer');
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
