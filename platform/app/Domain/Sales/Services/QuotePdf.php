<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Dompdf\Canvas;
use Dompdf\FontMetrics;
use Dompdf\Frame;

/**
 * A4 quotation PDF on the Studio letterhead (QUO-014), rendered from a snapshot (QuoteSnapshot) with dompdf.
 * Drafts carry a "DRAFT" watermark; sent versions are rendered once at send time and stored with their SHA-256.
 */
final class QuotePdf
{
    /** Position of "Page X of Y" in points (CSS px × 0.75): the template's right margin, and the footer text line. */
    private const float RIGHT_MARGIN = 31.5;

    private const float FOOTER_TEXT_Y = 29.6; // from the bottom edge: level with the footer text on the left

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function render(array $snapshot, bool $draft = false): string
    {
        $pdf = $this->document($snapshot, $draft);
        $closing = [];
        $measure = static function (Frame $frame, Canvas $canvas) use (&$closing): void {
            $node = $frame->get_node();
            if ($node instanceof \DOMElement && $node->getAttribute('id') === 'quotation-commercial-close') {
                $box = $frame->get_border_box();
                $closing[] = ['page' => $canvas->get_page_number(), 'y' => $box[1], 'height' => $box[3]];
            }
        };
        $anchor = ($snapshot['quote_layout'] ?? '') === 'furniture' && (int) ($snapshot['furniture_revision'] ?? 1) >= 5;
        if ($anchor) {
            $pdf->getDomPDF()->setCallbacks([['event' => 'end_frame', 'f' => $measure]]);
        }
        $pdf->render();
        // Dompdf has no flex/margin-top:auto support. Measure the native final-page group, then add
        // only the exact remaining space. A second layout is accepted only if it stays on the same page
        // within the content boundary. Oversized custom groups retain safe natural pagination.
        if ($anchor && count($closing) === 1) {
            $canvas = $pdf->getDomPDF()->getCanvas();
            $bottom = $canvas->get_height() - 58 * 0.75 - 1;
            $pages = $canvas->get_page_count();
            $space = floor(($bottom - $closing[0]['y'] - $closing[0]['height']) * 100) / 100;
            if ($closing[0]['page'] === $pages && $space > 1) {
                $candidate = $this->document($snapshot, $draft, $space);
                $closing = [];
                $candidate->getDomPDF()->setCallbacks([['event' => 'end_frame', 'f' => $measure]]);
                $candidate->render();
                if ($candidate->getDomPDF()->getCanvas()->get_page_count() === $pages && count($closing) === 1
                    && $closing[0]['page'] === $pages && $closing[0]['y'] + $closing[0]['height'] <= $bottom + 0.5) {
                    $pdf = $candidate;
                }
            }
        }

        // "Page X of Y", right-aligned in the footer: CSS counters in dompdf have no page total.
        $footerY = (int) ($snapshot['furniture_revision'] ?? 1) >= 2 ? 20.0 : self::FOOTER_TEXT_Y;
        $pdf->getDomPDF()->getCanvas()->page_script(function (int $page, int $pages, Canvas $canvas, FontMetrics $metrics) use ($footerY): void {
            $font = (string) $metrics->getFont('DejaVu Sans');
            $text = "Page {$page} of {$pages}";
            $width = $metrics->getTextWidth($text, $font, 6.0);
            $canvas->text($canvas->get_width() - self::RIGHT_MARGIN - $width, $canvas->get_height() - $footerY, $text, $font, 6.0, [0.431, 0.529, 0.675]);
        });

        return $pdf->output();
    }

    /** @param array<string, mixed> $snapshot */
    private function document(array $snapshot, bool $draft, float $commercialSpacer = 0): PdfDocument
    {
        return Pdf::loadView('pdf.quote', ['s' => $snapshot, 'draft' => $draft, 'commercialSpacer' => $commercialSpacer])
            ->setPaper('a4')
            ->setOption('isRemoteEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isFontSubsettingEnabled', true);
    }

    /**
     * The builder's live preview: the clean document HTML render() feeds to dompdf,
     * with screen-only sheet styling layered on by pdf.partials.preview-screen.
     * Page numbers are added to the PDF itself, so they are not part of this HTML.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function html(array $snapshot): string
    {
        return view('pdf.quote', ['s' => $snapshot, 'draft' => false, 'preview' => true])->render();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function filename(array $snapshot): string
    {
        return 'Woodex-Quotation-'.str_replace([' ', '·'], ['-', ''], (string) $snapshot['label']).'.pdf';
    }
}
