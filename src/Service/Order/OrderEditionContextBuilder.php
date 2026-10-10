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

namespace BackOfficeDefaultTwigBundle\Service\Order;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Order\Edition\OrderEditor;
use Thelia\Model\Order;

/**
 * What the order sheet says about editing the lines: the way to the edit page, or why the
 * order cannot be edited. Nothing for an administrator without the right.
 */
final readonly class OrderEditionContextBuilder
{
    public function __construct(
        private SecurityContext $securityContext,
        private OrderEditor $editor,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{edit_lines_url: ?string, edit_lines_refusal: ?string}
     */
    public function build(Order $order): array
    {
        if (!$this->securityContext->isGranted(['ADMIN'], [AdminResources::ORDER_EDIT], [], [AccessManager::UPDATE])) {
            return ['edit_lines_url' => null, 'edit_lines_refusal' => null];
        }

        $refusal = $this->editor->refusal($order);

        return [
            'edit_lines_url' => null === $refusal ? $this->urls->generate('admin.order.edit-lines', ['order_id' => $order->getId()]) : null,
            'edit_lines_refusal' => $refusal,
        ];
    }
}
