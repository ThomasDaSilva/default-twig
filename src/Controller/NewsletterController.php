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

namespace BackOfficeDefaultTwigBundle\Controller;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormAction;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\ListSort;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\Newsletter\NewsletterEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Newsletter;
use Thelia\Model\NewsletterQuery;
use Twig\Environment;

#[Route('/admin/newsletter', name: 'admin.newsletter.')]
final class NewsletterController
{
    private const RESOURCE = 'admin.newsletter';
    private const LIST_ROUTE = 'admin.newsletter.default';
    private const LIST_TEMPLATE = '@BackOfficeDefaultTwig/newsletter/list.html.twig';

    public function __construct(
        private readonly AdminFormAction $action,
        private readonly AdminAccessChecker $access,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly Packages $assetPackages,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $sort = ListSort::fromRequest($request, ['id', 'email', 'firstname', 'lastname', 'created_at'], 'created_at', 'desc');
        $criteria = strtoupper($sort->direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $query = NewsletterQuery::create()->filterByUnsubscribed(false);
        match ($sort->field) {
            'id' => $query->orderById($criteria),
            'email' => $query->orderByEmail($criteria),
            'firstname' => $query->orderByFirstname($criteria),
            'lastname' => $query->orderByLastname($criteria),
            default => $query->orderByCreatedAt($criteria),
        };
        $subscribers = $query->find();
        $rows = [];
        foreach ($subscribers as $subscriber) {
            \assert($subscriber instanceof Newsletter);
            $rows[] = $this->subscriberToRow($subscriber);
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'rows' => $rows,
            'export_url' => $this->urls->generate('admin.newsletter.export'),
            'sort_field' => $sort->field,
            'sort_direction' => $sort->direction,
        ]));
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $subscriber = NewsletterQuery::create()->findPk((int) $request->query->get('newsletter_id', 0));
        if ($subscriber === null) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE));
        }

        $event = new NewsletterEvent($subscriber->getEmail(), (string) $subscriber->getLocale());
        $event->setNewsletter($subscriber);
        $event->setId((string) $subscriber->getId());

        return $this->action->tokenAction(
            resource: self::RESOURCE,
            access: AccessManager::DELETE,
            request: $request,
            event: $event,
            eventName: TheliaEvents::NEWSLETTER_UNSUBSCRIBE,
            actionLabel: 'Newsletter subscriber removal',
            successRoute: self::LIST_ROUTE,
        );
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $response = new StreamedResponse(static function (): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, self::csvLine(['email', 'firstname', 'lastname', 'locale', 'created_at']));

            foreach (NewsletterQuery::create()->orderByCreatedAt()->find() as $subscriber) {
                \assert($subscriber instanceof Newsletter);
                fwrite($handle, self::csvLine(array_map(self::asSpreadsheetText(...), [
                    (string) $subscriber->getEmail(),
                    (string) $subscriber->getFirstname(),
                    (string) $subscriber->getLastname(),
                    (string) $subscriber->getLocale(),
                    $subscriber->getCreatedAt() instanceof \DateTimeInterface ? $subscriber->getCreatedAt()->format('Y-m-d H:i:s') : '',
                ])));
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="newsletter-subscribers-'.date('Y-m-d').'.csv"');

        return $response;
    }

    /**
     * A spreadsheet reads a cell starting with = + - @, a tab or a carriage return as
     * a formula: such a value is written behind a quote, as text. A plain signed number
     * (-5.00, +33612345678) calls nothing and stays a number.
     */
    private static function asSpreadsheetText(string $value): string
    {
        if ($value === '' || preg_match('/^[+-]?[0-9]+([.,][0-9]+)?$/', $value) === 1) {
            return $value;
        }

        return \in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * The line fputcsv() writes, except that a cell holding a semicolon is enclosed too: a
     * spreadsheet set to a French locale splits the line on it, and the part after it
     * would start a new cell, unguarded.
     *
     * @param list<string> $cells
     */
    private static function csvLine(array $cells): string
    {
        return implode(',', array_map(
            static fn (string $cell): string => preg_match('/[,;"\s]/', $cell) === 1
                ? '"'.str_replace('"', '""', $cell).'"'
                : $cell,
            $cells,
        ))."\n";
    }

    /** @return array<string, mixed> */
    private function subscriberToRow(Newsletter $subscriber): array
    {
        $id = (int) $subscriber->getId();
        $actions = [
            new RowAction(kind: 'delete', label: $this->translator->trans('Unsubscribe'), href: $this->urls->generate('admin.newsletter.delete', ['newsletter_id' => $id]), grantedAttribute: AccessManager::DELETE, grantedSubject: self::RESOURCE, dataAttributes: ['bo-post' => '']),
        ];

        return [
            'id' => $id,
            'email' => (string) $subscriber->getEmail(),
            'firstname' => (string) $subscriber->getFirstname(),
            'lastname' => (string) $subscriber->getLastname(),
            'locale' => $this->localeFlagHtml((string) $subscriber->getLocale()),
            'created_at' => $subscriber->getCreatedAt() instanceof \DateTimeInterface ? $subscriber->getCreatedAt()->format('Y-m-d H:i') : '',
            '_actions' => $actions,
        ];
    }

    /**
     * Renders the subscriber's locale (e.g. "fr_FR") as its country flag, with the
     * locale itself as tooltip. Falls back to the plain locale text when it doesn't
     * carry a recognizable 2-letter country code or the matching SVG doesn't exist.
     */
    private function localeFlagHtml(string $locale): string
    {
        $safeLocale = htmlspecialchars($locale, \ENT_QUOTES);

        $countryCode = strtolower(substr(strrchr($locale, '_') ?: $locale, -2));

        // Only ever accept a plain 2-letter code: $locale is user-submitted at
        // newsletter sign-up, not picked from a closed list, and $countryCode is
        // interpolated unescaped into the src attribute below.
        if (!preg_match('/^[a-z]{2}$/', $countryCode)) {
            return $safeLocale;
        }

        $svgPath = \dirname(__DIR__, 2).'/assets/img/svgFlags/'.$countryCode.'.svg';

        if (!is_file($svgPath)) {
            return $safeLocale;
        }

        return \sprintf(
            '<img src="%s" alt="%s" title="%s" width="22" height="14">',
            htmlspecialchars($this->assetPackages->getUrl('backoffice/img/svgFlags/'.$countryCode.'.svg'), \ENT_QUOTES),
            $safeLocale,
            $safeLocale,
        );
    }
}
