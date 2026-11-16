@if ($s['terms'] !== [])
    <div class="avoid">
        <h2>Terms &amp; conditions</h2>
        <ol class="terms" style="margin: 0 0 0 14px; padding: 0;">
            @foreach ($s['terms'] as $term)<li>{{ $term }}</li>@endforeach
        </ol>
    </div>
@endif
