@php
    $design = $s['document_design'] ?? null;
    $accent = is_array($design) && preg_match('/^#[0-9a-fA-F]{6}$/', $design['accent_color'] ?? '') ? $design['accent_color'] : null;
    $theme = \App\Domain\Sales\Services\DocumentStyles::theme(is_array($design) ? ($design['pdf_theme'] ?? null) : null);
    $themeAccent = $accent ?: '#0c1628';
    $premiumInk = $accent && strtolower($accent) !== '#0c1628' ? $accent : '#2b2118';
@endphp
@if ($accent)
<style>
    .brand { font-size: 14px; letter-spacing: .4px; line-height: 1.05; word-wrap: break-word; }
    .tagline { font-size: 7px; line-height: 1.2; word-wrap: break-word; }
    header { border-bottom-color: {{ $accent }}; }
    .brand, h1, h2 { color: {{ $accent }}; }
    .lines th, .totals .grand td, .pill td { background: {{ $accent }}; }
    .document-logo { max-width: 150px; max-height: 22px; margin-bottom: 3px; }
    .document-footer { font-size: 7px; }
</style>
@endif
@if ($theme === 'minimal')
<style>
    /* theme: minimal */
    .brand, h1, h2 { color: #111111; }
    header { border-bottom: 1px solid #111111; }
    .lines th { background: #ffffff; color: #111111; border-bottom: 1.5px solid #111111; }
    .lines .section td { background: #f3f4f6; color: #111111; border-bottom: 1px solid #e5e7eb; }
    .totals .grand td { background: #111111; color: #ffffff; }
    .box { background: #ffffff; border: 1px solid #e5e7eb; }
    .muted { color: #6b7280; }
    .doctitle, .doctitle .gold, .secthead { color: #111111; }
    .boxlabel { color: #4b5563; }
    .goldrule { border-top-color: #111111; }
    .wordsbox { border-color: #111111; }
    .pill td { background: #111111; color: #ffffff; }
    .pill .pill-amount { color: #ffffff; }
</style>
@elseif ($theme === 'premium')
<style>
    /* theme: premium */
    body { color: {{ $premiumInk }}; }
    header { border-bottom-color: {{ $premiumInk }}; }
    .brand, h1, h2, .doctitle { color: {{ $premiumInk }}; }
    .lines th { background: {{ $premiumInk }}; color: #f4efe7; }
    .lines .section td { background: #efe6d8; color: {{ $premiumInk }}; }
    .lines td { border-bottom-color: #e8dfd1; }
    .box { background: #fbf8f3; border-color: #e8dfd1; }
    .totals .grand td { background: {{ $premiumInk }}; color: #f4efe7; }
    .pill td { background: {{ $premiumInk }}; color: #f4efe7; }
</style>
@endif
