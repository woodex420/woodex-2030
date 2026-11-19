<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Actions\ManualWhatsAppHandoff;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ApiV1;
use App\Http\Requests\Api\StorePublicLeadRequest;
use App\Integrations\Turnstile\TurnstileVerifier;
use App\Support\Api\ApiAnswer;
use App\Support\Api\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/leads: an enquiry from the shop (the rules: docs/woodex-os/29-API-V1-RULES.md).
 *
 * 201 a new enquiry · 200 the visitor already has an open one, and this was added to it · 202 taken for a robot's:
 * it looks accepted and nothing is kept. Sent twice with one Idempotency-Key, it is written once and answered the
 * same, hand-off token included.
 */
final class LeadController extends Controller
{
    /** Never kept by anything in between: the answer carries a token that is good for one visitor. */
    private const array PRIVATE = ['Cache-Control' => 'no-store, private'];

    public function store(
        StorePublicLeadRequest $request,
        CaptureLead $capture,
        TurnstileVerifier $turnstile,
        ManualWhatsAppHandoff $handoff,
        Idempotency $idempotency,
    ): JsonResponse {
        if ($request->isSpam()) {
            return ApiAnswer::one(['reference' => null, 'handoff' => null], 202, self::PRIVATE);
        }

        $key = (string) $request->header(ApiV1::KEY_HEADER);
        // What the visitor sent, without what changes from one sending to the next.
        $fingerprint = Idempotency::fingerprint($request->safe()->except(['turnstile_token', 'form_started_at']));

        $outcome = $idempotency->seen('leads', $key, $fingerprint);

        if ($outcome === null) {
            // Only a request that will write is checked against robots: one that was answered before passed then,
            // and the token of that check cannot be used a second time.
            if (! $request->hasValidBridgeKey() && ! $turnstile->verify($request->input('turnstile_token'), $request->ip(), failClosed: true)) {
                throw ValidationException::withMessages(['turnstile_token' => 'The spam check failed. Please refresh the page and try again.']);
            }

            $outcome = $idempotency->once('leads', $key, $fingerprint, function () use ($request, $capture, $handoff, $key): array {
                $result = $capture->handle($request->toLeadInput());
                $capability = $request->boolean('consent') && $request->input('preferred_channel') === 'whatsapp'
                    ? $handoff->issue($result->lead, $key) : null;

                // Kept for a second sending: which enquiry, and until when its hand-off is good. Never the token.
                return [$result->leadCreated ? 201 : 200, [
                    'reference' => $result->lead->ref,
                    'handoff' => $capability === null ? null : ['lead' => $result->lead->id, 'expires_at' => ApiAnswer::time($capability['expires_at'])],
                ]];
            });
        }

        $kept = $outcome->kept;

        return ApiAnswer::one([
            'reference' => $kept['reference'],
            'handoff' => $kept['handoff'] === null ? null : [
                'token' => $handoff->tokenFor((int) $kept['handoff']['lead'], $key),
                'expires_at' => $kept['handoff']['expires_at'],
            ],
        ], $outcome->status, [...self::PRIVATE, ...$outcome->headers()]);
    }
}
