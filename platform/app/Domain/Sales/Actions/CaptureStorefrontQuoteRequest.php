<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Activity;
use App\Domain\Sales\Models\CatalogItem;
use App\Http\Requests\Api\StoreQuoteRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CaptureStorefrontQuoteRequest
{
    /** @return array{reference: string, created: bool} */
    public function handle(StoreQuoteRequest $request): array
    {
        $data = $request->validated();
        // Stable validated input only; challenge tokens and client money/labels are not identity.
        unset($data['turnstile_token'], $data['form_started_at']);
        $data['items'] = array_map(static fn (array $line): array => [
            'product_id' => $line['product_id'], 'qty' => (int) $line['qty'],
        ], $data['items']);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $key = $data['request_key'];
        $existing = fn () => DB::table('storefront_quote_requests')->where('request_key', $key)->first();
        $reply = function (object $row) use ($hash): array {
            abort_unless(hash_equals($row->payload_hash, $hash), 409, 'This request key has already been used for different details.');

            return ['reference' => $row->reference, 'created' => false];
        };
        if ($row = $existing()) {
            return $reply($row);
        }

        try {
            return DB::transaction(function () use ($request, $data, $hash, $key): array {
                $lines = [];
                $total = 0;
                foreach ($data['items'] as $index => $line) {
                    $item = CatalogItem::query()->where('storefront_product_id', $line['product_id'])
                        ->where('business_line', BusinessLine::Furniture->value)->where('is_active', true)
                        ->lockForUpdate()->first();
                    if ($item === null || $item->rate_minor < 0 || $item->rate_minor > 10000000000) {
                        throw ValidationException::withMessages(["items.$index.product_id" => 'This product is unavailable for online requests. Please refresh your basket or contact the team.']);
                    }
                    $amount = $item->rate_minor * $line['qty'];
                    $total += $amount;
                    $lines[] = ['product_id' => $line['product_id'], 'catalog_item_id' => $item->id,
                        'description' => $item->description, 'unit' => $item->unit, 'qty' => $line['qty'],
                        'rate_minor' => $item->rate_minor, 'line_total_minor' => $amount];
                }
                $lead = app(CaptureLead::class)->handle($request->toLeadInput())->lead;
                $reference = 'QR-'.Str::uuid();
                DB::table('storefront_quote_requests')->insert([
                    'request_key' => $key, 'payload_hash' => $hash, 'reference' => $reference,
                    'lead_id' => $lead->id, 'status' => 'pending_review', 'lines' => json_encode($lines, JSON_THROW_ON_ERROR),
                    'estimated_total_minor' => $total, 'currency' => 'PKR', 'created_at' => now(), 'updated_at' => now(),
                ]);
                Activity::create(['lead_id' => $lead->id, 'contact_id' => $lead->contact_id,
                    'type' => ActivityType::System, 'body' => "Basket quote request $reference saved for review — not an issued quotation.",
                    'data' => ['quote_request_reference' => $reference, 'status' => 'pending_review'], 'occurred_at' => now()]);

                return ['reference' => $reference, 'created' => true];
            });
        } catch (QueryException $error) {
            // A concurrent winning insert is safe to replay after this transaction rolls back.
            if ($row = $existing()) {
                return $reply($row);
            }
            throw $error;
        }
    }
}
