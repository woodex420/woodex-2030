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
use App\Support\BridgedVisitor;
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
            if (! $this->passesTheRobotCheck($request, $turnstile)) {
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

    /**
     * Who vouches for a basket: the robot check, or the shop's own server.
     *
     * A basket from anyone else needs a token that the check accepts, and is refused where the check is not set
     * up. A basket the shop's server carried here (it holds the bridge key) is not asked for a token. Where the
     * visitor's page made one all the same, because the shop was built with the check's public key, the token
     * is checked, for the visitor's own address: a page that shows the box must not show a box nobody reads
     * (decision DL-050). Only where the check is set up here; and when the check's own service gives no answer,
     * a basket the shop's server vouches for is taken.
     */
    private function passesTheRobotCheck(StoreQuoteRequest $request, TurnstileVerifier $turnstile): bool
    {
        $token = $request->input('turnstile_token');

        if (! $request->hasValidBridgeKey()) {
            return $turnstile->verify($token, $request->ip(), failClosed: true);
        }

        return blank($token) || ! $turnstile->enabled() || $turnstile->verify($token, BridgedVisitor::address($request));
    }
}
