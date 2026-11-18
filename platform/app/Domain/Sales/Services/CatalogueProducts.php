<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Services\ProductMerchandising;
use App\Support\NumberInput;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The catalogue as a quotation sees it (T-086): every product that is not archived, published in the shop or
 * not, with its code and the price it has now. A product with several options is offered once per option: each
 * option has a code of its own and may have a price of its own.
 *
 * What is read is the product's draft, which is what staff edit; the shop's published copy can be older.
 * Nothing here writes, and nothing here is kept: a quotation line takes its own copy (QuoteEditor).
 *
 * @phpstan-type Choice array{product_id: int, code: string, code_number: int, name: string, option: string, price_minor: int|null, was_minor: int|null, to_check: int, published: bool}
 */
final class CatalogueProducts
{
    /** How many products are read for one search before the choices are cut to the limit. */
    private const READ = 120;

    public function __construct(private readonly ProductMerchandising $merchandising) {}

    /**
     * Choices whose name or code holds the words typed, by name. An empty search gives the first of the catalogue.
     *
     * @return list<Choice>
     */
    public function search(string $term, int $limit = 30): array
    {
        $term = mb_substr(trim($term), 0, 120);
        $query = Product::query()->whereNull('archived_at');

        if ($term !== '') {
            // The database only narrows: a wildcard typed by hand may let more through, and the check below
            // decides. Codes sit inside the draft, in capitals for the real catalogue.
            $like = fn (string $text): string => '%'.str_replace(['\\', '%', '_'], '_', $text).'%';
            $query->where(function (Builder $found) use ($term, $like): void {
                $found->where('name', 'like', $like($term));
                foreach (array_unique([$term, mb_strtoupper($term), mb_strtolower($term)]) as $spelling) {
                    $found->orWhere('draft', 'like', $like($spelling));
                }
            });
        }

        $needle = mb_strtolower($term);
        $found = [];
        foreach ($query->orderBy('name')->orderBy('id')->limit(self::READ)->get() as $product) {
            $named = $needle === '' || str_contains(mb_strtolower((string) $product->name), $needle);
            foreach ($this->choices($product) as $choice) {
                if ($named || ($choice['code'] !== '' && str_contains(mb_strtolower($choice['code']), $needle))) {
                    $found[] = $choice;
                    if (count($found) === $limit) {
                        return $found;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * What one product offers: one choice per option, or a single one for a product without options.
     *
     * @return list<Choice>
     */
    public function choices(Product $product): array
    {
        $draft = (array) $product->draft;
        $base = [
            'product_id' => (int) $product->id,
            'code' => '',
            'code_number' => self::codeNumber(''),
            'name' => (string) $product->name,
            'option' => '',
            // Points of the import that nobody has looked at yet: a doubtful price is seen before it is used.
            'to_check' => ($draft['reviewed'] ?? false) ? 0 : count((array) $product->review_issues),
            'published' => $product->published_revision_id !== null,
        ];

        $options = array_values(array_filter((array) ($draft['variants'] ?? []), 'is_array'));
        if ($options === []) {
            return [[...$base, ...$this->price($draft)]];
        }

        return array_map(fn (array $option): array => [
            ...$base,
            'code' => $code = trim((string) ($option['sku'] ?? '')),
            'code_number' => self::codeNumber($code),
            'option' => implode(' · ', array_filter(array_map(fn (string $key): string => trim((string) ($option[$key] ?? '')), ['colour', 'material', 'size']))),
            // An option with a price of its own is sold at that price; otherwise the product's price holds.
            ...$this->price(filled($option['price'] ?? '') ? $option : $draft),
        ], $options);
    }

    /**
     * The choice a line is written from. Asked again on the server at that moment: what the screen showed a
     * minute ago is not trusted.
     *
     * @return Choice
     */
    public function choice(Product $product, string $code = ''): array
    {
        if ($product->archived_at !== null) {
            throw new InvalidArgumentException('That product is archived. Restore it in the catalogue to quote it.');
        }

        foreach ($this->choices($product) as $choice) {
            if ($choice['code'] === $code) {
                return $choice;
            }
        }

        throw new InvalidArgumentException($code === ''
            ? 'Choose one of the options of that product.'
            : "The catalogue no longer has the code {$code} for that product. Search again.");
    }

    /**
     * The choice a button of the screen stands for. The button says the product's number and the number of the
     * code; the code itself, which a person typed, is never printed into the button's script.
     *
     * @return Choice
     */
    public function choiceByNumber(Product $product, int $codeNumber): array
    {
        foreach ($this->choices($product) as $choice) {
            if ($choice['code_number'] === $codeNumber) {
                return $this->choice($product, $choice['code']);
            }
        }

        throw new InvalidArgumentException('The catalogue no longer has that option of the product. Search again.');
    }

    /**
     * A number that stands for a code in the one place where typed text may not be printed: an attribute the
     * browser runs as script (tests/Feature/Security/StoredScriptTest.php). It tells the options of one product
     * apart; it is not a secret and proves nothing.
     */
    public static function codeNumber(string $code): int
    {
        return crc32($code);
    }

    /**
     * The price in force now, in paisa: the reduced price inside its dates, otherwise the regular one. Null is
     * "price on request": no price, or a price of zero.
     *
     * @param  array<string, mixed>  $row  a product's draft or one of its options
     * @return array{price_minor: int|null, was_minor: int|null}
     */
    private function price(array $row): array
    {
        $now = $this->minor($this->merchandising->effectivePrice($row));
        $regular = $this->minor((string) ($row['price'] ?? ''));

        return [
            'price_minor' => $now,
            'was_minor' => $now !== null && $regular !== null && $regular > $now ? $regular : null,
        ];
    }

    private function minor(string $rupees): ?int
    {
        if (! preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $rupees)) {
            return null;
        }
        $minor = NumberInput::rupees($rupees);

        return $minor > 0 ? $minor : null;
    }
}
