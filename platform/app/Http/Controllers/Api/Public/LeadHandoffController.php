<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Domain\Crm\Actions\ManualWhatsAppHandoff;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LeadHandoffController extends Controller
{
    public function store(Request $request, ManualWhatsAppHandoff $handoff): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/\\A[a-f0-9]{64}\\z/']]);

        return response()->json($handoff->redeem($data['token']))
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
