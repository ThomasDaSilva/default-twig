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

namespace BackOfficeDefaultTwigBundle\Controller\Catalog;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Event\Category\CategoryAddContentEvent;
use Thelia\Core\Event\Category\CategoryDeleteContentEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\CategoryAssociatedContentQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\Content;
use Thelia\Model\ContentI18nQuery;
use Thelia\Model\ContentQuery;
use Thelia\Model\LangQuery;

final class CategoryRelationsController
{
    private const RESOURCE = AdminResources::CATEGORY;
    private const EDIT_ROUTE = 'admin.categories.update';
    private const CONTENT_SEARCH_LIMIT = 20;

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/admin/categories/related-content/add', name: 'admin.categories.related-content.add', methods: ['POST'])]
    public function addRelatedContent(Request $request): Response
    {
        $categoryId = (int) ($request->query->get('category_id') ?? $request->request->get('category_id', 0));
        $category = CategoryQuery::create()->findPk($categoryId);
        if ($category === null) {
            return new RedirectResponse($this->urls->generate('admin.categories.default'));
        }

        $contentId = (int) ($request->query->get('content_id') ?? $request->request->get('content_id', 0));
        if ($contentId <= 0) {
            return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['category_id' => $categoryId, 'current_tab' => 'associations']));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new CategoryAddContentEvent($category, $contentId),
            eventName: TheliaEvents::CATEGORY_ADD_CONTENT,
            actionLabel: 'Category related content added',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['category_id' => $categoryId, 'current_tab' => 'associations'],
        );
    }

    #[Route('/admin/categories/related-content/delete', name: 'admin.categories.related-content.delete', methods: ['POST'])]
    public function deleteRelatedContent(Request $request): Response
    {
        $categoryId = (int) ($request->query->get('category_id') ?? $request->request->get('category_id', 0));
        $category = CategoryQuery::create()->findPk($categoryId);
        if ($category === null) {
            return new RedirectResponse($this->urls->generate('admin.categories.default'));
        }

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::UPDATE,
            request: $request,
            event: new CategoryDeleteContentEvent($category, (int) ($request->query->get('content_id') ?? $request->request->get('content_id', 0))),
            eventName: TheliaEvents::CATEGORY_REMOVE_CONTENT,
            actionLabel: 'Category related content removed',
            successRoute: self::EDIT_ROUTE,
            successParameters: ['category_id' => $categoryId, 'current_tab' => 'associations'],
        );
    }

    #[Route('/admin/categories/related-picture/add', name: 'admin.categories.related-picture.add', methods: ['POST', 'GET'])]
    public function addRelatedPicture(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $categoryId = (int) ($request->query->get('category_id') ?? $request->request->get('category_id', 0));

        return new RedirectResponse($this->urls->generate(self::EDIT_ROUTE, ['category_id' => $categoryId, 'current_tab' => 'images']));
    }

    /**
     * The contents matching a search on their title, offered as related contents of
     * the category. Contents already related to it are out.
     */
    #[Route(
        '/admin/category/{categoryId}/available-related-content.json',
        name: 'admin.category.available-related-content',
        methods: ['GET'],
        requirements: ['categoryId' => '\d+'],
    )]
    public function availableRelatedContent(int $categoryId, Request $request): JsonResponse
    {
        if ($this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return new JsonResponse([], Response::HTTP_FORBIDDEN);
        }

        $term = trim((string) $request->query->get('q', ''));
        if ('' === $term) {
            return new JsonResponse([]);
        }

        $alreadyAssigned = CategoryAssociatedContentQuery::create()
            ->select('content_id')
            ->findByCategoryId($categoryId)
            ->toArray();

        // The title is searched in every language, so the operator finds a content
        // by the name they know it under, whichever translation carries it.
        $contents = ContentQuery::create()
            ->useContentI18nQuery()
                ->filterByTitle('%'.addcslashes($term, '%_\\').'%', Criteria::LIKE)
            ->endUse()
            ->filterById(array_map('intval', $alreadyAssigned), Criteria::NOT_IN)
            ->distinct()
            ->orderById()
            ->limit(self::CONTENT_SEARCH_LIMIT)
            ->find();

        $contentIds = array_map(static fn (Content $content): int => (int) $content->getId(), iterator_to_array($contents));
        // A content without a title in the edit locale shows the one the search
        // matched in another language rather than an empty suggestion.
        $locale = $this->searchLocale($request);
        $titles = [];
        $translations = ContentI18nQuery::create()
            ->filterById($contentIds, Criteria::IN)
            ->find();
        foreach ($translations as $translation) {
            $contentId = (int) $translation->getId();
            $title = (string) $translation->getTitle();
            if ('' !== $title && ($translation->getLocale() === $locale || !isset($titles[$contentId]))) {
                $titles[$contentId] = $title;
            }
        }

        $items = [];
        foreach ($contents as $content) {
            $contentId = (int) $content->getId();
            $items[] = ['id' => $contentId, 'title' => $titles[$contentId] ?? ''];
        }

        return new JsonResponse($items);
    }

    /**
     * The edit locale of the category sheet the search comes from, so a suggestion
     * reads like the rows of the related contents table.
     */
    private function searchLocale(Request $request): string
    {
        $locale = (string) $request->query->get('locale', '');

        if ('' !== $locale && null !== LangQuery::create()->findOneByLocale($locale)) {
            return $locale;
        }

        return $this->defaultLocale();
    }

    private function defaultLocale(): string
    {
        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }
}
