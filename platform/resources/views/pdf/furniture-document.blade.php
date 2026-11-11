@if (($s['furniture_revision'] ?? 1) >= 5 && ($s['furniture_renderer'] ?? '') !== 'furniture-v1')
    @include('pdf.furniture-quotation-v5')
@elseif (($s['furniture_revision'] ?? 1) >= 4 && ($s['furniture_renderer'] ?? '') !== 'furniture-v1')
    @include('pdf.furniture-quotation-v4')
@elseif (($s['furniture_revision'] ?? 1) >= 3 && ($s['furniture_renderer'] ?? '') !== 'furniture-v1')
    @include('pdf.furniture-quotation-v3')
@elseif (($s['furniture_revision'] ?? 1) >= 2)
    @include('pdf.furniture-document-v2')
@else
    @include('pdf.furniture-document-v1')
@endif
