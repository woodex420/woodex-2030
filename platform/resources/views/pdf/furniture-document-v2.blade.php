@php
    $invoice = ($s['furniture_renderer'] ?? '') === 'furniture-v1';
    $design = is_array($s['document_design'] ?? null) ? $s['document_design'] : [];
    $style = \App\Domain\Sales\Services\FurnitureDocument::style($design['furniture_style'] ?? null);
    $text = \App\Domain\Sales\Services\FurnitureDocument::text($s['furniture'] ?? null);
    $company = $invoice ? $s['business'] : $s['company'];
    $client = $s['client'];
    $brand = $design['brand_name'] ?? ($company['brand'] ?? $company['name'] ?? 'Woodex Furniture');
    $primary = preg_match('/^#[0-9a-fA-F]{6}$/', $design['accent_color'] ?? '') ? $design['accent_color'] : '#276749';
    $secondary = $style['secondary_color'];
    $kind = $invoice ? 'Invoice' : 'Quotation';
    $title = $text['document_title'] ?: $style[$invoice ? 'invoice_title' : 'quotation_title'];
    $number = $s['label'] ?? $s['number'];
    $money = fn (int $minor) => \App\Support\Money::ofMinor($minor)->format();
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');
    $date = fn ($value) => $value ? \Carbon\CarbonImmutable::parse($value)->format('d/m/Y') : '—';
    $t = $s['totals'] ?? [];
    $rows = $invoice ? $s['items'] : [];
    if (!$invoice) {
        foreach ($s['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $rows[] = [...$item, 'rate' => $money((int) $item['rate_minor']), 'line_total' => ($item['is_optional'] ?? false) ? 'Optional' : $money((int) $item['line_total_minor'])];
            }
        }
    }
    $amounts = $invoice ? $s['furniture_amounts'] : ['discount' => (int) $t['discount_minor'], 'charges' => (int) $t['other_charges_minor'] + (int) ($t['shipping_minor'] ?? 0), 'tax' => (int) $t['tax_minor'], 'rounding' => (int) $t['rounding_minor']];
    $advancePercent = $invoice ? $s['advance_percent'] : $qty(($t['advance_bp'] ?? 0) / 100);
    $summary = [['Subtotal', $invoice ? $s['subtotal'] : $money((int) $t['subtotal_minor']), '']];
    foreach (['discount' => 'Discount', 'charges' => 'Rent / Transport', 'tax' => 'Tax'] as $key => $label) {
        if ($amounts[$key] !== 0 || $text['show_'.$key] === 'yes') { $summary[] = [$label, ($key === 'discount' && $amounts[$key] > 0 ? '− ' : '').$money($amounts[$key]), '']; }
    }
    // Historical non-zero adjustments are disclosed rather than silently erased. New v2 quotes use exact paisa.
    if ($amounts['rounding'] !== 0) { $summary[] = ['Previous adjustment', $money($amounts['rounding']), '']; }
    $summary[] = [$invoice ? 'Invoice total' : 'Quotation total', $invoice ? $s['total'] : $money((int) $t['total_minor']), 'grand'];
    if ($text['show_advance'] === 'yes') {
        $summary[] = ['Advance payment ('.$advancePercent.'%)', $invoice ? $s['advance'] : $money((int) $t['advance_minor']), 'advance'];
        $summary[] = [$invoice ? 'Remaining after advance (planned)' : 'Remaining after advance', $invoice ? $s['after_requested_advance'] : $money((int) $t['after_advance_minor']), 'remainder'];
    }
    if ($invoice) {
        $summary[] = ['Verified payments', $s['paid'], ''];
        $summary[] = ['Outstanding balance', $s['balance'], 'remainder'];
    }
    $terms = $invoice ? (preg_split('/\R/', (string) ($s['terms'] ?? '')) ?: []) : ($s['terms'] ?? []);
    $people = \App\Domain\Sales\Services\FurnitureDocument::signatories($text['signatories_text']);
