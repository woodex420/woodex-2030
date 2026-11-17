@php $hasAdjustments = $t['discount_minor'] > 0 || $t['other_charges_minor'] > 0 || $t['tax_minor'] > 0 || $t['rounding_minor'] !== 0; @endphp
<div class="avoid" style="margin-top: 10px;">
    @if ($hasAdjustments)
        <table>
            <tr>
                <td style="width: 56%;"></td>
                <td style="width: 44%;">
                    <table class="totals">
                        <tr><td>Subtotal</td><td class="right">{{ $plain($t['subtotal_minor']) }}</td></tr>
                        @if ($t['discount_minor'] > 0)<tr><td>{{ $t['discount_label'] }}</td><td class="right">− {{ $plain($t['discount_minor']) }}</td></tr>@endif
                        @if ($t['other_charges_minor'] > 0)<tr><td>{{ $t['other_charges_label'] }}</td><td class="right">{{ $plain($t['other_charges_minor']) }}</td></tr>@endif
                        @if ($t['tax_minor'] > 0)<tr><td>Total tax ({{ rtrim(rtrim(number_format($t['tax_bp'] / 100, 2), '0'), '.') }}%)</td><td class="right">{{ $plain($t['tax_minor']) }}</td></tr>@endif
                        @if ($t['rounding_minor'] !== 0)<tr><td>Rounding</td><td class="right">{{ $plain($t['rounding_minor']) }}</td></tr>@endif
                    </table>
                </td>
            </tr>
        </table>
    @endif
    <table style="margin-top: 6px;">
        <tr>
            <td style="width: 56%; padding-right: 10px; vertical-align: top;">
                <div class="wordsbox">
                    <div class="boxlabel">Amount in words</div>
                    {{ $t['total_words'] }}
                </div>
            </td>
            <td style="width: 44%; vertical-align: top;">
                <table class="pill">
                    <tr>
                        <td class="pill-label">NET TOTAL (PKR)</td>
                        <td class="pill-amount">{{ $plain($t['total_minor']) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>
