<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

/**
 * Validation for POST /api/v1/quote-requests. The key that makes a second sending harmless is not a field of the
 * form any more: it is the Idempotency-Key header, as for every create under /api/v1 (master list T-063).
 */
final class StoreQuoteRequest extends StorePublicLeadRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'consent' => ['required', 'accepted'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'string', 'max:100', 'distinct:strict'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:100'],
            // No free-text variant is silently accepted as a catalogue selection.
            'items.*.material' => ['prohibited'],
            'items.*.variant_id' => ['prohibited'],
        ]);
    }
}
