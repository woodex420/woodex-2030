<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Domain\Sales\Actions\CaptureStorefrontQuoteRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreQuoteRequest;
use App\Integrations\Turnstile\TurnstileVerifier;
use Illuminate\Http\JsonResponse;

final class QuoteRequestController extends Controller
{
    public function store(StoreQuoteRequest $request, CaptureStorefrontQuoteRequest $capture, TurnstileVerifier $turnstile): JsonResponse
    {
        if ($request->isSpam()) {
            return response()->json(['ok' => true, 'reference' => null], 202);
        }
        if (! $request->hasValidBridgeKey() && ! $turnstile->verify($request->input('turnstile_token'), $request->ip(), failClosed: true)) {
            return response()->json(['ok' => false, 'message' => 'The spam check failed. Please refresh and try again.'], 422);
        }
        $result = $capture->handle($request);

        return response()->json(['ok' => true, 'reference' => $result['reference'], 'status' => 'pending_review',
            'message' => 'Your basket request was saved for review. This is not an issued quotation.'], $result['created'] ? 201 : 200)
            ->header('Cache-Control', 'no-store, private');
    }
}
