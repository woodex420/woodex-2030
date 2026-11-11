@if ($layout === 'single')
<h2>Particulars</h2>
<table class="lines">
    @include('pdf.partials.quotation-v21-table-head')
    <tbody>
    @foreach ($s['sections'] as $si => $section)
        <tr class="section"><td>{{ str_pad((string) ($si + 1), 2, '0', STR_PAD_LEFT) }}</td><td colspan="4">{{ $section['name'] }}</td><td class="right">{{ $plain($section['subtotal_minor']) }}</td></tr>
        @include('pdf.partials.quotation-v21-rows')
        @if ($section['note'])<tr><td></td><td colspan="5"><em>Note: {{ $section['note'] }}</em></td></tr>@endif
    @endforeach
    </tbody>
</table>
@else
    @foreach ($s['sections'] as $si => $section)
        <div class="trade-page">
            <h2 class="trade-title">{{ str_pad((string) ($si + 1), 2, '0', STR_PAD_LEFT) }} · {{ $section['name'] }}</h2>
            <p>Section total <strong>{{ $money($section['subtotal_minor']) }}</strong></p>
            <table class="lines">
                @include('pdf.partials.quotation-v21-table-head')
                <tbody>
                @include('pdf.partials.quotation-v21-rows')
                <tr class="subtotal"><td></td><td colspan="4">Total · {{ $section['name'] }}</td><td class="right">{{ $plain($section['subtotal_minor']) }}</td></tr>
                </tbody>
            </table>
            @if ($section['note'])<p class="muted">{{ $section['note'] }}</p>@endif
        </div>
    @endforeach
@endif
