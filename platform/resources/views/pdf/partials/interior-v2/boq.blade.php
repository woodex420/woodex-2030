<h2>Detailed bill of quantities</h2>
<table class="lines" data-interior-boq>
<thead><tr><th style="width:6%">SR</th><th style="width:44%">Particulars / work description</th><th style="width:11%" class="right">Qty / Area</th><th style="width:9%">Unit</th><th style="width:14%" class="right">Rate (Rs)</th><th style="width:16%" class="right">Amount (Rs)</th></tr></thead>
<tbody>
@php $rowNumber = 0; @endphp
@foreach ($s['sections'] as $si => $section)
@if (!in_array('summary', \App\Domain\Sales\Services\DocumentStyles::visibleBlocks('quote', $design), true))
<tr class="section"><td colspan="6">{{ $section['name'] }}@if ($section['note']) · {{ $section['note'] }}@endif</td></tr>
@endif
@foreach ($section['items'] as $ii => $item)
<tr @class(['optional' => $item['is_optional']])><td>{{ count($s['sections']) > 1 ? ($si + 1).'.'.($ii + 1) : ++$rowNumber }}</td><td>@if (($item['image'] ?? null) !== null)<img src="{{ $item['image'] }}" alt="" style="float:left;width:40px;height:40px;object-fit:cover;margin:0 7px 2px 0;">@endif{!! nl2br(e($item['description'])) !!}@if ($item['is_optional']) (optional, not in total)@endif
@if ($item['discount_minor'] > 0)<br><span class="muted">Discount {{ $money((int) $item['discount_minor']) }}</span>@endif</td><td class="right">{{ $qty((string) $item['qty']) }}</td><td>{{ $item['unit_label'] }}</td><td class="right">{{ $plain((int) $item['rate_minor']) }}</td><td class="right">{{ $plain((int) $item['line_total_minor']) }}</td></tr>
@endforeach
@endforeach
<tr class="subtotal"><td colspan="5">TOTAL BOQ SUM (PKR)</td><td class="right">{{ $plain((int) $t['subtotal_minor']) }}</td></tr>
</tbody></table>
