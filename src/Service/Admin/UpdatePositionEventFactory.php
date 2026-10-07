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
use Thelia\Core\Event\UpdatePositionEvent;

/**
 * The position change a list asks for, read from the query or the body.
 */
final readonly class UpdatePositionEventFactory
{
    public function fromRequest(Request $request, string $idField): UpdatePositionEvent
    {
        return new UpdatePositionEvent(
            (int) ($request->query->get($idField) ?? $request->request->get($idField, 0)),
            (int) ($request->query->get('mode') ?? $request->request->get('mode', UpdatePositionEvent::POSITION_ABSOLUTE)),
            (int) ($request->query->get('position') ?? $request->request->get('position', 0)),
        );
    }
}
