@php
    // Geometry and palettes from v2.1 admin-print17.js. Tables replace grid/flex only for dompdf support.
    $theme = \App\Domain\Sales\Services\DocumentStyles::theme($design['pdf_theme'] ?? null);
    $ink = match ($theme) { 'minimal' => '#111111', 'premium' => '#2b2118', default => '#0c1628' };
    // v2.1 designs own their palette; the old OS furniture-green accent must not recolour Classic BOQ.
    $gold = $theme === 'minimal' ? '#111111' : '#b8956a';
    $g2 = $theme === 'minimal' ? '#4b5563' : '#8a6a40';
    $line = $theme === 'premium' ? '#e8dfd1' : ($theme === 'minimal' ? '#e5e7eb' : '#e3e1dc');
    $box = match ($theme) { 'minimal' => '#ffffff', 'premium' => '#fbf8f3', default => '#fcfbf9' };
@endphp
<style>
    /* theme: {{ $theme }} */
    @foreach ([400, 500, 700] as $weight)
    @font-face { font-family: 'DM Sans'; font-style: normal; font-weight: {{ $weight }}; src: url('{{ !empty($preview) ? 'data:font/ttf;base64,'.base64_encode(file_get_contents(public_path('fonts/documents/dm-sans-'.$weight.'.ttf'))) : public_path('fonts/documents/dm-sans-'.$weight.'.ttf') }}') format('truetype'); }
    @endforeach
    @page { margin: 11mm 12mm 21mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'DM Sans', 'DejaVu Sans', sans-serif; font-size: 9.4pt; line-height: 1.45; color: {{ $ink }}; }
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
    .lines td { padding: 2mm 2.2mm; border-bottom: 1px solid {{ $line }}; vertical-align: top; font-size: 8.8pt; }
    .lines .section td { background: {{ $theme === 'premium' ? '#efe6d8' : ($theme === 'minimal' ? '#f3f4f6' : '#eceae5') }}; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
    .lines .subtotal td { font-weight: 700; border-bottom: 1.5px solid {{ $ink }}; }
    .optional td { color: #6b7280; font-style: italic; }
    .wordsbox { border: 1px dashed {{ $gold }}; border-radius: 2mm; padding: 3mm 3.5mm; font-size: 8.6pt; background: #fffdf9; }
    .totals td { padding: 1.3mm 3mm; font-size: 9pt; border-bottom: 1px solid {{ $line }}; }
    .pill td { background: {{ $ink }}; color: #ffffff; font-size: 11.5pt; font-weight: 700; padding: 2.8mm 3mm; }
    .pill .pill-amount { color: {{ $theme === 'minimal' ? '#ffffff' : '#e6cfa5' }}; text-align: right; }
    .scope { font-size: 8.8pt; } .scope p { margin: 0 0 1.2mm; } .scope ul { padding-left: 4.5mm; }
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
    body { width: 210mm; min-height: 297mm; margin: 8mm auto; padding: 11mm 12mm 14mm; background: #fff; box-shadow: 0 8px 34px rgba(12,22,40,.16); }
    footer { position: static; margin: 10mm -12mm -14mm; }
    footer table { width: calc(100% - 24mm); margin: 0 12mm; }
    .trade-page { margin-top: 14mm; border-top: 8mm solid #e9e7e2; padding-top: 11mm; }
    @endif
</style>
