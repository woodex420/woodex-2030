@php
    // Summary of cost (Slice R): one row per section. Area/per-sft only when every priced line in the
    // section is sft-measured — a mixed section (sft + lumpsum) honestly shows a dash, like v2.1 does.
    use App\Domain\Sales\Enums\Unit;
    $rows = [];
    foreach ($s['sections'] as $i => $section) {
        $priced = array_filter($section['items'], static fn (array $item): bool => ! $item['is_optional']);
        $allSft = $priced !== [] && array_reduce($priced, static fn (bool $carry, array $item): bool => $carry && Unit::normalize((string) $item['unit']) === Unit::Sft, true);
        $area = $allSft ? array_sum(array_map(static fn (array $item): float => (float) $item['qty'], $priced)) : 0.0;
        $rows[] = [
            'sr' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
            'name' => strtoupper($section['name']),
            'area' => $area > 0 ? number_format(round($area)) : '–',
            'per_sft' => $area > 0 && $section['subtotal_minor'] !== 0 ? $plain((int) (round($section['subtotal_minor'] / $area / 100) * 100)) : '–',
            'amount' => $section['subtotal_minor'] !== 0 ? $plain($section['subtotal_minor']) : '–',
        ];
    }
@endphp
@if ($rows !== [])
<h2>Summary of cost</h2>
<table class="lines">
    <thead>
        <tr>
            <th style="width: 6%;">SR</th>
            <th style="width: 44%;">Particulars</th>
            <th class="right" style="width: 16%;">Total area (Sft)</th>
            <th class="right" style="width: 16%;">Per Sft (Rs)</th>
            <th class="right" style="width: 18%;">Amount (Rs)</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $row['sr'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td class="right">{{ $row['area'] }}</td>
                <td class="right">{{ $row['per_sft'] }}</td>
                <td class="right">{{ $row['amount'] }}</td>
            </tr>
        @endforeach
        <tr class="subtotal">
            <td></td>
            <td style="text-transform: uppercase;">Total</td>
            <td></td>
            <td></td>
            <td class="right">{{ $plain($t['subtotal_minor']) }}</td>
        </tr>
    </tbody>
</table>
@endif
