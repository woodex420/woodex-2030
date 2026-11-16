@php
    $b = $business;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Receipt {{ $payment->receipt_number }}</title>
<style>
    @page { margin: 132px 42px 70px 42px; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { font-size: 10px; color: #0c1628; line-height: 1.5; }
    header { position: fixed; top: -104px; left: 0; right: 0; height: 84px; border-bottom: 2px solid #0c1628; }
    footer { position: fixed; bottom: -46px; left: 0; right: 0; height: 24px; border-top: 1px solid #e3e1dc; font-size: 8px; color: #6b7280; }
    .brand { font-size: 20px; letter-spacing: 3px; font-weight: bold; color: #0c1628; }
    .tagline { font-size: 7.5px; letter-spacing: 1.4px; color: #6b7280; text-transform: uppercase; margin-top: 3px; }
    .muted { color: #6b7280; }
    .right { text-align: right; }
    h1 { font-size: 18px; margin: 0 0 2px; color: #0c1628; letter-spacing: 1px; }
    h2 { font-size: 10.5px; margin: 16px 0 6px; color: #0c1628; text-transform: uppercase; letter-spacing: 1.2px; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { vertical-align: top; padding: 0; }
    .box { background: #f2f5f9; padding: 10px 12px; }
    .lines th { background: #0c1628; color: #fff; font-weight: bold; font-size: 8.5px; text-transform: uppercase; letter-spacing: .6px; padding: 6px; text-align: left; }
    .lines th.right { text-align: right; }
    .lines td { padding: 6px; border-bottom: 1px solid #e3e1dc; }
    .grand td { background: #0c1628; color: #fff; font-size: 12px; font-weight: bold; padding: 8px 6px; }
    .words { margin-top: 8px; font-style: italic; }
    .sign td { width: 50%; padding-top: 40px; vertical-align: bottom; }
    .sign .line { border-top: 1px solid #0c1628; width: 78%; padding-top: 4px; }
</style>
</head>
<body>
<header>
    <table class="meta">
        <tr>
            <td style="width: 52%;">
                <div class="brand">{{ strtoupper($b['name']) }}</div>
                <div class="tagline">{{ $b['tagline'] }}</div>
            </td>
            <td class="right muted" style="width: 48%; line-height: 1.55;">
                {{ $b['address'] }}<br>
                {{ $b['phone'] }} · {{ $b['email'] }}
                @if (! empty($b['ntn']))<br>NTN {{ $b['ntn'] }}@endif
            </td>
        </tr>
    </table>
</header>

<footer>
    {{ $b['legal_name'] }} · {{ $b['address'] }}, {{ $b['city'] }} · {{ $b['phone'] }}
</footer>

<h1>Receipt {{ $payment->receipt_number }}</h1>
<div class="muted">Received on {{ $payment->received_on?->format('j M Y') }} · {{ $payment->method->label() }}</div>

<table class="meta" style="margin-top: 14px;">
    <tr>
        <td style="width: 48%;">
            <div class="box">
                <strong>Received from</strong><br>
                {{ $payment->contact->name }}<br>
                @if ($payment->contact->address){{ $payment->contact->address }}<br>@endif
                {{ $payment->contact->phone }}
            </div>
        </td>
        <td style="width: 4%;"></td>
        <td style="width: 48%;">
            <table class="meta">
                <tr><td class="muted" style="width: 40%;">Amount</td><td><strong>{{ $received }}</strong></td></tr>
                <tr><td class="muted">Method</td><td>{{ $payment->method->label() }}</td></tr>
                @if ($payment->reference)
                    <tr><td class="muted">Reference</td><td>{{ $payment->reference }}</td></tr>
                @endif
                @if ($payment->bankAccount)
                    <tr><td class="muted">Received in</td><td>{{ $payment->bankAccount->title }}, {{ $payment->bankAccount->bank_name }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

@if ($allocations !== [])
    <h2>Against</h2>
    <table class="lines">
        <thead>
            <tr>
                <th>Invoice</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($allocations as $allocation)
                <tr>
                    <td>{{ $allocation['invoice'] ?? '—' }}</td>
                    <td class="right">{{ $allocation['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<table class="lines" style="margin-top: 12px; width: 46%; margin-left: 54%;">
    <tr class="grand"><td>Total received</td><td class="right">{{ $received }}</td></tr>
</table>
<div class="words">{{ $received_in_words }}</div>

@if ($unallocated !== $received)
    <p class="muted">{{ $unallocated }} of this payment is left as credit on the account.</p>
@endif

<table class="sign" style="margin-top: 40px;">
    <tr>
        <td><div class="line">Received by</div></td>
        <td><div class="line">For {{ strtoupper($b['name']) }}</div></td>
    </tr>
</table>

@if ($payment->notes)
    <p class="muted">{{ $payment->notes }}</p>
@endif
</body>
</html>
