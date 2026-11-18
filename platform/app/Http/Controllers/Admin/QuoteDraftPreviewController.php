<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\QuoteDraftPreview;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Separate read-only request: a Livewire action would also flush pending blur-bound fields. */
final class QuoteDraftPreviewController
{
    public function __invoke(Request $request, Quote $quote, QuoteDraftPreview $projection, QuotePdf $pdf): JsonResponse
    {
        Gate::authorize('view', $quote);
        try {
            $snapshot = $quote->isEditable() && Gate::allows('update', $quote)
                ? $projection->build($quote, $request->json()->all())
                : ($quote->versions()->reorder('revision', 'desc')->first()->snapshot ?? app(QuoteSnapshot::class)->build($quote));

            return response()->json(['html' => $pdf->html($snapshot), 'error' => null])->header('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|ValidationException $exception) {
            return response()->json(['html' => null, 'error' => 'Preview paused: correct the incomplete or invalid field.'], 422)->header('Cache-Control', 'no-store');
        }
    }
}
