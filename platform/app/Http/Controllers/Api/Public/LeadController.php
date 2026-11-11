<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Domain\Crm\Actions\CaptureLead;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePublicLeadRequest;
use App\Integrations\Turnstile\TurnstileVerifier;
use App\Settings\BusinessSettings;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;

/**
 * Stateless public endpoint used by website forms, the estimator, booking and the live-site bridge.
 * Cache-friendly by design: no session, no CSRF (D-13).
 */
final class LeadController extends Controller
{
    public function store(
        StorePublicLeadRequest $request,
        CaptureLead $captureLead,
        TurnstileVerifier $turnstile,
        BusinessSettings $business,
    ): JsonResponse {
        if ($request->isSpam()) {
            // Look successful to bots; store nothing.
            return response()->json(['ok' => true, 'reference' => null], 202);
        }

        if (! $request->hasValidBridgeKey() && ! $turnstile->verify($request->input('turnstile_token'), $request->ip())) {
            return response()->json([
                'ok' => false,
                'message' => 'The spam check failed. Please refresh the page and try again.',
            ], 422);
        }

        $result = $captureLead->handle($request->toLeadInput());

        return response()->json([
            'ok' => true,
            'reference' => $result->lead->ref,
            'message' => 'Thank you! Our team will contact you shortly.',
            'whatsapp_url' => Phone::whatsappUrl(
                $business->whatsapp,
                "Hello Woodex, my enquiry reference is {$result->lead->ref}.",
            ),
        ], $result->leadCreated ? 201 : 200);
    }
}
