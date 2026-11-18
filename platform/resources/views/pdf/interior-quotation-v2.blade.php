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
@include('pdf.partials.interior-v2.style')
</head><body data-quotation-layout="{{ $layout }}" data-interior-revision="2">
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
<td style="width:48%"><table class="brand-grid"><tr>
@if (!empty($design['logo_data_uri']) && preg_match('/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/', $design['logo_data_uri']))
<td class="mark-cell"><img src="{{ $design['logo_data_uri'] }}" class="document-logo" alt="Brand logo"></td>
@endif
<td class="brand-identity"><div class="brand">@if (strcasecmp(trim($design['brand_name'] ?? $company['brand']), 'Woodex Interior') === 0)WOODEX <span class="brand-suffix">INTERIOR</span>@else{{ strtoupper($design['brand_name'] ?? $company['brand']) }}@endif</div>
<div class="tagline">{{ $design['header_text'] ?? $company['tagline'] }}</div></td></tr></table>
</td><td class="right" style="width:52%">
<div class="doctitle">QUOTATION <span class="muted">/</span> BOQ<span class="gold">.</span></div>
<table class="docmeta">
<tr><td class="muted">Quotation no.</td><td><strong>{{ $s['label'] }}</strong></td></tr>
<tr><td class="muted">Date</td><td>{{ $date($s['issued_on']) }}</td></tr>
<tr><td class="muted">Valid until</td><td>{{ $date($s['valid_until']) }}</td></tr>
</table>

</td></tr></table>
<div class="header-accent"><div class="header-gold"></div></div>
</header>
@if ($draft)<div class="watermark">DRAFT</div>@endif
<table class="client-cards"><tr>
<td class="card" style="width:49%"><div class="boxlabel">Prepared for</div><strong>{{ !empty($client['company']) ? $client['company'] : $client['name'] }}</strong>
@if (!empty($client['company']) && $client['name'] !== $client['company'])<div>Attn: {{ $client['name'] }}</div>@endif
@if (!empty($client['address']))<div>{{ $client['address'] }}</div>@endif
<div>{{ implode(' · ', array_filter([$client['phone'], $client['email']])) }}</div></td>
<td style="width:2%"></td>
<td class="card" style="width:49%"><div class="boxlabel">Project details</div><strong>{{ $s['title'] ?: '—' }}</strong>
@if (!empty($s['site']))<div>{{ $s['site'] }}</div>@endif
@if (!empty($s['type']))<div><span class="muted">Type:</span> {{ $s['type'] }}</div>@endif
@if (!empty($s['area_sft']))<div><span class="muted">Measured BOQ area:</span> {{ number_format((int) $s['area_sft']) }} sft</div>@endif
</td></tr></table>

@if (! empty($s['intro_line']))<p style="margin: 10px 0 2px;">{{ $s['intro_line'] }}</p>@endif


@foreach (\App\Domain\Sales\Services\DocumentStyles::visibleBlocks('quote', $design) as $block)
    @if (in_array($block, ['summary', 'boq', 'totals', 'signatures'], true))
        @include('pdf.partials.interior-v2.'.$block)
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
