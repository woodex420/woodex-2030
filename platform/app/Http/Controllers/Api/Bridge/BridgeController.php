<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bridge;

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\Quote;
use App\Domain\System\Models\IntegrationLog;
use App\Http\Controllers\Controller;
use App\Integrations\Mcp\McpAccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class BridgeController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'status.read');

        return $this->respond($request, 'status', [
            'service' => 'Woodex read-only bridge', 'version' => '1.0.0',
            'read_only' => true, 'abilities' => $request->attributes->get('mcp_token')->abilities,
        ]);
    }

    public function leads(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'leads.read');
        Gate::authorize('viewAny', Lead::class);
        $input = $this->pagination($request);
        /** @var User $user */
        $user = $request->user();
        $rows = Lead::query()->visibleTo($user)->with('stage:id,name')->where('id', '>', $input['after_id'])
            ->orderBy('id')->limit($input['limit'] + 1)->get(['id', 'ref', 'service', 'city', 'stage_id', 'created_at']);
        $more = $rows->count() > $input['limit'];
        $rows = $rows->take($input['limit']);

        return $this->respond($request, 'leads', [
            'items' => $rows->map(fn (Lead $lead): array => [
                'id' => $lead->id, 'reference' => $lead->ref, 'service' => $lead->service,
                'city' => $lead->city, 'stage' => $lead->stage?->name,
                'created_at' => $lead->created_at?->toIso8601String(),
            ])->values()->all(),
            'next_after_id' => $more ? $rows->last()?->id : null,
        ]);
    }

    public function quotes(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'quotes.read');
        Gate::authorize('viewAny', Quote::class);
        $input = $this->pagination($request);
        /** @var User $user */
        $user = $request->user();
        $rows = Quote::query()->visibleTo($user)->where('id', '>', $input['after_id'])->orderBy('id')
            ->limit($input['limit'] + 1)->get(['id', 'number', 'status', 'currency', 'total_minor']);
        $more = $rows->count() > $input['limit'];
        $rows = $rows->take($input['limit']);

        return $this->respond($request, 'quotes', [
            'items' => $rows->map(fn (Quote $quote): array => [
                'id' => $quote->id, 'number' => $quote->number, 'status' => $quote->status->value,
                'currency' => $quote->currency, 'total_minor' => (int) $quote->total_minor,
            ])->values()->all(),
            'next_after_id' => $more ? $rows->last()?->id : null,
        ]);
    }

    private function authorizeAbility(Request $request, string $ability): void
    {
        /** @var McpAccessToken $token */
        $token = $request->attributes->get('mcp_token');
        abort_unless(in_array($ability, $token->abilities, true), 403, 'This bridge key does not allow that tool.');
    }

    /** @return array{limit: int, after_id: int} */
    private function pagination(Request $request): array
    {
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:50'], 'after_id' => ['sometimes', 'integer', 'min:0']]);

        return ['limit' => (int) ($data['limit'] ?? 20), 'after_id' => (int) ($data['after_id'] ?? 0)];
    }

    /** @param array<string, mixed> $data */
    private function respond(Request $request, string $tool, array $data): JsonResponse
    {
        IntegrationLog::query()->create([
            'integration' => 'mcp', 'direction' => 'in', 'action' => $tool, 'ok' => true,
            'status_code' => 200, 'request' => ['key_id' => $request->attributes->get('mcp_token')->id],
            'created_at' => now(),
        ]);

        return response()->json($data);
    }
}
