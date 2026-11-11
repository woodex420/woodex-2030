<h2>Detailed bill of quantities</h2>
@foreach ($s['sections'] as $si => $section)
    <div class="secthead">{{ str_pad((string) ($si + 1), 2, '0', STR_PAD_LEFT) }} · {{ $section['name'] }}@if ($section['note']) <span class="note">— {{ $section['note'] }}</span>@endif</div>
    <table class="lines">
        <thead>
            <tr>
                <th style="width: 6%;">SR</th>
                <th style="width: 44%;">Particulars</th>
                <th class="right" style="width: 11%;">Qty / Area</th>
                <th style="width: 9%;">Unit</th>
                <th class="right" style="width: 14%;">Rate (Rs)</th>
                <th class="right" style="width: 16%;">Amount (Rs)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($section['items'] as $ii => $item)
                <tr @class(['optional' => $item['is_optional']])>
                    <td>{{ ($si + 1).'.'.($ii + 1) }}</td>
                    <td>@if (($item['image'] ?? null) !== null)<img src="{{ $item['image'] }}" alt="" style="float:left;width:52px;height:52px;object-fit:cover;margin:0 8px 2px 0;border-radius:4px;">@endif{!! nl2br(e($item['description'])) !!}@if ($item['is_optional']) (optional, not in total)@endif
                        @if ($item['discount_minor'] > 0)<br><span class="muted">Discount {{ $money($item['discount_minor']) }}</span>@endif</td>
                    <td class="right">{{ (float) $item['qty'] !== 0.0 ? $qty($item['qty']) : '–' }}</td>
                    <td>{{ $item['unit_label'] }}</td>
                    <td class="right">{{ $item['rate_minor'] !== 0 ? $plain($item['rate_minor']) : '–' }}</td>
                    <td class="right">{{ $item['line_total_minor'] !== 0 ? $plain($item['line_total_minor']) : '–' }}</td>
                </tr>
            @endforeach
            <tr class="subtotal">
                <td></td>
                <td colspan="4">Total · {{ $section['name'] }}</td>
                <td class="right">{{ $section['subtotal_minor'] !== 0 ? $plain($section['subtotal_minor']) : '–' }}</td>
            </tr>
        </tbody>
    </table>
@endforeach
