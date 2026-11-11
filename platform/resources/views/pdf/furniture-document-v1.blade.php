@php
    $invoice = ($s['furniture_renderer'] ?? '') === 'furniture-v1';
    $design = is_array($s['document_design'] ?? null) ? $s['document_design'] : [];
    $style = \App\Domain\Sales\Services\FurnitureDocument::style($design['furniture_style'] ?? null);
    $text = \App\Domain\Sales\Services\FurnitureDocument::text($s['furniture'] ?? null);
    $company = $invoice ? $s['business'] : $s['company'];
    $client = $s['client'];
    $brand = $design['brand_name'] ?? ($company['brand'] ?? $company['name'] ?? 'Woodex Furniture');
    $primary = preg_match('/^#[0-9a-fA-F]{6}$/', $design['accent_color'] ?? '') ? $design['accent_color'] : '#1b5e3b';
    $secondary = $style['secondary_color'];
    $kind = $invoice ? 'Invoice' : 'Quotation';
    $title = $text['document_title'] ?: $style[$invoice ? 'invoice_title' : 'quotation_title'];
    $number = $s['label'] ?? $s['number'];
    $money = fn (int $minor) => \App\Support\Money::ofMinor($minor)->format();
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');
    $totals = $s['totals'] ?? [];
    $rows = [];
    if ($invoice) {
        $rows = $s['items'];
    } else {
        foreach ($s['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $rows[] = [...$item, 'rate' => $money((int) $item['rate_minor']), 'line_total' => ($item['is_optional'] ?? false) ? 'Optional' : $money((int) $item['line_total_minor'])];
            }
        }
    }
    $advancePercent = $invoice ? ($s['advance_percent'] ?? '0') : $qty(($totals['advance_bp'] ?? 0) / 100);
    $summary = $invoice ? [
        ['Subtotal', $s['subtotal']], ['Discount', $s['discount']], ['Rent', $s['rent']], ['Shipping / transport', $s['shipping']], ['Tax', $s['tax']], ['Rounding', $s['rounding']], ['Invoice total', $s['total']], ['Advance requested ('.$advancePercent.'%)', $s['advance']], ['Verified payments', $s['paid']], ['Outstanding balance', $s['balance']],
    ] : [
        ['Subtotal', $money((int) $totals['subtotal_minor'])], ['Discount', $money((int) $totals['discount_minor'])], ['Rent', $money((int) $totals['other_charges_minor'])], ['Shipping / transport', $money((int) ($totals['shipping_minor'] ?? 0))], ['Tax', $money((int) $totals['tax_minor'])], ['Rounding', $money((int) $totals['rounding_minor'])], ['Quotation total', $money((int) $totals['total_minor'])], ['Advance requested ('.$advancePercent.'%)', $money((int) ($totals['advance_minor'] ?? 0))], ['Remainder after requested advance', $money((int) ($totals['after_advance_minor'] ?? $totals['total_minor']))],
    ];
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $kind }} {{ $number }}</title>
<style>
@foreach ([400, 700] as $weight)
@font-face { font-family: 'DM Sans'; font-style: normal; font-weight: {{ $weight }}; src: url('{{ !empty($preview) ? 'data:font/ttf;base64,'.base64_encode(file_get_contents(public_path('fonts/documents/dm-sans-'.$weight.'.ttf'))) : public_path('fonts/documents/dm-sans-'.$weight.'.ttf') }}') format('truetype'); }
@endforeach
@page { margin: 30px 34px 58px; }
* { box-sizing: border-box; }
body { margin: 0; color: #202723; font-family: 'DM Sans', 'DejaVu Sans', sans-serif; font-size: 10px; line-height: 1.4; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
td, th { overflow-wrap: anywhere; word-wrap: break-word; vertical-align: top; }
.brand { font-size: 19px; font-weight: bold; letter-spacing: 1px; color: {{ $primary }}; }
.identity { text-align: {{ $style['header_align'] }}; padding-bottom: 12px; }
.logo { display: block; margin: 0 {{ $style['header_align'] === 'right' ? '0' : 'auto' }} 4px {{ $style['header_align'] === 'left' ? '0' : 'auto' }}; max-width: 68px; max-height: 52px; }
.title { background: {{ $secondary }}; border-bottom: 4px solid {{ $primary }}; padding: 6px 10px; }
.title strong { color: {{ $primary }}; font-size: 15px; }
.meta { font-size: 9px; text-align: right; }
.client { margin: 12px 0; }
.client td { padding: 2px 0; }
.muted { color: #58655c; }
.items thead { display: table-header-group; }
.items th { background: {{ $primary }}; color: white; padding: 7px 4px; text-align: left; font-size: 9px; font-weight: bold; }
.items td { border: 1px solid #b5bdb7; padding: 7px 4px; }
.items tr { page-break-inside: avoid; }
.items .num, .num { text-align: right; font-variant-numeric: tabular-nums; }
.items .product { font-weight: bold; }
.items .spec { white-space: pre-line; }
.summary-wrap { margin-top: 12px; page-break-inside: avoid; }
.summary-wrap > tbody > tr > td:first-child { width: 39%; padding-right: 18px; }
.summary td { padding: 3px 6px; border-bottom: 1px solid #e1e5e2; }
.summary .total td { background: {{ $secondary }}; border-bottom: 2px solid {{ $primary }}; font-weight: bold; }
.summary .balance td { font-weight: bold; color: {{ $primary }}; }
.notes { margin-top: 14px; }
.notes h3 { font-size: 10px; color: {{ $primary }}; border-bottom: 1px solid {{ $secondary }}; margin: 12px 0 5px; }
.notes p { margin: 1px 0; }
.footer { position: fixed; bottom: -38px; left: 0; right: 0; font-size: 8px; text-align: {{ $style['footer_align'] }}; border-top: 4px solid {{ $primary }}; background: {{ $secondary }}; padding: 7px 8px; }
.kind { font-size: 8px; margin-top: 3px; }
@if (!empty($preview))
@media screen { html { background: #e9e7e2; } body { background: white; width: 794px; min-height: 1123px; margin: 12px auto; padding: 28px 32px; } .footer { position: static; margin-top: 26px; } }
@endif
</style></head>
<body data-furniture-document="{{ $invoice ? 'invoice' : 'quotation' }}">
@if (empty($preview))
<footer class="footer">{{ $design['footer_text'] ?? '' }}@if (empty($design['footer_text'])){{ $company['phone'] ?? '' }} · {{ $company['email'] ?? '' }} · {{ $company['website'] ?? '' }}@endif</footer>
@endif
<header class="identity">
@if (!empty($design['logo_data_uri']) && preg_match('/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/', $design['logo_data_uri']))
<img class="logo" src="{{ $design['logo_data_uri'] }}" alt="Woodex brand mark">
@endif
<div class="brand">{{ $brand }}</div>
<div>{{ $design['header_text'] ?? $company['tagline'] ?? '' }}</div>
<div class="muted">{{ $company['address'] ?? '' }} · {{ $company['phone'] ?? '' }} · {{ $company['email'] ?? '' }}</div>
</header>
<table class="title"><tr><td><strong>{{ $title }}</strong><div class="kind">{{ $kind }} record · {{ $number }}@if ($invoice) · {{ ucfirst($s['status']) }}@endif</div></td><td class="meta">
<strong>{{ $number }}</strong><br>Date: {{ $invoice ? $s['issue_date'] : $s['issued_on'] }}<br>{{ $invoice ? 'Due' : 'Valid until' }}: {{ $invoice ? $s['due_date'] : $s['valid_until'] }}
</td></tr></table>
<table class="client"><tr><td style="width:65%"><strong>To: {{ $client['name'] }}</strong>@if (!empty($client['company']))<br>{{ $client['company'] }}@endif @if (!empty($client['address']))<br>{{ $client['address'] }}@endif<br>{{ $client['phone'] ?? '' }} {{ $client['email'] ?? '' }}</td><td class="meta">@if ($text['po_reference'])PO / reference: {{ $text['po_reference'] }}<br>@endif @if ($invoice && !empty($s['source_number']))Quotation: {{ $s['source_number'] }}@elseif (!$invoice){{ $s['title'] }}@if ($s['site'])<br>Site: {{ $s['site'] }}@endif @endif</td></tr></table>
@if (!empty($s['intro_line']))<p>{{ $s['intro_line'] }}</p>@endif
@if (!empty($s['scope_html']))<div class="notes">{!! \App\Support\Html::sanitize($s['scope_html']) !!}</div>@endif
<table class="items"><thead><tr><th style="width:4%">Sr</th><th style="width:16%">Item / Product</th><th style="width:36%">Description / Specification</th><th style="width:6%">Qty</th><th style="width:8%">Unit</th><th style="width:14%" class="num">Unit price</th><th style="width:16%" class="num">Total</th></tr></thead><tbody>
@foreach ($rows as $i => $item)
<tr><td>{{ $i + 1 }}</td><td class="product">{{ $item['product_name'] ?? '' }}</td><td class="spec">{{ $item['description'] }}@if (($item['discount_minor'] ?? $item['discount'] ?? 0) > 0)<br><span class="muted">Line discount included: {{ $money((int) ($item['discount_minor'] ?? $item['discount'])) }}</span>@endif</td><td class="num">{{ $qty($item['qty']) }}</td><td>{{ $item['unit_label'] ?? $item['unit'] }}</td><td class="num">{{ $item['rate'] }}</td><td class="num">{{ $item['line_total'] }}</td></tr>
@endforeach
</tbody></table>
<table class="summary-wrap"><tr><td><strong>Amount in words</strong><p>{{ $invoice ? $s['total_in_words'] : $totals['total_words'] }}</p><span class="muted">{{ $invoice ? 'Advance requested is not a receipt. Only verified payments reduce the outstanding balance.' : 'Requested advance is not a payment received. The remainder assumes that advance is paid.' }}</span></td><td><table class="summary">
@foreach ($summary as [$label, $amount])
<tr class="{{ in_array($label, ['Invoice total', 'Quotation total']) ? 'total' : (in_array($label, ['Outstanding balance', 'Remainder after requested advance']) ? 'balance' : '') }}"><td style="width:66%">{{ $label }}</td><td class="num">{{ $amount }}</td></tr>
@endforeach
</table></td></tr></table>
<div class="notes">
<h3>Commercial notes &amp; terms</h3>
@if ($text['delivery_text'])<p><strong>Delivery:</strong> {{ $text['delivery_text'] }}</p>@endif
@if ($text['transport_text'])<p><strong>Transportation:</strong> {{ $text['transport_text'] }}</p>@endif
@if ($text['tax_note'])<p><strong>Tax:</strong> {{ $text['tax_note'] }}</p>@endif
@if (!empty($s['notes']))<p style="white-space:pre-line">{{ $s['notes'] }}</p>@endif
@foreach ($invoice ? (preg_split('/\R/', (string) ($s['terms'] ?? '')) ?: []) : ($s['terms'] ?? []) as $term)<p>{{ $term }}</p>@endforeach
@php $banks = $invoice ? (empty($s['bank']) ? [] : [$s['bank']]) : ($s['bank_accounts'] ?? []); @endphp
@if ($banks)<h3>Payment details</h3>@foreach ($banks as $bank)<p>{{ $bank['title'] }} · {{ $bank['bank_name'] }} · {{ $bank['account_number'] }} @if (!empty($bank['iban']))· {{ $bank['iban'] }}@endif</p>@endforeach @endif
@if (!empty($s['payments']))<h3>Verified payment receipts</h3>@foreach ($s['payments'] as $payment)<p>{{ $payment['receipt'] }} · {{ $payment['date'] }} · {{ $payment['amount'] }}</p>@endforeach @endif
</div>
@php $signatories = $s['signatories'] ?? (empty($s['signatory']) ? [] : [$s['signatory']]); @endphp
@if ($signatories)
<table style="margin-top:14px;page-break-inside:avoid"><tr>@foreach ($signatories as $signatory)<td style="padding:6px 12px 0 0"><strong>{{ $signatory['name'] ?? '' }}</strong><br>{{ $signatory['title'] ?? '' }}</td>@endforeach</tr></table>
@endif
@if (!empty($preview))
<footer class="footer">{{ $design['footer_text'] ?? '' }}@if (empty($design['footer_text'])){{ $company['phone'] ?? '' }} · {{ $company['email'] ?? '' }} · {{ $company['website'] ?? '' }}@endif</footer>
@endif
</body></html>
