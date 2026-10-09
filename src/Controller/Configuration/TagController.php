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

use BackOfficeDefaultTwigBundle\Form\Configuration\TagType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFailureMessage;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormValidator;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Customer\CustomerFilters;
use BackOfficeDefaultTwigBundle\Service\Tag\TagSwatch;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Tagging\Exception\TagLabelAlreadyUsedException;
use Thelia\Domain\Tagging\Service\TagService;
use Thelia\Model\Tag;
use Thelia\Model\TagQuery;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * The tag vocabulary of the shop: what an administrator may put on a customer.
 *
 * Its own admin resource rather than the customer one: a profile allowed to
 * rename or delete a tag reaches every customer carrying it, which is not the
 * same reach as editing one customer.
 */
#[Route('/admin/configuration/tags', name: 'admin.configuration.tags.')]
final class TagController
{
    private const RESOURCE = AdminResources::TAG;
    private const LIST_ROUTE = 'admin.configuration.tags.default';
    private const EDIT_ROUTE = 'admin.configuration.tags.update';
    private const FORM_NAME = 'thelia_tag_update';
    private const CREATE_FORM_NAME = 'thelia_tag_create';
    private const CUSTOMER_LIST_ROUTE = 'admin.customers';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/configuration/tag/list.html.twig';
    private const EDIT_TEMPLATE = '@BackOfficeDefaultTwig/configuration/tag/edit.html.twig';
    private const DELETE_MODAL_ID = '#tag-delete-modal';
    private const MERGE_TEMPLATE = '@BackOfficeDefaultTwig/configuration/tag/merge.html.twig';

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly TagService $tags,
        private readonly FormFactoryInterface $formFactory,
        private readonly AdminFormValidator $validator,
        private readonly AdminFormErrorRenderer $errorRenderer,
        private readonly AdminLogger $adminLogger,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly TokenProvider $tokens,
        private readonly TagSwatch $swatch,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $sort = ListSort::fromRequest($request, ['id', 'label'], 'label');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;

