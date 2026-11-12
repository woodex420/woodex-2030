<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit catalogue merchandising; no inferred sales ranking, stock or frontend cutover. */
final class ProductMerchandising
{
    public function validate(array $data): array
    {
        $data = array_replace(['sale_price' => '', 'sale_start' => '', 'sale_end' => '', 'is_hot' => false, 'is_featured' => false, 'featured_position' => 0], $data);
        $rules = ['sale_price' => ['nullable', 'string', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'], 'sale_start' => ['nullable', 'date_format:Y-m-d\TH:i'], 'sale_end' => ['nullable', 'date_format:Y-m-d\TH:i'], 'is_hot' => ['boolean'], 'is_featured' => ['boolean'], 'featured_position' => ['integer', 'min:0', 'max:99999']];
        Validator::make($data, $rules)->validate();
        $data['is_hot'] = (bool) $data['is_hot'];
        $data['is_featured'] = (bool) $data['is_featured'];
        $data['featured_position'] = (int) $data['featured_position'];
        $this->checkWindow($data, '');
        foreach ($data['variants'] ?? [] as $i => $variant) {
            Validator::make($variant, array_intersect_key($rules, array_flip(['sale_price', 'sale_start', 'sale_end'])))->validate();
            $this->checkWindow($variant, 'variants.'.$i.'.');
        }

        return $data;
    }

    private function cents(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function checkWindow(array $row, string $prefix): void
    {
        if (filled($row['sale_price'] ?? '')) {
            if (! filled($row['price'] ?? '') || ! preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', (string) $row['price']) || $this->cents($row['sale_price']) >= $this->cents($row['price'])) {
                throw ValidationException::withMessages([$prefix.'sale_price' => 'Sale price must be lower than an explicit regular price.']);
            }
        } elseif (filled($row['sale_start'] ?? '') || filled($row['sale_end'] ?? '')) {
            throw ValidationException::withMessages([$prefix.'sale_price' => 'Set a sale price before scheduling a sale.']);
        }
        if (filled($row['sale_start'] ?? '') && filled($row['sale_end'] ?? '') && $row['sale_start'] >= $row['sale_end']) {
            throw ValidationException::withMessages([$prefix.'sale_end' => 'Sale end must be after sale start (UTC).']);
        }
    }

    public function effectivePrice(array $row, ?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now('UTC');
        if (! filled($row['sale_price'] ?? '')) {
            return (string) ($row['price'] ?? '');
        }
        if (filled($row['sale_start'] ?? '') && $at->lt(CarbonImmutable::parse($row['sale_start'], 'UTC'))) {
            return (string) $row['price'];
        }
        if (filled($row['sale_end'] ?? '') && $at->gte(CarbonImmutable::parse($row['sale_end'], 'UTC'))) {
            return (string) $row['price'];
        }

        return (string) $row['sale_price'];
    }
}
