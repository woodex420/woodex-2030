@if($placement === 'top')
<header class="hero {{ $content['hero_template'] }}">
    <div><h1>{{ $content['hero_heading'] }}</h1><p class="text">{{ $content['hero_text'] }}</p>@if($content['cta_path'])<a class="cta" href="{{ $content['cta_path'] }}" target="_blank" rel="noopener noreferrer">{{ $content['cta_label'] }}</a>@endif</div>
    @if(($content['hero_url'] ?? null))<img src="{{ $content['hero_url'] }}" alt="{{ $content['hero_alt'] }}">@endif
</header>
@if($content['intro_heading'] || $content['intro_text'])<section><h2>{{ $content['intro_heading'] }}</h2><div class="formatted-content">{!! app(\App\Domain\Commerce\Services\CategoryContent::class)->formattedText($content['intro_text'] ?? '', $content['text_format'] ?? 'plain') !!}</div></section>@endif
@else
@if($content['bottom_heading'] || $content['bottom_text'])<section><h2>{{ $content['bottom_heading'] }}</h2><div class="formatted-content">{!! app(\App\Domain\Commerce\Services\CategoryContent::class)->formattedText($content['bottom_text'] ?? '', $content['text_format'] ?? 'plain') !!}</div></section>@endif
@if(count($content['faqs']))<section><h2>Frequently asked questions</h2>@foreach($content['faqs'] as $faq)<details><summary>{{ $faq['question'] }}</summary><p class="text">{{ $faq['answer'] }}</p></details>@endforeach</section>@endif
@endif
