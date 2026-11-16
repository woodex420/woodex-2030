@if ($s['sections'] !== [])
<h2>Summary of cost</h2>
<table class="lines" data-interior-summary>
<thead><tr><th style="width:6%">SR</th><th style="width:52%">Particulars</th><th style="width:20%;text-align:center">Phase / Notes</th><th style="width:22%" class="right">Amount (Rs)</th></tr></thead>
<tbody>
@foreach ($s['sections'] as $i => $section)
<tr><td>{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</td><td>{{ $section['name'] }}</td><td class="phase">{{ $section['note'] ?: '—' }}</td><td class="right">{{ $plain((int) $section['subtotal_minor']) }}</td></tr>
@endforeach
<tr class="subtotal"><td colspan="3">TOTAL ESTIMATED BUDGET</td><td class="right">{{ $plain((int) $t['subtotal_minor']) }}</td></tr>
</tbody></table>
@endif