        $query = TagQuery::create();
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            default => $query->orderByLabel($criteria),
        };

        // One grouped query for the whole table rather than a count per row: the
        // screen shows every tag, so a per-row count would be one query per line.
        $customerCounts = $this->tags->countCustomersByTag();

        $rows = [];
        foreach ($query->find() as $tag) {
            \assert($tag instanceof Tag);
            $rows[] = [
                'id' => (int) $tag->getId(),
                'label' => (string) $tag->getLabel(),
                'color_code' => (string) $tag->getColorCode(),
                'color_swatch' => $this->colorSwatch((string) $tag->getColorCode()),
                'customer_count' => $customerCounts[$tag->getId()] ?? 0,
                'customers_html' => $this->customersLink((int) $tag->getId(), $customerCounts[$tag->getId()] ?? 0),
                '_actions' => [
                    new RowAction(
                        kind: 'edit',
                        label: $this->translator->trans('Edit'),
                        href: $this->urls->generate(self::EDIT_ROUTE, ['tag_id' => (int) $tag->getId()]),
                        grantedAttribute: AccessManager::UPDATE,
                        grantedSubject: self::RESOURCE,
                    ),
                    new RowAction(
                        kind: 'delete',
                        label: $this->translator->trans('Delete'),
                        modalTarget: self::DELETE_MODAL_ID,
                        grantedAttribute: AccessManager::DELETE,
                        grantedSubject: self::RESOURCE,
                        dataAttributes: ['tag-id' => (int) $tag->getId()],
                    ),
                ],
            ];
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
            'create_form' => $this->buildCreateForm()->createView(),
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        $form = $this->buildCreateForm();

        try {
            $data = $this->validator->validate($form)->getData();

            // The checkbox is what expresses "no colour": a native colour input has
            // no empty state and always posts a value, black by default.
            $colorCode = ($data['noColor'] ?? false) === true
                ? null
                : (($data['colorCode'] ?? '') === '' ? null : (string) $data['colorCode']);

            $tag = $this->tags->create((string) $data['label'], $colorCode);

            $this->adminLogger->log(self::RESOURCE, AccessManager::CREATE, 'Tag created', (int) $tag->getId());

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        } catch (\Throwable $exception) {
            $this->errorRenderer->refuse(
                $this->translator->trans('Tag creation failed.'),
                $this->refusalMessage($exception),
                $form,
                $exception,
            );

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }
    }

    #[Route('/update', name: 'update', methods: ['GET'])]
    public function update(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $tag = TagQuery::create()->findPk($request->query->getInt('tag_id'));

        if (!$tag instanceof Tag) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'form' => $this->buildForm($tag)->createView(),
            'tag' => [
                'id' => (int) $tag->getId(),
                'label' => (string) $tag->getLabel(),
                'customer_count' => $this->tags->countCustomersByTag()[$tag->getId()] ?? 0,
                'customers_url' => $this->customersCarryingUrl((int) $tag->getId()),
            ],
            'merge_targets' => $this->mergeTargets($tag),
        ]));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $form = $this->buildForm();

        try {
            $validated = $this->validator->validate($form);
            $data = $validated->getData();

            $tag = TagQuery::create()->findPk((int) $data['id'])
                ?? throw new \InvalidArgumentException('This tag no longer exists.');

            // The checkbox is what expresses "no colour": a native colour input has
            // no empty state and always posts a value, black by default.
            $colorCode = ($data['noColor'] ?? false) === true
                ? null
                : (($data['colorCode'] ?? '') === '' ? null : (string) $data['colorCode']);

            $this->tags->rename($tag, (string) $data['label'], $colorCode);

            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, 'Tag renamed', (int) $tag->getId());

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        } catch (\Throwable $exception) {
            $this->errorRenderer->refuse(
                $this->translator->trans('Tag update failed.'),
                $this->refusalMessage($exception, suggestMerge: true),
                $form,
                $exception,
            );

            // Re-read from the posted identifier rather than rendering an empty
            // stub: the screen comes back with the refusal on it, and a header
            // reading "#0, 0 customers" would tell the administrator their tag
            // had been emptied when nothing was written at all.
            $edited = TagQuery::create()->findPk((int) ($request->request->all(self::FORM_NAME)['id'] ?? 0));

            if (!$edited instanceof Tag) {
                return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
            }

            return new Response(
                $this->twig->render(self::EDIT_TEMPLATE, [
                    'form' => $form->createView(),
                    'tag' => [
                        'id' => (int) $edited->getId(),
                        'label' => (string) $edited->getLabel(),
                        'customer_count' => $this->tags->countCustomersByTag()[$edited->getId()] ?? 0,
                        'customers_url' => $this->customersCarryingUrl((int) $edited->getId()),
                    ],
                    'merge_targets' => $this->mergeTargets($edited),
                ]),
                Response::HTTP_BAD_REQUEST,
            );
        }
    }

    /**
     * Asks for the confirmation of a merge, and performs it once confirmed.
     *
     * A separate page rather than a dialog on the list: the confirmation has to
     * announce how many customers will carry the surviving tag, and that number
     * is not the sum of the two counts — a customer carrying both is one
     * customer. A shared dialog cannot render a figure that depends on the pair.
     */
    #[Route('/merge', name: 'merge', methods: ['GET', 'POST'])]
    public function merge(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $absorbed = TagQuery::create()->findPk((int) ($request->request->get('tag_id') ?? $request->query->get('tag_id') ?? 0));
        $surviving = TagQuery::create()->findPk((int) ($request->request->get('into') ?? $request->query->get('into') ?? 0));

        if (!$absorbed instanceof Tag || !$surviving instanceof Tag || $absorbed->getId() === $surviving->getId()) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        if ($request->isMethod('GET')) {
            return new Response($this->twig->render(self::MERGE_TEMPLATE, [
                'absorbed' => ['id' => (int) $absorbed->getId(), 'label' => (string) $absorbed->getLabel()],
                'surviving' => ['id' => (int) $surviving->getId(), 'label' => (string) $surviving->getLabel()],
                'resulting_customer_count' => $this->tags->countCustomersCarryingEither($absorbed, $surviving),
            ]));
        }

        try {
            // Irreversible, so the same guard as the deletion: a merge strips one
            // tag from the vocabulary and rewrites the attachments of every
            // customer carrying it.
            $this->tokens->checkToken(
                (string) $request->request->get('_token', ''),
            );

            $this->tags->merge($absorbed, $surviving);

            $this->adminLogger->log(
                self::RESOURCE,
                AccessManager::UPDATE,
                \sprintf('Tag "%s" merged into "%s"', (string) $absorbed->getLabel(), (string) $surviving->getLabel()),
                (int) $surviving->getId(),
            );
        } catch (\Throwable $exception) {
            $this->errorRenderer->fail(
                $this->translator->trans('Tag merge'),
                $exception,
            );
        }

        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::DELETE)) {
            return $denied;
        }

        try {
            // The confirmation dialog carries the token in the query string, a
            // posted form carries it in the body: accept either, refuse neither
            // silently. Deleting a tag takes it off every customer at once, so an
            // unguarded GET would be a one-click forgery.
            $this->tokens->checkToken(
                (string) $request->request->get('_token', ''),
            );
        } catch (\Throwable $exception) {
            $this->errorRenderer->fail(
                $this->translator->trans('Tag deletion'),
                $exception,
            );

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $tagId = (int) ($request->request->get('tag_id') ?? $request->query->get('tag_id') ?? 0);
        $tag = TagQuery::create()->findPk($tagId);

        if ($tag instanceof Tag) {
            // The foreign key on tag_element.tag_id cascades, so the attachments
            // go with it and no customer is left pointing at a tag that is gone.
            $tag->delete();
            $this->adminLogger->log(self::RESOURCE, AccessManager::DELETE, 'Tag deleted', $tagId);
        }

        return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
    }

    /**
     * Every other tag, so the screen never offers a tag as its own target: the
     * service refuses that, and an option that can only fail is a trap.
     *
     * @return list<array{id: int, label: string}>
     */
    private function mergeTargets(Tag $absorbed): array
    {
        $targets = [];

        foreach (TagQuery::create()->orderByLabel()->find() as $candidate) {
            \assert($candidate instanceof Tag);

            if ($candidate->getId() === $absorbed->getId()) {
                continue;
            }

            $targets[] = ['id' => (int) $candidate->getId(), 'label' => (string) $candidate->getLabel()];
        }

        return $targets;
    }

    /**
     * What the administrator reads when a write was refused.
     *
     * Only the collision is translated here: it is the one refusal an
     * administrator can actually trigger from these screens, the others being
     * caught by the form validator or made unreachable by the screen itself.
     * Anything else keeps the raw exception message, which is what the rest of
     * this back-office does.
     */
    private function refusalMessage(\Throwable $exception, bool $suggestMerge = false): string
    {
        if (!$exception instanceof TagLabelAlreadyUsedException) {
            return AdminFailureMessage::of($exception, $this->translator);
        }

        // Two sentences rather than one: when the collision is on the very same
        // spelling, naming the other tag would print the label twice and read as
        // nonsense.
        $message = $exception->isSameSpelling()
            ? $this->translator->trans('A tag named "%label%" already exists.', ['%label%' => $exception->existingLabel])
            : $this->translator->trans(
                'The label "%label%" is already carried by the tag "%existing%".',
                ['%label%' => $exception->requestedLabel, '%existing%' => $exception->existingLabel],
            );

        if (!$suggestMerge) {
            return $message;
        }

        return $message.' '.$this->translator->trans('Merge the two tags instead of renaming this one.');
    }

    private function buildCreateForm(): FormInterface
    {
        // noColor ticked by default: a native colour input has no empty state and
        // posts black, so a tag created without touching the picker would come
        // out black rather than colourless — the same trap the edit screen
        // already handles by ticking the box for a tag that has no colour.
        return $this->formFactory->createNamed(
            self::CREATE_FORM_NAME,
            TagType::class,
            ['noColor' => true],
            ['include_id' => false],
        );
    }

    /**
     * A link to the customers carrying this tag, or the bare count when the
     * profile cannot open the customer list: a link that answers 403 is worse
     * than no link.
     */
    private function customersLink(int $tagId, int $customerCount): string
    {
        $label = $this->translator->trans('View customers (%count%)', ['%count%' => $customerCount]);

        if ($this->access->check(AdminResources::CUSTOMER, [], AccessManager::VIEW) !== null) {
            return \sprintf('<span class="text-muted">%d</span>', $customerCount);
        }

        return \sprintf(
            '<a class="btn btn-sm btn-outline-secondary" href="%s" data-testid="tag-customers-link"><i class="bi bi-eye" aria-hidden="true"></i> %s</a>',
            htmlspecialchars($this->customersCarryingUrl($tagId)),
            htmlspecialchars($label),
        );
    }

    /**
     * The customer list, pre-filtered on this tag.
     *
     * Built here and not in the template: the filter key belongs to the customer
     * screen, and a hand-written query string in Twig would drift from it the
     * day that key changes.
     */
    private function customersCarryingUrl(int $tagId): string
    {
        return $this->urls->generate(self::CUSTOMER_LIST_ROUTE, [CustomerFilters::KEY_TAG_IDS => [$tagId]]);
    }

    private function buildForm(?Tag $tag = null): FormInterface
    {
        return $this->formFactory->createNamed(self::FORM_NAME, TagType::class, $tag instanceof Tag ? [
            'id' => (int) $tag->getId(),
            'label' => (string) $tag->getLabel(),
            'colorCode' => $tag->getColorCode(),
            'noColor' => $tag->getColorCode() === null || $tag->getColorCode() === '',
        ] : []);
    }

    /**
     * A coloured square, or nothing at all when the stored value is not a colour.
     *
     * The colour is checked by TagSwatch and not here: it is the one place that
     * decides whether a tag colour may reach a style attribute.
     */
    private function colorSwatch(string $colorCode): string
    {
        $color = $this->swatch->color($colorCode);

        if ($color === null) {
            return '';
        }

        return \sprintf(
            '<span class="d-inline-block rounded border" style="width:1rem;height:1rem;background-color:%s" aria-hidden="true"></span>',
            $color,
        );
    }
}
