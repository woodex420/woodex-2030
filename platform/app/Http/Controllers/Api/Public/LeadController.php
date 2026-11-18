<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Actions\ManualWhatsAppHandoff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePublicLeadRequest;
use App\Integrations\Turnstile\TurnstileVerifier;
use App\Settings\BusinessSettings;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
        ManualWhatsAppHandoff $handoff,
    ): JsonResponse {
        if ($request->isSpam()) {
            // Look successful to bots; store nothing.
            return response()->json(['ok' => true, 'reference' => null], 202);
        }

        if (! $request->hasValidBridgeKey() && ! $turnstile->verify($request->input('turnstile_token'), $request->ip(), failClosed: $request->routeIs('api.v1.leads.store'))) {
            return response()->json([
                'ok' => false,
                'message' => 'The spam check failed. Please refresh the page and try again.',
            ], 422);
        }

        [$result, $capability] = DB::transaction(function () use ($request, $captureLead, $handoff): array {
            $result = $captureLead->handle($request->toLeadInput());
            $capability = $request->routeIs('api.v1.leads.store') && $request->boolean('consent')
                && $request->input('preferred_channel') === 'whatsapp'
                ? $handoff->issue($result->lead) : null;

            return [$result, $capability];
        });

        return response()->json([
            'ok' => true,
            'reference' => $result->lead->ref,
            'message' => 'Thank you! Our team will contact you shortly.',
            ...($request->routeIs('api.v1.leads.store') ? ['handoff' => $capability] : []),
            'whatsapp_url' => $request->routeIs('api.v1.leads.store') ? null : Phone::whatsappUrl(
                $business->whatsapp,
                "Hello Woodex, my enquiry reference is {$result->lead->ref}.",
            ),
        ], $result->leadCreated ? 201 : 200)->header('Cache-Control', 'no-store, private');
    }
}
