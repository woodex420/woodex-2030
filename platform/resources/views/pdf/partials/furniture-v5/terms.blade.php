@if ($terms)
<section class="terms">
    <h3>Terms and conditions</h3>
    {{-- Flowing definition rows can split an oversized custom condition; dompdf cannot split table cells. --}}
    <dl class="commercial-terms">
        @foreach ($terms as [$label, $value])
            <div class="term-row"><dt class="term-label">{{ $label }}</dt><dd class="term-value">{{ $value }}</dd></div>
        @endforeach
    </dl>
</section>
@endif
