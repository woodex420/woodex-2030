<div class="avoid" style="margin-top: 10px;">
    <table>
        <tr>
            <td style="width: 56%;"></td>
            <td style="width: 44%;">
                <table class="totals">
                    <tr><td>Subtotal</td><td class="right">{{ $s['subtotal'] }}</td></tr>
                    @if ((int) filter_var($s['discount'], FILTER_SANITIZE_NUMBER_INT) !== 0)
                        <tr><td>Discount</td><td class="right">− {{ $s['discount'] }}</td></tr>
                    @endif
                    @if ((int) filter_var($s['tax'], FILTER_SANITIZE_NUMBER_INT) !== 0)
                        <tr><td>Total tax @if (! empty($s['tax_bp']))({{ $s['tax_bp'] / 100 }}%)@endif</td><td class="right">{{ $s['tax'] }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
    <table style="margin-top: 6px;">
        <tr>
            <td style="width: 56%; padding-right: 10px; vertical-align: top;">
                <div class="wordsbox">
                    <div class="boxlabel">Amount in words</div>
                    {{ $s['total_in_words'] }}
                </div>
            </td>
            <td style="width: 44%; vertical-align: top;">
                <table class="pill">
                    <tr>
                        <td class="pill-label">Invoice total (Rs)</td>
                        <td class="pill-amount">{{ $s['total'] }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>

<div class="avoid" style="margin-top: 14px;">
    <h2 style="display: inline;">Payments received</h2>@if (! empty($s['pay_stamp']))<span class="stamp {{ $s['pay_stamp']['tone'] }}">{{ $s['pay_stamp']['label'] }}</span>@endif
    @if (! empty($s['payments']))
        <table class="lines" style="margin-top: 6px;">
            <thead>
                <tr>
                    <th style="width: 18%;">Receipt</th>
                    <th style="width: 18%;">Date</th>
                    <th style="width: 20%;">Method</th>
                    <th style="width: 24%;">Reference</th>
                    <th class="right" style="width: 20%;">Amount (Rs)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($s['payments'] as $payment)
                    <tr>
                        <td>{{ $payment['receipt'] }}</td>
                        <td>{{ $payment['date'] }}</td>
                        <td>{{ $payment['method'] }}</td>
                        <td>{{ $payment['reference'] }}</td>
                        <td class="right">{{ $payment['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p style="margin: 6px 0 0;">No payments received yet.</p>
    @endif
    <table style="margin-top: 8px;">
        <tr>
            <td style="width: 56%;"></td>
            <td style="width: 44%;">
                <table class="totals">
                    <tr><td>Received</td><td class="right">{{ $s['paid'] }}</td></tr>
                </table>
                <table class="pill" style="margin-top: 6px;">
                    <tr>
                        <td class="pill-label">Balance due (Rs)</td>
                        <td class="pill-amount">{{ $s['balance'] }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>
