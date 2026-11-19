<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Activity;
use App\Domain\Sales\Models\CatalogItem;
use App\Http\Requests\Api\StoreQuoteRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The basket of a visitor of the shop, saved for the team to review: priced here from the catalogue, never from
 * what the page sent. It is not a quotation.
 *
 * That the same request sent twice is saved once is not decided here any more: the request's key is held by
 * App\Support\Api\Idempotency, which calls this inside its transaction (master list T-063). The catalogue is read
 * without a lock: the lock that was here made two baskets with the same products wait for each other, and could
 * make two that named them in another order stop each other, for nothing — a price is read, not changed.
 */
final class CaptureStorefrontQuoteRequest
{
    /**
     * What was asked, for telling one request from another: the fields that were accepted, without the token of the
     * robot check and the moment the form was opened, and of each line only what is read here.
     *
     * @return array<string, mixed>
     */
    public function whatWasAsked(StoreQuoteRequest $request): array
    {
        $data = $request->validated();
        // Stable validated input only; challenge tokens and client money/labels are not identity.
        unset($data['turnstile_token'], $data['form_started_at']);
        $data['items'] = array_map(static fn (array $line): array => [
            'product_id' => $line['product_id'], 'qty' => (int) $line['qty'],
        ], $data['items']);

        return $data;
    }

    /**
     * Price and save. Answers the reference of the request.
     *
     * @param  string  $requestKey  the Idempotency-Key of the request, a UUID
     * @param  string  $fingerprint  the hash of what was asked
     */
    public function handle(StoreQuoteRequest $request, string $requestKey, string $fingerprint): string
    {
        $data = $this->whatWasAsked($request);

        return DB::transaction(function () use ($request, $data, $requestKey, $fingerprint): string {
            $lines = [];
            $total = 0;
            foreach ($data['items'] as $index => $line) {
                $item = CatalogItem::query()->where('storefront_product_id', $line['product_id'])
                    ->where('business_line', BusinessLine::Furniture->value)->where('is_active', true)
                    ->first();
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
                'request_key' => Str::lower($requestKey), 'payload_hash' => $fingerprint, 'reference' => $reference,
                'lead_id' => $lead->id, 'status' => 'pending_review', 'lines' => json_encode($lines, JSON_THROW_ON_ERROR),
                'estimated_total_minor' => $total, 'currency' => 'PKR', 'created_at' => now(), 'updated_at' => now(),
            ]);
            Activity::create(['lead_id' => $lead->id, 'contact_id' => $lead->contact_id,
                'type' => ActivityType::System, 'body' => "Basket quote request $reference saved for review — not an issued quotation.",
                'data' => ['quote_request_reference' => $reference, 'status' => 'pending_review'], 'occurred_at' => now()]);

            return $reference;
        });
    }
}
