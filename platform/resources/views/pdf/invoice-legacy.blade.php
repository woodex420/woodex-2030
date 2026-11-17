@php
    $design = $s['document_design'] ?? null;
    $b = $s['business'];
    $client = $s['client'];
    $bank = $s['bank'];
    $isCreditNote = $s['kind'] === 'credit_note';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $s['number'] }}</title>
<style>
    @page { margin: 132px 42px 70px 42px; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { font-size: 9.5px; color: #0c1628; line-height: 1.45; }
    header { position: fixed; top: -104px; left: 0; right: 0; height: 84px; border-bottom: 2px solid #0c1628; }
    footer { position: fixed; bottom: -46px; left: 0; right: 0; height: 24px; border-top: 1px solid #e3e1dc; font-size: 8px; color: #46586f; }
    .brand { font-size: 20px; letter-spacing: 3px; font-weight: bold; color: #0c1628; }
    .tagline { font-size: 7.5px; letter-spacing: 1.4px; color: #6b7280; text-transform: uppercase; margin-top: 3px; }
    .muted { color: #6b7280; }
    .right { text-align: right; }
    h1 { font-size: 17px; margin: 0 0 2px; color: #0c1628; letter-spacing: 1px; }
    h2 { font-size: 10.5px; margin: 16px 0 6px; color: #0c1628; text-transform: uppercase; letter-spacing: 1.2px; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { vertical-align: top; padding: 0; }
    /* Classic BOQ furniture (Slice R): title band, cream boxes, words box + balance pill. */
    .doctitle { font-size: 21px; letter-spacing: 4px; font-weight: bold; color: #0c1628; text-align: right; }
    .doctitle .gold { color: #b8956a; }
    .docmeta td { padding: 1px 0 1px 10px; vertical-align: top; }
    .goldrule { border-top: 1px solid #b8956a; margin: 10px 0 12px; }
    .box { background: #fcfbf9; border: 1px solid #e3e1dc; padding: 9px 11px; }
    .boxlabel { font-size: 7.5px; letter-spacing: 1.6px; color: #8a6a40; text-transform: uppercase; margin-bottom: 3px; }
    .wordsbox { border: 1px dashed #b8956a; padding: 8px 10px; }
    .pill td { background: #0c1628; color: #fff; padding: 9px 12px; }
    .pill .pill-label { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; }
    .pill .pill-amount { font-size: 13px; font-weight: bold; color: #e6cfa5; text-align: right; }
    .paylabel { color: #6b7280; width: 34%; padding: 1px 6px 1px 0; vertical-align: top; }
    .lines th { background: #0c1628; color: #fff; font-weight: bold; font-size: 8.5px; text-transform: uppercase; letter-spacing: .6px; padding: 6px; text-align: left; }
    .lines th.right { text-align: right; }
    .lines td { padding: 6px; border-bottom: 1px solid #e3e1dc; vertical-align: top; }
    .totals td { padding: 4px 6px; }
    .totals .grand td { background: #0c1628; color: #fff; font-size: 12px; font-weight: bold; padding: 8px 6px; }
    .words { margin-top: 6px; font-style: italic; }
    .terms li { margin-bottom: 2px; }
    .avoid { page-break-inside: avoid; }
    .sign td { padding-top: 34px; vertical-align: bottom; }
    .sign .line { border-top: 1px solid #0c1628; width: 82%; padding-top: 4px; }
    .stamp { display: inline-block; border: 2px solid; border-radius: 3px; padding: 2px 8px; font-weight: bold; letter-spacing: 1.5px; font-size: 9px; text-transform: uppercase; transform: rotate(-4deg); margin-left: 10px; }
    .stamp.paid { color: #15803d; } .stamp.due { color: #b42318; } .stamp.part { color: #a16207; }
    .stamp { color: #b0453a; font-weight: bold; font-size: 11px; letter-spacing: 2px; text-transform: uppercase; }
</style>
@include('pdf.partials.document-style')
@include('pdf.partials.preview-screen')
</head>
<body>
<header>
    <table class="meta">
        <tr>
            <td style="width: 52%;">
                @if (! empty($design['logo_data_uri']) && preg_match('/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/', $design['logo_data_uri']))
                    <img src="{{ $design['logo_data_uri'] }}" class="document-logo" alt="Brand logo">
                @endif
                <div class="brand">{{ strtoupper($design['brand_name'] ?? $b['name']) }}</div>
                <div class="tagline">{{ ($design['header_text'] ?? '') !== '' ? $design['header_text'] : $b['tagline'] }}</div>
            </td>
            <td class="right" style="width: 48%; line-height: 1.55;">
                <strong>{{ $isCreditNote ? 'Credit note' : 'Invoice' }} {{ $s['number'] }}</strong><br>
                <span class="muted">{{ $s['issue_date'] ?? '—' }} · Due {{ $s['due_date'] ?? '—' }}</span>
            </td>
        </tr>
    </table>
</header>

<footer>
    @if (! empty($design['footer_text']))
        <div class="document-footer">{{ $design['footer_text'] }}</div>
    @else
    <table><tr>
        <td style="padding-top: 6px; width: 68%;">{{ $b['phone'] }}&nbsp;&nbsp;|&nbsp;&nbsp;{{ $b['email'] }}&nbsp;&nbsp;|&nbsp;&nbsp;{{ $b['address'] }}</td>
        <td class="right" style="padding-top: 6px; padding-right: 88px; width: 32%;">Thank you for your business</td>
    </tr></table>
    @endif
</footer>

@if ($s['status'] === 'draft')<div class="stamp">DRAFT — not issued</div>@endif

@if ($isCreditNote)
    <div class="stamp">Credit note</div>
@endif

<table class="meta">
    <tr>
        <td style="width: 44%;"></td>
        <td style="width: 56%;">
            <div class="doctitle">{{ $isCreditNote ? 'CREDIT NOTE' : 'INVOICE' }}<span class="gold">.</span></div>
            <table class="docmeta" style="margin-top: 6px;">
                <tr><td class="muted right">{{ $isCreditNote ? 'Credit note no.' : 'Invoice no.' }}</td><td class="right" style="width: 34%;"><strong>{{ $s['number'] }}</strong></td></tr>
                <tr><td class="muted right">Date</td><td class="right">{{ $s['issue_date'] ?? '—' }}</td></tr>
                <tr><td class="muted right">Due date</td><td class="right">{{ $s['due_date'] ?? '—' }}</td></tr>
            </table>
            <div class="muted right" style="margin-top: 6px; line-height: 1.55;">
                {{ $b['address'] }}<br>
                {{ $b['phone'] }} · {{ $b['email'] }}@if (! empty($b['ntn']))<br>NTN {{ $b['ntn'] }}@endif
            </div>
        </td>
    </tr>
</table>

<div class="goldrule"></div>

<table class="meta">
    <tr>
        <td style="width: 49%;">
            <div class="box">
                <div class="boxlabel">Prepared for</div>
                <div style="font-size: 11px; font-weight: bold;">{{ ! empty($client['company']) ? $client['company'] : $client['name'] }}</div>
                @if (! empty($client['company']) && $client['name'] !== $client['company'])<div>Attn: {{ $client['name'] }}</div>@endif
                @if (! empty($client['address']))<div>{{ $client['address'] }}</div>@endif
                @if (! empty($client['city']))<div>{{ $client['city'] }}</div>@endif
                <div>{{ implode(' · ', array_filter([$client['phone'], $client['email'] ?? null])) }}</div>
            </div>
        </td>
        <td style="width: 2%;"></td>
        <td style="width: 49%;">
            <div class="box">
                <div class="boxlabel">Invoice details</div>
                @if (! empty($s['quote']['title']))<div><span class="muted">Project</span>&nbsp;&nbsp;{{ $s['quote']['title'] }}</div>@endif
                @if (! empty($s['project']))<div style="margin-top: 2px;"><span class="muted">Project</span>&nbsp;&nbsp;{{ $s['project']['code'] }} — {{ $s['project']['name'] }}</div>@endif
                @if (! empty($s['quote']['site']))<div style="margin-top: 2px;"><span class="muted">Site</span>&nbsp;&nbsp;{{ $s['quote']['site'] }}</div>@endif
                @if (! empty($s['quote']))<div style="margin-top: 2px;"><span class="muted">Quotation</span>&nbsp;&nbsp;{{ $s['quote']['number'] }} (total {{ $s['quote']['total'] }})</div>@endif
                @if (empty($s['quote']) && empty($s['project']))<div class="muted">—</div>@endif
            </div>
        </td>
    </tr>
</table>

@foreach (\App\Domain\Sales\Services\DocumentStyles::visibleBlocks('invoice', $design) as $block)
    @include('pdf.partials.invoice-blocks.'.$block)
@endforeach
</body>
</html>
