<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Commerce\Services\PublishedCatalogue;
use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Activity;
use App\Domain\Sales\Models\CatalogItem;
use App\Http\Requests\Api\StoreQuoteRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The basket of a visitor of the shop, saved for the team to review: priced here from the catalogue, never from
 * what the page sent. It is not a quotation.
 *
 * A line is read in one of two ways (master list T-191; the rules are docs/woodex-os/29-API-V1-RULES.md):
 *
 *   by `slug`        a product this admin published. The published copy is read — never the draft — exactly as
 *                    the shop was handed it (App\Domain\Commerce\Services\PublishedCatalogue): its name, its
 *                    code, its price at this moment, and the option the visitor chose. A product priced on
 *                    request is taken without a figure: no price is made up, and none is zero.
 *   by `product_id`  the shop's own key, through the line of the rate book that names it.
 *
 * That the same request sent twice is saved once is not decided here any more: the request's key is held by
 * App\Support\Api\Idempotency, which calls this inside its transaction (master list T-063). The catalogue is read
 * without a lock: the lock that was here made two baskets with the same products wait for each other, and could
 * make two that named them in another order stop each other, for nothing — a price is read, not changed.
 */
final class CaptureStorefrontQuoteRequest
{
    /** The most one unit may cost, in paisa: with 100 of a line and 50 lines a total stays a whole number. */
    private const int RATE_AT_MOST = 10000000000;

    private const string NOT_OFFERED = 'This product is not offered at the moment. Please refresh your basket or contact the team.';

    public function __construct(private readonly PublishedCatalogue $catalogue) {}

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
        // In the order the basket was sent. What was validated comes back rule by rule, so that the lines named by
        // `product_id` stand before the lines named by `slug`, whatever their place in the basket was.
        ksort($data['items']);
        $data['items'] = array_map(static fn (array $line): array => isset($line['slug'])
            ? ['slug' => (string) $line['slug'], 'sku' => filled($line['sku'] ?? null) ? (string) $line['sku'] : null, 'qty' => (int) $line['qty']]
            : ['product_id' => $line['product_id'], 'qty' => (int) $line['qty']], $data['items']);

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
            foreach ($data['items'] as $index => $line) {
                $lines[] = isset($line['slug']) ? $this->publishedLine($line, $index) : $this->rateBookLine($line, $index);
            }
            // The estimate adds up the lines that have a figure. A line priced on request has none, and is said
            // to be left out wherever the estimate is shown.
            $total = array_sum(array_map(static fn (array $line): int => (int) ($line['line_total_minor'] ?? 0), $lines));

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

    /**
     * A line that names a product of the rate book by the shop's own key.
     *
     * @param  array{product_id: string, qty: int}  $line
     * @return array<string, mixed>
     */
    private function rateBookLine(array $line, int $index): array
    {
        $item = CatalogItem::query()->where('storefront_product_id', $line['product_id'])
            ->where('business_line', BusinessLine::Furniture->value)->where('is_active', true)
            ->first();
        if ($item === null || $item->rate_minor < 0 || $item->rate_minor > self::RATE_AT_MOST) {
            throw ValidationException::withMessages(["items.$index.product_id" => 'This product is unavailable for online requests. Please refresh your basket or contact the team.']);
        }

        return ['product_id' => $line['product_id'], 'catalog_item_id' => $item->id,
            'description' => $item->description, 'unit' => $item->unit, 'qty' => $line['qty'],
            'rate_minor' => $item->rate_minor, 'line_total_minor' => $item->rate_minor * $line['qty']];
    }

    /**
     * A line that names a product this admin published, by its address, and the option by its code.
     *
     * Refused, by the name of the line, when the shop could not have offered it: a product that is a draft, was
     * withdrawn or is archived answers like a product that is not there; one the editor marked unavailable cannot
     * be asked for; a product sold in options needs the option, and the option must be one of the published copy.
     *
     * @param  array{slug: string, sku: string|null, qty: int}  $line
     * @return array<string, mixed>
     */
    private function publishedLine(array $line, int $index): array
    {
        $refuse = static fn (string $why): never => throw ValidationException::withMessages(["items.$index.slug" => $why]);

        // The same switch as the addresses the shop reads the products at: what is not handed out cannot be asked for.
        $product = config('woodex.product_sync_enabled') ? $this->catalogue->one($line['slug']) : null;
        if ($product === null || $product['availability'] === 'unavailable') {
            $refuse(self::NOT_OFFERED);
        }

        $code = $product['sku'];
        $rate = $product['price_minor'];
        $option = '';

        if ($product['variants'] !== []) {
            if ($line['sku'] === null) {
                $refuse('This product is sold in options. Choose one on its page and add it to the basket again.');
            }
            $chosen = Arr::first($product['variants'], static fn (array $variant): bool => $variant['sku'] === $line['sku']);
            if ($chosen === null || $chosen['availability'] === 'unavailable') {
                $refuse('The option chosen is not offered any more. Choose one on the product\'s page and add it to the basket again.');
            }
            $code = $chosen['sku'];
            $rate = $chosen['price_minor'];
            $option = implode(' · ', array_filter([$chosen['colour'], $chosen['material'], $chosen['size']], filled(...)));
        } elseif ($line['sku'] !== null && $line['sku'] !== $code) {
            // A basket that still names an option, of a product that is sold in one form now.
            $refuse('The option chosen is not offered any more. Add the product to the basket again from its page.');
        }

        if ($rate !== null && ($rate < 0 || $rate > self::RATE_AT_MOST)) {
            $refuse(self::NOT_OFFERED);
        }

        return [
            'slug' => $product['slug'], 'sku' => $code, 'option' => $option, 'revision' => $product['revision'],
            'description' => $product['name'].($option === '' ? '' : ' — '.$option), 'unit' => 'each', 'qty' => $line['qty'],
            // No figure, where the published copy has none: the team gives the price when it answers.
            'rate_minor' => $rate, 'line_total_minor' => $rate === null ? null : $rate * $line['qty'],
        ];
    }
}
