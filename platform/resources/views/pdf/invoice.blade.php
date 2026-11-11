@if (($s['furniture_renderer'] ?? null) === 'furniture-v1')
    @include('pdf.furniture-document')
@else
    @include('pdf.invoice-legacy')
@endif
