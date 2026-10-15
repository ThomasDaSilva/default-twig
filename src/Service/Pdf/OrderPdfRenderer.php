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

namespace BackOfficeDefaultTwigBundle\Service\Pdf;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Event\PdfEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderShipment;
use Thelia\Model\OrderShipmentQuery;

/**
 * Renders order invoice / delivery PDFs through the active PDF template,
 * keeping parity with the legacy back-office.
 *
 * The HTML produced by the template parser is then dispatched as a {@see PdfEvent}
 * so the Html2Pdf listener (or any third-party listener with higher priority)
 * converts it to a PDF binary stream.
 */
final class OrderPdfRenderer
{
    public const KIND_INVOICE = 'invoice';
    public const KIND_DELIVERY = 'delivery';

    // Extensionless names: ParserResolver picks the parser whose extension matches
    // a file in the active PDF template (invoice.html for Smarty, invoice.html.twig for Twig).
    private const PDF_FILE_BY_KIND = [
        self::KIND_INVOICE => 'invoice',
        self::KIND_DELIVERY => 'delivery',
    ];

    public function __construct(
        private readonly EventDispatcherInterface $events,
        private readonly TranslatorInterface $translator,
        private readonly ParserResolver $parserResolver,
        private readonly TemplateHelperInterface $templateHelper,
    ) {
    }

    public function render(int $orderId, string $kind, bool $browser, ?int $shipmentId = null): Response
    {
        if (!isset(self::PDF_FILE_BY_KIND[$kind])) {
            throw new \InvalidArgumentException(\sprintf('Unsupported PDF kind "%s"', $kind));
        }

        // A shipment narrows the delivery note to one parcel; an invoice is always the whole order.
        if (null !== $shipmentId && self::KIND_DELIVERY !== $kind) {
            throw new \InvalidArgumentException(\sprintf('A shipment can only be rendered as a "%s" PDF', self::KIND_DELIVERY));
        }

        $order = OrderQuery::create()->findPk($orderId);
        if (!$order instanceof Order) {
            throw new NotFoundHttpException(\sprintf('Order %d not found', $orderId));
        }

        $shipment = null;
        if (null !== $shipmentId) {
            $shipment = OrderShipmentQuery::create()
                ->filterById($shipmentId)
                ->filterByOrderId($orderId)
                ->findOne();
            if (!$shipment instanceof OrderShipment) {
                throw new NotFoundHttpException(\sprintf('Shipment %d not found for order %d', $shipmentId, $orderId));
            }
        }

        $locale = $this->resolveLocale();
        $pdfFile = self::PDF_FILE_BY_KIND[$kind];

        $pdfTemplate = $this->templateHelper->getActivePdfTemplate();
        $parser = $this->parserResolver->getParser($pdfTemplate->getAbsolutePath(), $pdfFile);
        $parser->setTemplateDefinition($pdfTemplate, true);
        $parser->assign('order_id', $orderId);
        $parser->assign('lang', $locale);
        // Assigned even when null, so a parser reused within the request keeps no shipment from a previous render.
        $parser->assign('shipment_id', $shipmentId);

        $html = $parser->render($pdfFile);

        $fileName = match ($kind) {
            self::KIND_INVOICE => (string) ConfigQuery::read('pdf_invoice_file', 'invoice'),
            self::KIND_DELIVERY => (string) ConfigQuery::read('pdf_delivery_file', 'delivery'),
        };

        $event = new PdfEvent($html, 'P', 'A4', $this->langCodeFor($locale));
        $event->setTemplateName($fileName);
        $event->setFileName((string) $order->getRef());
        $event->setObject($order);

        try {
            $this->events->dispatch($event, TheliaEvents::GENERATE_PDF);
        } catch (\Throwable $exception) {
            Tlog::getInstance()->error(\sprintf(
                'PDF generation failed for order %d (%s): %s',
                $orderId,
                $kind,
                $exception->getMessage(),
            ));

            throw $exception;
        }

        if (!$event->hasPdf()) {
            throw new \RuntimeException('PDF rendering listener did not produce a binary output.');
        }

        // The shipment reference already begins with the order's.
        $downloadName = $shipment instanceof OrderShipment
            ? \sprintf('%s-%s', $kind, (string) $shipment->getRef())
            : \sprintf('%s-%s', $kind, (string) $order->getRef());

        $disposition = $browser ? 'inline' : 'attachment';
        $response = new Response($event->getPdf());
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            \sprintf('%s; filename="%s.pdf"', $disposition, $downloadName),
        );

        return $response;
    }

    private function resolveLocale(): string
    {
        if ($this->translator instanceof Translator) {
            $current = $this->translator->getLocale();
            if ($current !== '') {
                return $current;
            }
        }

        $defaultLang = LangQuery::create()->findOneByByDefault(1);

        return $defaultLang?->getLocale() ?? 'en_US';
    }

    private function langCodeFor(string $locale): string
    {
        $parts = explode('_', $locale);

        return strtolower($parts[0]);
    }
}
