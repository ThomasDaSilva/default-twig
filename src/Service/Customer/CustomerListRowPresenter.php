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

namespace BackOfficeDefaultTwigBundle\Service\Customer;

use BackOfficeDefaultTwigBundle\Service\I18n\MoneyFormatter;
use BackOfficeDefaultTwigBundle\Service\Tag\TagSwatch;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Customer;
use Thelia\Model\Tag;

/**
 * Maps a Customer model to the DataTable row shape used by
 * `customer/list.html.twig`. Aggregations (orders count, total spent, last
 * order, primary phone/country) are passed in as pre-batched
 * maps to avoid N+1.
 */
final readonly class CustomerListRowPresenter
{
    private const RESOURCE = AdminResources::CUSTOMER;
    private const EDIT_ROUTE = 'admin.customer.update.view';
    private const ORDER_LIST_ROUTE = 'admin.order.list';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private TagSwatch $swatch,
        private MoneyFormatter $money,
    ) {
    }

    /**
     * @param list<Tag> $tags
     *
     * @return array<string, mixed>
     */
    public function present(
        Customer $customer,
        string $locale,
        int $orderCount,
        float $totalSpent,
        ?\DateTimeImmutable $lastOrderAt,
        string $phone,
        string $countryFlag,
        string $countryTitle,
        array $tags,
    ): array {
        $customerId = (int) $customer->getId();
        $firstname = (string) $customer->getFirstname();
        $lastname = (string) $customer->getLastname();
        $email = (string) $customer->getEmail();
        $createdAt = $customer->getCreatedAt();

        return [
            'id' => $customerId,
            'ref' => (string) ($customer->getRef() ?: '-'),
            'ref_html' => $this->renderRef($customerId, (string) ($customer->getRef() ?: '-')),
            'name_html' => $this->renderName($customerId, $firstname, $lastname, $email, $countryFlag, $countryTitle),
            'phone_html' => $this->renderPhone($phone),
            'orders_html' => $this->renderOrders($customerId, $orderCount),
            'total_spent_html' => $this->renderTotalSpent($totalSpent, $orderCount, $locale),
            'last_order_html' => $this->renderLastOrder($lastOrderAt),
            'created_html' => $this->renderCreatedAt($createdAt),
            'guest_html' => $this->renderGuest($customer->isGuest()),
            'tags_html' => $this->renderTags($tags),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            '_actions' => $this->buildActions($customerId, $firstname, $lastname),
        ];
    }

    private function renderRef(int $customerId, string $ref): string
    {
        $href = $this->urls->generate(self::EDIT_ROUTE, ['customer_id' => $customerId]);

        return \sprintf(
            '<a href="%s" class="text-decoration-none" data-testid="bo-customer-ref-link">%s</a>',
            htmlspecialchars($href),
            htmlspecialchars($ref),
        );
    }

    private function renderName(int $customerId, string $firstname, string $lastname, string $email, string $countryFlag, string $countryTitle): string
    {
        $fullName = trim($firstname.' '.$lastname);
        if ($fullName === '') {
            $fullName = $email;
        }

        $href = $this->urls->generate(self::EDIT_ROUTE, ['customer_id' => $customerId]);

        // The flag stands before the name, as in the order list; the country name stays reachable on hover.
        $flag = $countryFlag === ''
            ? ''
            : \sprintf(
                '<span class="bo-customer-country" data-bs-toggle="tooltip" title="%s">%s</span> ',
                htmlspecialchars($countryTitle),
                htmlspecialchars($countryFlag),
            );

        return \sprintf(
            '<div class="bo-customer-name"><div class="fw-semibold">%s<a href="%s" class="text-decoration-none" data-testid="bo-customer-name-link">%s</a></div><div class="text-muted small text-truncate">%s</div></div>',
            $flag,
            htmlspecialchars($href),
            htmlspecialchars($fullName),
            htmlspecialchars($email),
        );
    }

    private function renderPhone(string $phone): string
    {
        if ($phone === '') {
            return '<span class="text-muted">-</span>';
        }

        return \sprintf('<span class="bo-customer-phone">%s</span>', htmlspecialchars($phone));
    }

    private function renderOrders(int $customerId, int $count): string
    {
        if ($count === 0) {
            return '<span class="badge text-bg-light bo-customer-orders">0</span>';
        }

        $url = $this->urls->generate(self::ORDER_LIST_ROUTE, ['customer_id' => $customerId]);
        $tooltip = $this->translator->trans('See this customer orders');

        return \sprintf(
            '<a href="%s" class="badge text-bg-primary bo-customer-orders text-decoration-none" data-bs-toggle="tooltip" title="%s"><i class="bi bi-bag me-1" aria-hidden="true"></i>%d</a>',
            htmlspecialchars($url),
            htmlspecialchars($tooltip),
            $count,
        );
    }

    private function renderTotalSpent(float $total, int $orderCount, string $locale): string
    {
        if ($orderCount === 0) {
            return '<span class="text-muted">-</span>';
        }

        return \sprintf(
            '<span class="bo-customer-total fw-semibold">%s</span>',
            htmlspecialchars($this->money->format($total, $locale)),
        );
    }

    private function renderLastOrder(?\DateTimeImmutable $lastOrderAt): string
    {
        if ($lastOrderAt === null) {
            return '<span class="text-muted fst-italic">'.htmlspecialchars($this->translator->trans('Never')).'</span>';
        }

        $iso = $lastOrderAt->format(\DateTimeInterface::ATOM);
        $display = $lastOrderAt->format('d/m/Y');
        $relative = $this->relativeTime($lastOrderAt);

        return \sprintf(
            '<time datetime="%s" title="%s"><div>%s</div><div class="text-muted small">%s</div></time>',
            htmlspecialchars($iso),
            htmlspecialchars($display),
            htmlspecialchars($display),
            htmlspecialchars($relative),
        );
    }

    private function renderCreatedAt(?\DateTimeInterface $createdAt): string
    {
        if ($createdAt === null) {
            return '<span class="text-muted">-</span>';
        }

        $iso = $createdAt->format(\DateTimeInterface::ATOM);
        $display = $createdAt->format('d/m/Y');
        $relative = $this->relativeTime($createdAt);

        return \sprintf(
            '<time datetime="%s" title="%s"><div>%s</div><div class="text-muted small">%s</div></time>',
            htmlspecialchars($iso),
            htmlspecialchars($display),
            htmlspecialchars($display),
            htmlspecialchars($relative),
        );
    }


    /**
     * @param list<Tag> $tags
     */
    private function renderTags(array $tags): string
    {
        if ($tags === []) {
            return '<span class="text-muted">-</span>';
        }

        $badges = '';

        foreach ($tags as $tag) {
            $color = $this->swatch->color($tag->getColorCode());
            $dot = $color === null
                ? ''
                : \sprintf('<span class="bo-tag-badge__swatch" style="background-color:%s"></span>', htmlspecialchars($color));

            // The title carries the whole label: the cell truncates it, and a
            // truncated tag with no way to read it in full would be worse than
            // no column at all.
            $badges .= \sprintf(
                '<span class="bo-tag-badge" title="%s">%s<span class="bo-tag-badge__label">%s</span></span>',
                htmlspecialchars((string) $tag->getLabel()),
                $dot,
                htmlspecialchars((string) $tag->getLabel()),
            );
        }

        return \sprintf('<span class="bo-tag-badges" data-testid="bo-customer-tags">%s</span>', $badges);
    }

    private function renderGuest(bool $isGuest): string
    {
        if ($isGuest) {
            // is_guest stays 1 until the account is activated (not merely converted),
            // so this also covers a customer who chose a password but never answered
            // the activation email.
            $tooltip = $this->translator->trans('Guest order, or account not yet activated');

            return \sprintf(
                '<span class="badge text-bg-warning-subtle text-warning-emphasis bo-customer-guest" data-bs-toggle="tooltip" title="%s"><i class="bi bi-person-vcard" aria-hidden="true"></i> %s</span>',
                htmlspecialchars($tooltip),
                htmlspecialchars($this->translator->trans('Guest')),
            );
        }

        $tooltip = $this->translator->trans('Registered account');

        return \sprintf(
            '<span class="badge text-bg-light text-muted bo-customer-guest" data-bs-toggle="tooltip" title="%s"><i class="bi bi-person-check" aria-hidden="true"></i></span>',
            htmlspecialchars($tooltip),
        );
    }

    private function relativeTime(\DateTimeInterface $createdAt): string
    {
        $diffSeconds = time() - $createdAt->getTimestamp();
        if ($diffSeconds < 60) {
            return $this->translator->trans('Just now');
        }
        if ($diffSeconds < 3600) {
            $minutes = (int) floor($diffSeconds / 60);

            return $this->translator->trans('%count% min ago', ['%count%' => $minutes]);
        }
        if ($diffSeconds < 86400) {
            $hours = (int) floor($diffSeconds / 3600);

            return $this->translator->trans('%count% h ago', ['%count%' => $hours]);
        }
        $days = (int) floor($diffSeconds / 86400);
        if ($days < 30) {
            return $this->translator->trans('%count% d ago', ['%count%' => $days]);
        }
        $months = (int) floor($days / 30);
        if ($months < 12) {
            return $this->translator->trans('%count% mo ago', ['%count%' => $months]);
        }
        $years = (int) floor($days / 365);

        return $this->translator->trans('%count% y ago', ['%count%' => $years]);
    }

    /**
     * @return list<RowAction>
     */
    private function buildActions(int $customerId, string $firstname, string $lastname): array
    {
        return [
            new RowAction(
                kind: 'edit',
                label: $this->translator->trans('Edit this customer'),
                href: $this->urls->generate(self::EDIT_ROUTE, ['customer_id' => $customerId]),
                grantedAttribute: AccessManager::UPDATE,
                grantedSubject: self::RESOURCE,
            ),
            new RowAction(
                kind: 'delete',
                label: $this->translator->trans('Delete this customer'),
                modalTarget: '#customer-delete-modal',
                grantedAttribute: AccessManager::DELETE,
                grantedSubject: self::RESOURCE,
                dataAttributes: [
                    'customer-id' => $customerId,
                    'customer-label' => trim($firstname.' '.$lastname),
                ],
            ),
        ];
    }
}
