@php
    $design = is_array($s['document_design'] ?? null) ? $s['document_design'] : [];
    $style = \App\Domain\Sales\Services\FurnitureDocument::style($design['furniture_style'] ?? null);
    $text = \App\Domain\Sales\Services\FurnitureDocument::text($s['furniture'] ?? null);
    $company = $s['company'];
    $client = $s['client'];
    $brand = $design['brand_name'] ?? $company['brand'] ?? 'Woodex Furniture';
    $primary = preg_match('/^#[0-9a-fA-F]{6}$/', $design['accent_color'] ?? '') ? $design['accent_color'] : '#347647';
    $title = $text['document_title'] ?: $style['quotation_title'];
    $number = $s['label'] ?? $s['number'];
    $money = fn (int $minor) => \App\Support\Money::ofMinor($minor)->format();
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');
    $date = fn ($value) => $value ? \Carbon\CarbonImmutable::parse($value)->format('d M Y') : '—';
    $t = $s['totals'];
    $rows = [];
    foreach ($s['sections'] as $section) {
        foreach ($section['items'] as $item) {
            $rows[] = $item;
        }
    }
    $amounts = ['discount' => (int) $t['discount_minor'], 'charges' => (int) $t['other_charges_minor'] + (int) ($t['shipping_minor'] ?? 0), 'tax' => (int) $t['tax_minor']];
    $advancePercent = $qty(($t['advance_bp'] ?? 0) / 100);
    $summary = [['Subtotal', $money((int) $t['subtotal_minor']), '']];
    foreach (['discount' => 'Discount', 'charges' => 'Rent / Transport', 'tax' => 'Tax'] as $key => $label) {
        if ($amounts[$key] !== 0 || $text['show_'.$key] === 'yes') {
            $summary[] = [$label, ($key === 'discount' && $amounts[$key] > 0 ? '− ' : '').$money($amounts[$key]), ''];
        }
    }
    if ((int) $t['rounding_minor'] !== 0) {
        $summary[] = ['Previous adjustment', $money((int) $t['rounding_minor']), ''];
    }
    $summary[] = ['Quotation total', $money((int) $t['total_minor']), 'grand'];
    if ($text['show_advance'] === 'yes') {
        $summary[] = ['Advance payment ('.$advancePercent.'%)', $money((int) $t['advance_minor']), 'advance'];
        $summary[] = ['Remaining after advance', $money((int) $t['after_advance_minor']), 'remainder'];
    }
    $terms = \App\Domain\Sales\Services\FurnitureDocument::commercialTerms($s);
    $blocks = \App\Domain\Sales\Services\DocumentStyles::visibleBlocks('quote', $design);
    $mainBlocks = array_values(array_intersect($blocks, ['scope', 'summary', 'boq']));
    $closingBlocks = array_values(array_diff($blocks, ['scope', 'summary', 'boq']));
    $productRows = $rows;
    $plain = fn (int $minor) => \App\Support\Money::ofMinor($minor)->format(false, false, '');
    $people = \App\Domain\Sales\Services\FurnitureDocument::signatories($text['signatories_text']);
    $logo = $design['logo_data_uri'] ?? '';
    $hasLogo = is_string($logo) && preg_match('/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/', $logo);
