<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff PDF (QUO-014): ?revision=N opens the stored PDF of a sent version exactly as the client got it; without it,
 * a live preview of the current draft with a "DRAFT" watermark.
 */
final class QuotePdfController
{
    public function __invoke(Request $request, Quote $quote, QuoteSnapshot $snapshots, QuotePdf $pdf): Response
    {
        Gate::authorize('view', $quote);

        if ($request->filled('revision') || in_array($quote->status->value, ['sent', 'viewed', 'accepted', 'declined', 'expired', 'superseded', 'void'], true)) {
            $version = $request->filled('revision')
                ? $quote->versions()->where('revision', (int) $request->query('revision'))->firstOrFail()
                : $quote->versions()->reorder('revision', 'desc')->firstOrFail();
            abort_if($version->pdf_path === null || ! Storage::disk('local')->exists($version->pdf_path), 404);

            return Storage::disk('local')->response($version->pdf_path, QuotePdf::filename($version->snapshot), ['Content-Type' => 'application/pdf']);
        }

        $snapshot = $snapshots->build($quote);

        return response($pdf->render($snapshot, draft: true), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.QuotePdf::filename($snapshot).'"',
        ]);
    }
}
