@php
    $design = $s['document_design'] ?? null;
    use App\Support\Money;
    $lakh = app(\App\Settings\SalesSettings::class)->lakh_grouping;
    $money = fn (int $minor) => Money::ofMinor($minor)->format($lakh);
    $plain = fn (int $minor) => Money::ofMinor($minor)->format($lakh, false, '');
    $qty = fn (string $q) => rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.');
    $date = fn (?string $d) => $d ? \Carbon\CarbonImmutable::parse($d)->format('j M Y') : '—';
    $t = $s['totals'];
    $client = $s['client'];
    $company = $s['company'];
@endphp
@php
    $layout = \App\Domain\Sales\Services\DocumentStyles::quoteLayout($s['quote_layout'] ?? null);
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Quotation {{ $s['label'] }}</title>
@include('pdf.partials.quotation-v21-style')
</head><body data-quotation-layout="{{ $layout }}">
@if (empty($preview))
<footer class="v21-footer">
@if (!empty($design['footer_text']))
<div>{{ $design['footer_text'] }}</div>
@else
<table><tr><td>{{ $company['phone'] }} | {{ $company['email'] }} | {{ $company['address'] }}</td><td class="right">Thank you for your business</td></tr></table>
@endif
<div class="footer-bar"></div>
</footer>
@endif
<header class="v21-header">
<table class="meta"><tr>
<td style="width:48%">
@if (!empty($design['logo_data_uri']) && preg_match('/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/', $design['logo_data_uri']))
<img src="{{ $design['logo_data_uri'] }}" class="document-logo" alt="Brand logo">
@endif
<div class="brand">{{ strtoupper($design['brand_name'] ?? $company['brand']) }}</div>
<div class="tagline">{{ $design['header_text'] ?? $company['tagline'] }}</div>
</td><td class="right" style="width:52%">
<div class="doctitle">QUOTATION<span class="gold">.</span></div>
<table class="docmeta">
<tr><td class="muted">Quotation no.</td><td><strong>{{ $s['label'] }}</strong></td></tr>
<tr><td class="muted">Date</td><td>{{ $date($s['issued_on']) }}</td></tr>
<tr><td class="muted">Valid until</td><td>{{ $date($s['valid_until']) }}</td></tr>
</table>
<div class="contact-line">{{ $company['address'] }}<br>{{ $company['phone'] }} · {{ $company['email'] }}@if ($company['ntn'])<br>NTN {{ $company['ntn'] }}@endif</div>
</td></tr></table>
<div class="header-accent"><div class="header-gold"></div></div>
</header>
@if ($draft)<div class="watermark">DRAFT</div>@endif
<table class="meta">
    <tr>
        <td style="width: 49%;">
            <div class="box">
                <div class="boxlabel">Prepared for</div>
                <div style="font-size: 11px; font-weight: bold;">{{ ! empty($client['company']) ? $client['company'] : $client['name'] }}</div>
                @if (! empty($client['company']) && $client['name'] !== $client['company'])<div>Attn: {{ $client['name'] }}</div>@endif
                @if (! empty($client['address']))<div>{{ $client['address'] }}</div>@endif
                <div>{{ implode(' · ', array_filter([$client['phone'], $client['email']])) }}</div>
            </div>
        </td>
        <td style="width: 2%;"></td>
        <td style="width: 49%;">
            <div class="box">
                <div class="boxlabel">Project details</div>
                <div><span class="muted">Project</span>&nbsp;&nbsp;{{ ($s['title'] ?? '') !== '' ? $s['title'] : '—' }}</div>
                @if (! empty($s['site']))<div style="margin-top: 2px;"><span class="muted">Site</span>&nbsp;&nbsp;{{ $s['site'] }}</div>@endif
                @if (! empty($s['type']))<div style="margin-top: 2px;"><span class="muted">Type</span>&nbsp;&nbsp;{{ $s['type'] }}</div>@endif
                @if (! empty($s['area_sft']))<div style="margin-top: 2px;"><span class="muted">Area</span>&nbsp;&nbsp;{{ number_format((int) $s['area_sft']) }} sft</div>@endif
            </div>
        </td>
    </tr>
</table>

@if (! empty($s['intro_line']))<p style="margin: 10px 0 2px;">{{ $s['intro_line'] }}</p>@endif


@foreach (\App\Domain\Sales\Services\DocumentStyles::visibleBlocks('quote', $design) as $block)
    @if ($block === 'boq' && $layout !== 'classic')
        @include('pdf.partials.quotation-v21-items')
    @else
        @include('pdf.partials.quote-blocks.'.$block)
    @endif
@endforeach
@if (!empty($preview))
<footer class="v21-footer">
@if (!empty($design['footer_text']))
<div>{{ $design['footer_text'] }}</div>
@else
<table><tr><td>{{ $company['phone'] }} | {{ $company['email'] }} | {{ $company['address'] }}</td><td class="right">Thank you for your business</td></tr></table>
@endif
<div class="footer-bar"></div>
</footer>
@endif
</body></html>
