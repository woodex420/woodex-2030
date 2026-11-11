<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Support\Money;
use Illuminate\Support\Str;

/**
 * What changed between two sent revisions (QUO-013): total, lines added / removed / changed, discount, tax,
 * validity and terms. Staff see it on the quote's version list.
 */
final class QuoteDiff
{
    private const int LIMIT = 10;

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<string>
     */
    public static function summarize(array $old, array $new, bool $lakh = true): array
    {
        $money = fn (int $minor): string => Money::ofMinor($minor)->format($lakh);
        $changes = [];

        $oldTotal = (int) ($old['totals']['total_minor'] ?? 0);
        $newTotal = (int) ($new['totals']['total_minor'] ?? 0);
        if ($oldTotal !== $newTotal) {
            $delta = $newTotal - $oldTotal;
            $changes[] = 'Total '.$money($oldTotal).' → '.$money($newTotal).' ('.($delta > 0 ? '+' : '−').$money(abs($delta)).')';
        }

        $oldLines = self::lines($old);
        $newLines = self::lines($new);

        foreach ($newLines as $key => $line) {
            if (! isset($oldLines[$key])) {
                $changes[] = 'Added: '.self::short($line['description']).' ('.$money($line['line_total_minor']).')';

                continue;
            }

            $before = $oldLines[$key];
            $parts = [];
            if ((float) $before['qty'] !== (float) $line['qty']) {
                $parts[] = 'qty '.self::qty($before['qty']).' → '.self::qty($line['qty']);
            }
            if ($before['rate_minor'] !== $line['rate_minor']) {
                $parts[] = 'rate '.$money($before['rate_minor']).' → '.$money($line['rate_minor']);
            }
            if ($before['is_optional'] !== $line['is_optional']) {
                $parts[] = $line['is_optional'] ? 'now optional' : 'now included';
            }
            if ($parts === [] && $before['line_total_minor'] !== $line['line_total_minor']) {
                $parts[] = 'amount '.$money($before['line_total_minor']).' → '.$money($line['line_total_minor']);
            }
            if ($parts !== []) {
                $changes[] = self::short($line['description']).': '.implode(', ', $parts);
            }
        }

        foreach ($oldLines as $key => $line) {
            if (! isset($newLines[$key])) {
                $changes[] = 'Removed: '.self::short($line['description']);
            }
        }

        foreach (['discount_minor' => 'Discount', 'tax_minor' => 'Tax', 'other_charges_minor' => 'Other charges'] as $field => $label) {
            $a = (int) ($old['totals'][$field] ?? 0);
            $b = (int) ($new['totals'][$field] ?? 0);
            if ($a !== $b) {
                $changes[] = "{$label} ".$money($a).' → '.$money($b);
            }
        }

        if (($old['valid_until'] ?? null) !== ($new['valid_until'] ?? null)) {
            $changes[] = 'Valid until '.($old['valid_until'] ?? '—').' → '.($new['valid_until'] ?? '—');
        }

        if (($old['terms'] ?? []) !== ($new['terms'] ?? []) || ($old['scope_html'] ?? null) !== ($new['scope_html'] ?? null)) {
            $changes[] = 'Scope or terms text changed';
        }

        if (count($changes) > self::LIMIT) {
            $more = count($changes) - (self::LIMIT - 1);
            $changes = [...array_slice($changes, 0, self::LIMIT - 1), "and {$more} more changes"];
        }

        return $changes === [] ? ['No changes in lines or totals'] : $changes;
    }

    /**
     * Lines keyed by section + description (+ a counter for repeated descriptions).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, array{description: string, qty: string, rate_minor: int, line_total_minor: int, is_optional: bool}>
     */
    private static function lines(array $snapshot): array
    {
        $lines = [];

        foreach ((array) ($snapshot['sections'] ?? []) as $section) {
            foreach ((array) ($section['items'] ?? []) as $item) {
                $base = Str::lower(trim((string) ($section['name'] ?? '')).'|'.trim((string) ($item['description'] ?? '')));
                $key = $base;
                for ($n = 2; isset($lines[$key]); $n++) {
                    $key = $base.'#'.$n;
                }

                $lines[$key] = [
                    'description' => (string) ($item['description'] ?? ''),
                    'qty' => (string) ($item['qty'] ?? '0'),
                    'rate_minor' => (int) ($item['rate_minor'] ?? 0),
                    'line_total_minor' => (int) ($item['line_total_minor'] ?? 0),
                    'is_optional' => (bool) ($item['is_optional'] ?? false),
                ];
            }
        }

        return $lines;
    }

    private static function short(string $text): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', trim($text)) ?? '', 60);
    }

    private static function qty(string $qty): string
    {
        return rtrim(rtrim(number_format((float) $qty, 3, '.', ''), '0'), '.');
    }
}
