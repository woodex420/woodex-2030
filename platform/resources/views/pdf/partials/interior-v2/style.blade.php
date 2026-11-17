@php
    // Geometry and palettes from v2.1 admin-print17.js. Tables replace grid/flex only for dompdf support.
    $theme = \App\Domain\Sales\Services\DocumentStyles::theme($design['pdf_theme'] ?? null);
    $ink = match ($theme) { 'minimal' => '#111111', 'premium' => '#2b2118', default => '#0e1726' };
    // v2.1 designs own their palette; the old OS furniture-green accent must not recolour Classic BOQ.
    $gold = $theme === 'minimal' ? '#111111' : '#b48256';
    $g2 = $theme === 'minimal' ? '#4b5563' : '#8a6a40';
    $line = $theme === 'premium' ? '#e8dfd1' : ($theme === 'minimal' ? '#e5e7eb' : '#e3e1dc');
    $box = match ($theme) { 'minimal' => '#ffffff', 'premium' => '#fbf8f3', default => '#fcfbf9' };
@endphp
<style>
    /* theme: {{ $theme }} */
    @foreach (['Jakarta' => 'plus-jakarta-sans', 'Grotesk' => 'space-grotesk'] as $family => $file)
    @foreach ([400, 500, 700] as $weight)
    @font-face { font-family: '{{ $family }}'; font-style: normal; font-weight: {{ $weight }}; src: url('{{ !empty($preview) ? 'data:font/ttf;base64,'.base64_encode(file_get_contents(public_path('fonts/documents/'.$file.'-'.$weight.'.ttf'))) : public_path('fonts/documents/'.$file.'-'.$weight.'.ttf') }}') format('truetype'); }
    @endforeach
    @endforeach
    @page { margin: 155px 42px 75px; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Jakarta', 'DejaVu Sans', sans-serif; font-size: 8pt; line-height: 1.45; color: {{ $ink }}; }
    table { width: 100%; border-collapse: collapse; }
    .right { text-align: right; } .center { text-align: center; } .muted { color: #6b7280; }
    header { margin-bottom: 5mm; padding-bottom: 0; }
    .header-accent { width: 100%; height: 1.5px; margin-top: 4mm; background: {{ $ink }}; }
    .header-gold { width: 34mm; height: 1.5px; background: {{ $gold }}; }
    .meta td { vertical-align: top; padding: 0; }
    .brand { font-size: 15pt; letter-spacing: .2em; line-height: 1.1; font-weight: 700; }
    .tagline { font-size: 6.6pt; letter-spacing: .18em; color: {{ $g2 }}; margin-top: 1.4mm; }
    .document-logo { max-height: 13mm; max-width: 55mm; margin-bottom: 2mm; }
    .doctitle { font-size: 21pt; letter-spacing: .14em; font-weight: 700; line-height: 1; }
    .gold { color: {{ $gold }}; }
    .docmeta { margin-top: 2.4mm; font-size: 8.6pt; }
    .docmeta td { padding: .3mm 0 .3mm 4mm; text-align: right; }
    .contact-line { font-size: 7.4pt; color: #6b7280; margin-top: 2mm; line-height: 1.5; }
    .box { border: 1px solid {{ $line }}; border-radius: 2.4mm; padding: 3.4mm 4mm; background: {{ $box }}; margin-bottom: 5mm; font-size: 8.6pt; }
    .boxlabel { font-size: 6.8pt; letter-spacing: .16em; text-transform: uppercase; color: {{ $g2 }}; font-weight: 700; margin-bottom: 1.2mm; }
    .box b { font-size: 10.5pt; }
    h2, h3 { font-size: 8.6pt; letter-spacing: .16em; text-transform: uppercase; color: {{ $g2 }}; margin: 6mm 0 2.4mm; page-break-after: avoid; }
    .secthead { margin: 3mm 0 2.4mm; font-size: 8.6pt; letter-spacing: .16em; text-transform: uppercase; color: {{ $g2 }}; page-break-after: avoid; }
    .secthead .note { letter-spacing: normal; text-transform: none; color: #6b7280; }
    .lines thead { display: table-header-group; }
    .lines tr { page-break-inside: avoid; }
    .lines th { background: {{ $theme === 'minimal' ? '#ffffff' : $ink }}; color: {{ $theme === 'minimal' ? '#111111' : ($theme === 'premium' ? '#f4efe7' : '#ffffff') }}; border-bottom: 1.5px solid {{ $ink }}; font-weight: 500; font-size: 7.4pt; letter-spacing: .08em; text-transform: uppercase; padding: 2.3mm 2.2mm; text-align: left; }
    .lines th.right { text-align: right; }
    .lines td { padding: 2mm 2.2mm; border-bottom: 1px solid {{ $line }}; vertical-align: top; font-size: 7.7pt; }
    .lines .section td { background: {{ $theme === 'premium' ? '#efe6d8' : ($theme === 'minimal' ? '#f3f4f6' : '#eceae5') }}; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
    .lines .subtotal td { font-weight: 700; border-bottom: 1.5px solid {{ $ink }}; }
    .optional td { color: #6b7280; font-style: italic; }
    .wordsbox { border: 1px dashed {{ $gold }}; border-radius: 2mm; padding: 3mm 3.5mm; font-size: 8.6pt; background: #fffdf9; }
    .totals td { padding: 1.3mm 3mm; font-size: 9pt; border-bottom: 1px solid {{ $line }}; }
    .pill td { background: {{ $ink }}; color: #ffffff; font-size: 11.5pt; font-weight: 700; padding: 2.8mm 3mm; }
    .pill .pill-amount { color: {{ $theme === 'minimal' ? '#ffffff' : '#e6cfa5' }}; text-align: right; }
    .scope { font-size: 7.7pt; } .scope p { margin: 0 0 1.2mm; } .scope ul { padding-left: 4.5mm; }
    .terms { padding-left: 4.5mm; font-size: 8.6pt; } .terms li { margin-bottom: 1.1mm; }
    .sign td { padding-top: 12mm; vertical-align: bottom; } .sign .line { border-top: 1px solid {{ $ink }}; padding-top: 2mm; width: 90%; }
    .paylabel { color: #6b7280; width: 34%; vertical-align: top; }
    .avoid { page-break-inside: avoid; }
    .trade-page { page-break-before: always; } .trade-title { font-size: 20pt; border-bottom: 2px solid {{ $ink }}; padding-bottom: 3mm; margin: 0 0 4mm; }
    .watermark { position: fixed; top: 34%; width: 100%; text-align: center; font-size: 90pt; color: #e3e1dc; transform: rotate(-30deg); z-index: -1; }
    footer { position: fixed; bottom: -16mm; left: 0; right: 0; font-size: 7pt; color: #6b7280; }
    footer td { padding: 3mm 0; border-top: 1px solid {{ $line }}; }
    .footer-bar { height: 3mm; background: {{ $ink }}; border-right: 34mm solid {{ $gold }}; }
    @if (!empty($preview))
    html { background: #e9e7e2; }
    body { position: relative; width: 210mm; min-height: 297mm; margin: 8mm auto; padding: 155px 42px 75px; background: #fff; box-shadow: 0 8px 34px rgba(12,22,40,.16); }
    footer { position: absolute; bottom: 14px; left: 42px; right: 42px; margin: 0; }
    footer table { width: 100%; margin: 0; }
    .trade-page { margin-top: 14mm; border-top: 8mm solid #e9e7e2; padding-top: 11mm; }
    @endif

    header.v21-header { position: fixed; top: -115px; left: 0; right: 0; margin: 0; height: 97px; }
    .brand-grid td { vertical-align: middle; }
    .mark-cell { width: 43px; padding-right: 10px; }
    .document-logo { width: 33px; height: auto; max-width: 33px; max-height: 38px; margin: 0; }
    .brand { font-family: 'Grotesk', sans-serif; font-size: 14pt; letter-spacing: .12em; }
    .brand-identity { border-left: 1px solid #d6d3d1; padding-left: 10px !important; }
    .tagline { font-size: 6pt; letter-spacing: .1em; }
    .doctitle { font-family: 'Grotesk', sans-serif; font-size: 21pt; letter-spacing: -.05em; }
    .docmeta { margin-top: 5px; font-size: 7.3pt; }
    .docmeta td { padding: 0 0 1px 8px; line-height: 1.35; }
    .brand-suffix { font-size: 7.4pt; color: {{ $gold }}; letter-spacing: .14em; }
    .client-cards { margin-bottom: 2mm; }
    .client-cards .card { background: #fafaf9; border: 1px solid {{ $line }}; border-radius: 2mm; padding: 9px 12px; vertical-align: top; font-size: 7.4pt; line-height: 1.5; }
    .header-accent { margin-top: 5px; height: 1px; background: #e7e5e4; }
    .header-gold { background: {{ $gold }}; height: 2px; width: 24mm; }
    .box { padding: 3mm; border-radius: 2mm; background: #fafaf9; font-size: 7.4pt; margin-bottom: 4mm; min-height: 24mm; }
    .boxlabel { font-size: 6.6pt; letter-spacing: .04em; color: {{ $gold }}; }
    h2, h3 { font-size: 7.6pt; margin: 3mm 0 2mm; letter-spacing: .14em; color: {{ $gold }}; }
    .lines th { font-size: 6.6pt; padding: 1.5mm; letter-spacing: 0; font-weight: 700; }
    .lines td { font-size: 7.5pt; padding: .85mm 2mm; line-height: 1.35; }
    .lines .section td { padding: 1mm 2mm; font-size: 6.5pt; background: #fafaf9; }
    .lines .subtotal td { background: #fafaf9; border-bottom: 1px solid {{ $line }}; }
    .phase { text-align: center; color: #78716c; font-size: 7pt; }
    .pill td { padding: 3mm; font-size: 10pt; }
    .pill .pill-amount { color: white; }
    .wordsbox { border: 0; padding: 2mm 3mm 2mm 0; background: white; font-size: 7.2pt; }
    .wordsbox .boxlabel { color: #78716c; }
    .sign td { padding-top: 6mm; font-size: 7.4pt; }
    .terms { font-size: 7.6pt; }
    footer { bottom: -57px; font-size: 6.5pt; }
    footer td { padding: 7px 0; }
    .footer-bar { height: 2.5mm; background: {{ $ink }}; border-right: 34mm solid {{ $gold }}; }
    @if (!empty($preview))
    header.v21-header { position: absolute; top: 40px; left: 42px; right: 42px; }
    footer { position: absolute; bottom: 14px; left: 42px; right: 42px; }
    @endif
</style>
