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

namespace BackOfficeDefaultTwigBundle\Service\Listing;

use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use Symfony\Component\HttpFoundation\Request;

/**
 * The state of the folder list the user is on: the sort of its subfolders and
 * the page of its contents. The actions of the list (online toggles,
 * deletions) carry it in their URL, so their redirect lands back on the same
 * page with the same sort.
 */
final readonly class FolderListQuery
{
    public const SORT_FIELDS = ['id', 'title', 'visible', 'position'];

    public const DEFAULT_SORT = 'position';

    public function __construct(
        public ListSort $sort,
        public int $contentPage,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            ListSort::fromRequest($request, self::SORT_FIELDS, self::DEFAULT_SORT),
            max(1, (int) $request->query->get('content_page', 1)),
        );
    }

    /**
     * The state without the folder: the actions name their own folder_id.
     *
     * @return array{order: string, direction: string, content_page: int}
     */
    public function toQueryParams(): array
    {
        return [
            'order' => $this->sort->field,
            'direction' => $this->sort->direction,
            'content_page' => $this->contentPage,
        ];
    }

    /**
     * @return array{folder_id: int, order: string, direction: string, content_page: int}
     */
    public function toListParams(int $folderId): array
    {
        return ['folder_id' => $folderId] + $this->toQueryParams();
    }
}
