@if (($s['quotation_renderer'] ?? null) === 'v21' && ($s['quote_layout'] ?? '') === 'furniture')
    @include('pdf.furniture-document')
@elseif (($s['quotation_renderer'] ?? null) === 'v21' && ($s['business_line'] ?? '') !== 'furniture' && ($s['quote_layout'] ?? 'classic') === 'classic' && ($s['interior_revision'] ?? 1) >= 3)
    @include('pdf.interior-quotation-v3')
@elseif (($s['quotation_renderer'] ?? null) === 'v21' && ($s['business_line'] ?? '') !== 'furniture' && ($s['quote_layout'] ?? 'classic') === 'classic' && ($s['interior_revision'] ?? 1) >= 2)
    @include('pdf.interior-quotation-v2')
@elseif (($s['quotation_renderer'] ?? null) === 'v21')
    @include('pdf.quote-v21')
@else
    @include('pdf.quote-legacy')
@endif
