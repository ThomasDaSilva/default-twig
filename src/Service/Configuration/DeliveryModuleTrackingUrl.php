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

namespace BackOfficeDefaultTwigBundle\Service\Configuration;

use Thelia\Domain\Order\Service\OrderTrackingUrlResolver;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;

/**
 * The tracking address template of a delivery module, where %ID% stands for the
 * tracking number of an order. The core turns it into the link shown to the customer
 * and to the merchant; this reads and writes it for the shipping page of the module.
 *
 * The resolver is optional so the theme keeps working on a core that predates the
 * tracking link: the card is then not offered.
 */
final readonly class DeliveryModuleTrackingUrl
{
    public function __construct(private ?OrderTrackingUrlResolver $resolver = null)
    {
    }

    public function isSupported(): bool
    {
        return null !== $this->resolver;
    }

    /**
     * Whether the module builds its tracking links itself, in which case a template
     * typed for it would never be read.
     */
    public function isProvidedByModule(int $moduleId): bool
    {
        return null !== $this->resolver && $this->resolver->isProvidedByModule($moduleId);
    }

    public function templateOf(int $moduleId): string
    {
        if (!$this->isSupported()) {
            return '';
        }

        return (string) ModuleConfigQuery::create()->getConfigValue($moduleId, OrderTrackingUrlResolver::TRACKING_URL_CONFIG_KEY);
    }

    /**
     * Saves the template of a delivery module, or removes it when empty. A template
     * that is not an http(s) address carrying %ID% is refused and nothing changes, and
     * so is any template for a module that builds its tracking links itself.
     */
    public function save(int $moduleId, string $template): bool
    {
        $template = trim($template);
        $module = ModuleQuery::create()->findPk($moduleId);

        if (!$this->isSupported() || null === $module || BaseModule::DELIVERY_MODULE_TYPE !== (int) $module->getType()) {
            return false;
        }

        if ('' === $template) {
            ModuleConfigQuery::create()->deleteConfigValue($moduleId, OrderTrackingUrlResolver::TRACKING_URL_CONFIG_KEY);

            return true;
        }

        // A module that builds its links itself never reads a template: none is kept.
        if ($this->isProvidedByModule($moduleId)) {
            return false;
        }

        if (!OrderTrackingUrlResolver::isValidTemplate($template)) {
            return false;
        }

        ModuleConfigQuery::create()->setConfigValue($moduleId, OrderTrackingUrlResolver::TRACKING_URL_CONFIG_KEY, $template);

        return true;
    }
}
