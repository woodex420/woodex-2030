<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Validator;

/**
 * Validation for POST /api/v1/quote-requests. The key that makes a second sending harmless is not a field of the
 * form any more: it is the Idempotency-Key header, as for every create under /api/v1 (master list T-063).
 *
 * A line names its product in one of two ways, and never in both (master list T-191):
 *
 *   slug         the address of a product this admin published. It is what the shop knows such a product by,
 *                and nothing has to be mapped: the published copy is read, its name, its code and its price.
 *                `sku` names the option, for a product sold in options.
 *   product_id   the shop's own key of a product it keeps itself. It needs a line of the rate book that names
 *                this key (catalog_items.storefront_product_id), written by a person: no key is ever guessed.
 */
final class StoreQuoteRequest extends StorePublicLeadRequest
{
    /** What a line is told when it names its product twice, or not at all. */
    private const string ONE_WAY = 'Name the product once: by its address (slug), or by the shop\'s own key (product_id).';

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'consent' => ['required', 'accepted'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required_without:items.*.slug', 'prohibits:items.*.slug,items.*.sku', 'string', 'max:100', 'distinct:strict'],
            'items.*.slug' => ['required_without:items.*.product_id', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'items.*.sku' => ['nullable', 'string', 'max:100'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:100'],
            // No free-text variant is silently accepted as a catalogue selection.
            'items.*.material' => ['prohibited'],
            'items.*.variant_id' => ['prohibited'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.product_id.required_without' => self::ONE_WAY,
            'items.*.slug.required_without' => self::ONE_WAY,
            'items.*.product_id.prohibits' => self::ONE_WAY,
            'items.*.slug.regex' => 'This is not the address of a product.',
        ];
    }

    /**
     * The same product in the same option stands on one line. Two options of one product are two lines.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $seen = [];

            foreach ((array) $this->input('items', []) as $index => $line) {
                if (! is_array($line) || ! is_string($line['slug'] ?? null)) {
                    continue;
                }
                $option = is_string($line['sku'] ?? null) ? $line['sku'] : '';
                if (isset($seen[$line['slug']][$option])) {
                    $validator->errors()->add("items.$index.slug", 'This product stands in the basket twice. Put the two lines together.');
                }
                $seen[$line['slug']][$option] = true;
            }
        }];
    }
}
