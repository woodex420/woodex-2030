<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Sales\Actions\CaptureStorefrontQuoteRequest;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ApiV1;
use App\Http\Requests\Api\StoreQuoteRequest;
use App\Integrations\Turnstile\TurnstileVerifier;
use App\Support\Api\ApiAnswer;
use App\Support\Api\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/quote-requests: the basket of a visitor of the shop, saved for the team to review. It is priced here
 * from the catalogue, never from what the page sent, and it is not a quotation.
 *
 * 201 saved · 202 taken for a robot's: it looks accepted and nothing is kept. Sent twice with one Idempotency-Key,
 * it is written once and answered the same, even when a product of it has been withdrawn in between.
 */
final class QuoteRequestController extends Controller
{
    private const array PRIVATE = ['Cache-Control' => 'no-store, private'];

    public function store(StoreQuoteRequest $request, CaptureStorefrontQuoteRequest $capture, TurnstileVerifier $turnstile, Idempotency $idempotency): JsonResponse
    {
        if ($request->isSpam()) {
            return ApiAnswer::one(['reference' => null, 'status' => null], 202, self::PRIVATE);
        }

        $key = (string) $request->header(ApiV1::KEY_HEADER);
        $fingerprint = Idempotency::fingerprint($capture->whatWasAsked($request));

        $outcome = $idempotency->seen('quote-requests', $key, $fingerprint);

        if ($outcome === null) {
            if (! $request->hasValidBridgeKey() && ! $turnstile->verify($request->input('turnstile_token'), $request->ip(), failClosed: true)) {
                throw ValidationException::withMessages(['turnstile_token' => 'The spam check failed. Please refresh and try again.']);
            }

            $outcome = $idempotency->once('quote-requests', $key, $fingerprint, fn (): array => [201, [
                'reference' => $capture->handle($request, $key, $fingerprint),
                'status' => 'pending_review',
            ]]);
        }

        return ApiAnswer::one(
            ['reference' => $outcome->kept['reference'], 'status' => $outcome->kept['status']],
            $outcome->status,
            [...self::PRIVATE, ...$outcome->headers()],
        );
    }
}