@endphp
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>{{ $title }} {{ $number }}</title>
<style>
@foreach ([400, 500, 700] as $weight)
@font-face { font-family: 'Inter'; font-style: normal; font-weight: {{ $weight }}; src: url('{{ !empty($preview) ? 'data:font/ttf;base64,'.base64_encode(file_get_contents(public_path('fonts/documents/inter-'.$weight.'.ttf'))) : public_path('fonts/documents/inter-'.$weight.'.ttf') }}') format('truetype'); }
@endforeach
@page { margin: 174px 44px 58px; }
* { box-sizing: border-box; }
body { margin: 0; font-family: 'Inter', 'DejaVu Sans', sans-serif; color: #24312c; font-size: 11px; line-height: 1.45; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
td, th { vertical-align: top; word-wrap: break-word; overflow-wrap: anywhere; }
.letterhead { position: fixed; top: -130px; left: 0; right: 0; height: 120px; border-bottom: 1px solid {{ $style['secondary_color'] }}; }
.header-grid .identity { width: 62%; padding-right: 24px; }
.accent { width: 38px; height: 2px; background: {{ $primary }}; margin-bottom: 10px; }
.brand { color: {{ $primary }}; font-size: 23px; font-weight: 700; line-height: 1.2; margin-bottom: 9px; }
.brand-logo { width: 43px; height: auto; max-height: 52px; }
.brand-identity { padding-left: 12px; border-left: 1px solid #d7e0d9; }
.brand-lockup { margin-bottom: 9px; }
.brand-lockup .mark { width: 57px; vertical-align: middle; padding-right: 10px; }
.brand-lockup .brand { font-size: 23px; margin-bottom: 3px; }
.brand-tagline { color: #7b867f; font-size: 8px; letter-spacing: 1px; text-transform: uppercase; }
.company { color: #59665f; font-size: 10px; line-height: 1.5; }
.company-contact { margin-top: 6px; font-size: 11px; font-weight: 500; }
.document-title { font-size: 30px; font-weight: 700; text-align: right; text-transform: uppercase; line-height: 1.2; margin-bottom: 8px; }
.document-meta td { padding-bottom: 3px; font-size: 10px; line-height: 1.25; }
.document-meta .label { color: #59665f; width: 26%; }
.document-meta .value { text-align: right; font-weight: 500; }
.client { margin-bottom: 12px; }
.client td { padding-right: 14px; }
.client .prepared { width: 43%; }
.client .location { width: 34%; }
.eyebrow { color: #59665f; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 7px; }
.client .name { font-size: 12px; font-weight: 700; }
.client .detail { font-size: 11px; }
.muted { color: #59665f; font-size: 9px; }
.project { margin: 0 0 12px; font-size: 10px; color: #59665f; }
.scope { margin-bottom: 16px; }
.items thead { display: table-header-group; }
.items th { background: {{ $primary }}; color: #fff; font-size: 9px; font-weight: 700; padding: 9px 6px; text-align: left; text-transform: uppercase; }
.items td { padding: 7px 6px; line-height: 1.35; border-bottom: 1px solid #d7e0d9; font-size: 11px; }
.items tr { page-break-inside: avoid; }
.items tbody tr:nth-child(even) { background: #f8faf8; }
.num, .items .num { text-align: right; font-variant-numeric: tabular-nums; }
.spec { white-space: pre-line; }
.financials { margin-top: 14px; page-break-inside: avoid; }
.financials .space { width: 52%; }
.summary td { padding: 2px 10px; line-height: 1.25; border-bottom: 1px solid #d7e0d9; font-size: 10px; }
.summary .amount { width: 42%; }
.summary .grand td { font-weight: 700; }
.summary .advance td { font-weight: 500; }
.summary .remainder td { background: #f1f6f1; color: {{ $primary }}; border-top: 2px solid {{ $primary }}; border-bottom: 0; padding-top: 11px; padding-bottom: 11px; font-weight: 700; }
.terms { margin-top: 8px; page-break-inside: avoid; }
h3 { margin: 0 0 8px; color: {{ $primary }}; font-size: 10px; font-weight: 700; }
.terms td { padding: 1px 0; font-size: 10px; line-height: 1.25; }
.terms .label { width: 23%; font-weight: 500; padding-right: 14px; }
.terms .value { color: #59665f; }
.terms p { margin: 4px 0; font-size: 10px; color: #59665f; }
.signatures { margin-top: 24px; page-break-inside: avoid; }
.signatures td { text-align: center; font-size: 10px; padding: 7px; }
.signatures strong { display: block; font-size: 11px; margin-bottom: 3px; }
.signatures .signature-line { border-top: 1px solid #c8ccca; padding-top: 7px; margin: 0 14px; }
.footer { position: fixed; bottom: -42px; left: 0; right: 0; height: 32px; border-top: 1px solid {{ $style['secondary_color'] }}; padding-top: 6px; }
.project-cards { margin-bottom: 14px; }
.project-cards .gutter { width: 14px; }
.project-cards .card { width: 49%; border: 1px solid #d7e0d9; border-radius: 7px; padding: 11px 13px; background: #f8faf8; }
.project-cards .eyebrow { color: {{ $primary }}; margin-bottom: 5px; letter-spacing: .7px; }
.project-cards .name { font-size: 12px; font-weight: 700; margin-bottom: 4px; }
.project-cards .detail { font-size: 10px; line-height: 1.5; color: #59665f; }
.footer td { font-size: 9px; color: #59665f; line-height: 1.5; }
.footer .thanks { width: 42%; text-align: right; }
.footer strong { color: {{ $primary }}; font-size: 10px; }
.commercial-terms { margin: 0; }
.term-row { clear: both; page-break-inside: avoid; padding: 1px 0; font-size: 10px; line-height: 1.25; }
.term-label { float: left; width: 23%; font-weight: 500; }
.term-value { margin: 0 0 0 25%; color: #59665f; }
.closing { page-break-inside: avoid; padding-top: 10px; }
.closing h2, .section-summary h2 { margin: 4px 0 7px; color: {{ $primary }}; font-size: 10px; }
.lines th { background: {{ $primary }}; color: white; padding: 6px; font-size: 9px; }
.lines td { padding: 5px 6px; border-bottom: 1px solid #d7e0d9; font-size: 10px; }
.right { text-align: right; }
.box { padding: 7px; }
.paylabel { color: #59665f; }
.footer-contact { font-size: 8px; margin-right: 145px; }
@if (!empty($preview))
.page-content { display: flex; flex-direction: column; min-height: 891px; }
.closing { margin-top: auto; }
html { background: #e2e8f0; }
body { position: relative; background: #fff; width: 794px; min-height: 1123px; margin: 12px auto; padding: 174px 44px 58px; }
.letterhead { position: absolute; top: 44px; left: 44px; right: 44px; }
.footer { position: absolute; bottom: 14px; left: 44px; right: 44px; }
@endif
</style></head><body data-furniture-document="quotation" data-furniture-revision="5">
<header class="letterhead"><table class="header-grid"><tr><td class="identity">
<table class="brand-lockup" style="table-layout:auto;"><tr>@if ($hasLogo)<td class="mark" style="width:57px;"><img class="brand-logo" src="{{ $logo }}" alt="Woodex brand mark"></td>@endif<td class="brand-identity"><div class="brand">{{ $brand }}</div><div class="brand-tagline">Furniture · crafted for your space</div></td></tr></table>
<div class="company">@if (!empty($design['header_text'])){{ $design['header_text'] }}<br>@endif{{ $company['address'] ?? '' }}</div>
<div class="company-contact">{{ $company['phone'] ?? '' }}@if (!empty($company['email'])) | {{ $company['email'] }}@endif</div>
</td><td><div class="document-title">{{ $title }}</div><table class="document-meta">
<tr><td class="label">Ref #</td><td class="value">{{ $number }}</td></tr><tr><td class="label">Date</td><td class="value">{{ $date($s['issued_on']) }}</td></tr><tr><td class="label">Valid until</td><td class="value">{{ $date($s['valid_until']) }}</td></tr></table></td></tr></table></header>
<footer class="footer"><table><tr><td>{{ $brand }} · {{ $number }}</td><td class="thanks">{{ ($design['footer_text'] ?? '') ?: 'Quotation document' }}</td></tr></table><div class="footer-contact">{{ $company['phone'] ?? '' }}@if (!empty($company['email'])) · {{ $company['email'] }}@endif</div></footer>
<main class="page-content">
<table class="project-cards"><tr><td class="card"><div class="eyebrow">Prepared for</div><div class="name">{{ $client['company'] ?: $client['name'] }}</div>
@if ($client['company'] && $client['company'] !== $client['name'])<div class="detail">Attention: {{ $client['name'] }}</div>@endif
@if (!empty($client['address']))<div class="detail">{{ $client['address'] }}</div>@endif
<div class="detail">{{ implode(' · ', array_filter([$client['phone'] ?? '', $client['email'] ?? ''])) }}</div>
</td><td class="gutter"></td><td class="card"><div class="eyebrow">Project details</div><div class="name">{{ $s['title'] ?: 'Furniture supply' }}</div>
<div class="detail">Location: {{ $s['site'] ?: ($client['address'] ?? '—') }}</div>
@if (!empty($s['type']))<div class="detail">Type: {{ $s['type'] }}</div>@endif
@if ($text['po_reference'])<div class="detail">PO / Reference: {{ $text['po_reference'] }}</div>@endif
</td></tr></table>
@if (!empty($s['intro_line']))<p>{{ $s['intro_line'] }}</p>@endif
@foreach ($mainBlocks as $block)
    @include('pdf.partials.furniture-v5.'.$block)
@endforeach
@if ($closingBlocks)
@if (empty($preview) && ($commercialSpacer ?? 0) > 0)<div style="height:{{ $commercialSpacer }}pt"></div>@endif
<section id="quotation-commercial-close" class="closing">
@foreach ($closingBlocks as $block)
    @include('pdf.partials.furniture-v5.'.$block)
@endforeach
</section>
@endif
</main>
</body></html>
