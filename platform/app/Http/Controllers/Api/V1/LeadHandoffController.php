<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crm\Actions\ManualWhatsAppHandoff;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiAnswer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/leads/whatsapp-handoff: the visitor chose WhatsApp; the enquiry's timeline says so, and the answer
 * is the link that opens the chat. Nothing is sent to anybody.
 *
 * The token is the key of this request: sent twice, it is written down once and answered the same, until it
 * expires. So this create needs no Idempotency-Key.
 */
final class LeadHandoffController extends Controller
{
    public function store(Request $request, ManualWhatsAppHandoff $handoff): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/\\A[a-f0-9]{64}\\z/']]);
        $redeemed = $handoff->redeem($data['token']);

        return ApiAnswer::one(
            ['delivery' => $redeemed['delivery'], 'whatsapp_url' => $redeemed['whatsapp_url']],
            200,
            ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer'],
        );
    }
}