@endphp
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>{{ $kind }} {{ $number }}</title>
<style>
@foreach ([400, 700] as $weight)
@font-face { font-family: 'DM Sans'; font-style: normal; font-weight: {{ $weight }}; src: url('{{ !empty($preview) ? 'data:font/ttf;base64,'.base64_encode(file_get_contents(public_path('fonts/documents/dm-sans-'.$weight.'.ttf'))) : public_path('fonts/documents/dm-sans-'.$weight.'.ttf') }}') format('truetype'); }
@endforeach
@page { margin: 175px 34px 85px; }
* { box-sizing: border-box; }
body { margin: 0; font-family: 'DM Sans', 'DejaVu Sans', sans-serif; font-size: 11px; line-height: 1.3; color: #24332b; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
td, th { vertical-align: top; word-wrap: break-word; overflow-wrap: anywhere; }
.letterhead { position: fixed; top: -156px; left: 0; right: 0; height: 111px; }
.datebar { background: {{ $secondary }}; font-size: 9px; padding: 4px 8px; text-align: right; }
.header-grid td { padding: 10px 0 9px; }
.header-grid .logo-cell { width: 14%; padding-right: 12px; }
.logo { max-width: 70px; max-height: 59px; }
.brand { font-size: 18px; font-weight: 700; color: {{ $primary }}; margin-bottom: 3px; }
.company { font-size: 9px; line-height: 1.3; }
.document-meta { text-align: right; width: 34%; font-size: 10px; }
.document-title { font-size: 21px; color: {{ $primary }}; font-weight: 700; margin-bottom: 3px; }
.header-rule { border-bottom: 2px solid {{ $primary }}; }
.client { margin-bottom: 15px; font-size: 10px; }
.client strong { font-size: 12px; }
.client .ref { text-align: right; width: 35%; }
.scope { margin: 0 0 12px; }
.items thead { display: table-header-group; }
.items th { padding: 8px 5px; color: #fff; background: {{ $primary }}; text-align: left; font-size: 10px; }
.items td { padding: 8px 5px; border: 1px solid #c8d0ca; font-size: 10px; }
.items tr { page-break-inside: avoid; }
.items tbody tr:nth-child(even) { background: #f5f7f5; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.items .num { text-align: right; }
.spec { white-space: pre-line; }
.muted { color: #67736b; font-size: 9px; }
.summary { margin-top: 14px; page-break-inside: avoid; }
.summary td { padding: 6px 9px; border-bottom: 1px solid #dbe1dc; }
.summary .grand td { background: {{ $primary }}; color: #fff; font-size: 12px; font-weight: 700; padding: 8px 9px; }
.summary .advance td { background: #f0f4f0; }
.summary .remainder td { font-weight: 700; color: {{ $primary }}; border-bottom: 2px solid {{ $primary }}; }
.terms { margin-top: 17px; page-break-inside: avoid; }
h3 { font-size: 11px; color: {{ $primary }}; border-bottom: 1px solid #c8d0ca; padding-bottom: 4px; margin: 0 0 6px; }
.terms p { margin: 3px 0; }
.terms .label { font-weight: 700; width: 24%; }
.terms td { padding: 3px 0; }
.signatures { margin-top: 25px; page-break-inside: avoid; }
.signatures td { text-align: center; padding: 10px 7px 0; font-size: 10px; }
.signatures strong { display: block; }
.footer { position: fixed; bottom: -47px; left: 0; right: 0; font-size: 8px; }
.footer .message { background: {{ $secondary }}; text-align: {{ $style['footer_align'] }}; padding: 6px; width: 72%; }
.footer .green { width: 16%; background: {{ $primary }}; }
.footer .lime { width: 12%; background: #acd12e; }
.footer .contact { text-align: center; font-size: 7px; padding-top: 4px; color: #67736b; }
@if (!empty($preview))
html { background: #e9e7e2; }
body { position: relative; background: #fff; width: 794px; min-height: 1123px; margin: 12px auto; padding: 175px 34px 85px; }
.letterhead { position: absolute; top: 19px; left: 34px; right: 34px; }
.footer { position: absolute; bottom: 20px; left: 34px; right: 34px; }
@endif
</style></head><body data-furniture-document="{{ $invoice ? 'invoice' : 'quotation' }}" data-furniture-revision="2">
<header class="letterhead">
<div class="datebar"><strong>Date</strong> &nbsp; {{ $date($invoice ? $s['issue_date'] : $s['issued_on']) }}</div>
<table class="header-grid header-rule"><tr>
<td class="logo-cell">@if (!empty($design['logo_data_uri']) && preg_match('/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/', $design['logo_data_uri']))<img class="logo" src="{{ $design['logo_data_uri'] }}" alt="Woodex brand mark">@endif</td>
<td><div class="brand">{{ $brand }}</div><div class="company">{{ $design['header_text'] ?? '' }}<br>{{ $company['address'] ?? '' }}<br>{{ $company['phone'] ?? '' }} · {{ $company['email'] ?? '' }}</div></td>
<td class="document-meta"><div class="document-title">{{ $title }}</div><strong>No. {{ $number }}</strong><br>{{ $invoice ? 'Due' : 'Valid until' }}: {{ $date($invoice ? $s['due_date'] : $s['valid_until']) }}<div class="muted">{{ $kind }} record @if ($invoice)· {{ ucfirst($s['status']) }}@endif</div></td>
</tr></table></header>
<footer class="footer"><table><tr><td class="message">{{ ($design['footer_text'] ?? '') ?: 'THANK YOU FOR YOUR BUSINESS!' }}</td><td class="green"></td><td class="lime"></td></tr></table><div class="contact">{{ $company['phone'] ?? '' }} · {{ $company['website'] ?? '' }}</div></footer>
<table class="client"><tr><td><strong>{{ $client['name'] }}</strong>@if (!empty($client['company']))<br>{{ $client['company'] }}@endif @if (!empty($client['address']))<br>{{ $client['address'] }}@endif<br>{{ $client['phone'] ?? '' }} {{ $client['email'] ?? '' }}</td><td class="ref">@if ($text['po_reference'])<strong>PO / Reference:</strong> {{ $text['po_reference'] }}<br>@endif @if ($invoice && !empty($s['source_number']))Quotation: {{ $s['source_number'] }}@elseif (!$invoice){{ $s['title'] }}@if ($s['site'])<br>Site: {{ $s['site'] }}@endif @endif</td></tr></table>
@if (!empty($s['intro_line']))<p>{{ $s['intro_line'] }}</p>@endif
@if (!empty($s['scope_html']))<div class="scope">{!! \App\Support\Html::sanitize($s['scope_html']) !!}</div>@endif
<table class="items"><thead><tr><th style="width:4%">Sr</th><th style="width:20%">Product</th><th style="width:32%">Description</th><th style="width:6%" class="num">Qty</th><th style="width:8%">Unit</th><th style="width:14%" class="num">Unit price</th><th style="width:16%" class="num">Total</th></tr></thead><tbody>
@foreach ($rows as $i => $item)<tr><td>{{ $i + 1 }}</td><td><strong>{{ $item['product_name'] ?? '' }}</strong></td><td class="spec">{{ $item['description'] }}@if (($item['discount_minor'] ?? $item['discount'] ?? 0) > 0)<br><span class="muted">Line discount included: {{ $money((int) ($item['discount_minor'] ?? $item['discount'])) }}</span>@endif</td><td class="num">{{ $qty($item['qty']) }}</td><td>{{ $item['unit_label'] ?? $item['unit'] }}</td><td class="num">{{ $item['rate'] }}</td><td class="num">{{ $item['line_total'] }}</td></tr>@endforeach
</tbody></table>
<table class="summary"><tbody>@foreach ($summary as [$label, $amount, $class])<tr class="{{ $class }}"><td style="width:76%">{{ $label }}</td><td class="num">{{ $amount }}</td></tr>@endforeach</tbody></table>
@if ($invoice)<p class="muted">An advance request is not a receipt. Outstanding balance changes only after verified payments.</p>@endif
@if ($text['show_terms'] === 'yes')
<section class="terms"><h3>Terms and conditions</h3>
<table>@if (!$invoice)<tr><td class="label">Quotation validity</td><td>{{ $date($s['valid_until']) }}</td></tr>@endif
@if ($text['show_delivery'] === 'yes' && $text['delivery_text'])<tr><td class="label">Delivery</td><td>{{ $text['delivery_text'] }}</td></tr>@endif
@if ($text['show_transport'] === 'yes' && $text['transport_text'])<tr><td class="label">Transportation</td><td>{{ $text['transport_text'] }}</td></tr>@endif
<tr><td class="label">Taxes</td><td>{{ $text['tax_note'] ?: ($amounts['tax'] > 0 ? 'Applicable tax is included as shown above.' : 'The above quote excludes applicable taxes.') }}</td></tr></table>
@foreach ($terms as $term)<p>{{ $term }}</p>@endforeach</section>
@endif
@if ($text['show_notes'] === 'yes' && !empty($s['notes']))<section class="terms"><h3>Notes</h3><p style="white-space:pre-line">{{ $s['notes'] }}</p></section>@endif
@php $banks = $invoice ? (empty($s['bank']) ? [] : [$s['bank']]) : ($s['bank_accounts'] ?? []); @endphp
@if ($banks)<section class="terms"><h3>Payment details</h3>@foreach ($banks as $bank)<p>{{ $bank['title'] }} · {{ $bank['bank_name'] }} · {{ $bank['account_number'] }} @if (!empty($bank['iban']))· {{ $bank['iban'] }}@endif</p>@endforeach</section>@endif
@if ($invoice && !empty($s['payments']))<section class="terms"><h3>Verified payment receipts</h3>@foreach ($s['payments'] as $payment)<p>{{ $payment['receipt'] }} · {{ $payment['date'] }} · {{ $payment['amount'] }}</p>@endforeach</section>@endif
@if ($text['show_signatories'] === 'yes' && $people)<table class="signatures"><tr>@foreach ($people as $person)<td><strong>{{ $person['name'] }}</strong>{{ $person['title'] }}</td>@endforeach</tr></table>@endif
</body></html>
