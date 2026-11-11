@php
    $total = \App\Support\Money::ofMinor((int) $s['totals']['total_minor'])->format(app(\App\Settings\SalesSettings::class)->lakh_grouping);
    $valid = $s['valid_until'] ? \Carbon\CarbonImmutable::parse($s['valid_until'])->format('j M Y') : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<body style="margin:0;background:#f5f6f8;font:15px/1.6 Arial,Helvetica,sans-serif;color:#1a2a45">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6f8;padding:24px 0">
    <tr><td align="center">
        <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden">
            <tr><td style="background:#0f1e36;color:#ffffff;padding:20px 28px;font-size:18px;letter-spacing:3px;font-weight:bold">{{ strtoupper($s['company']['brand']) }}</td></tr>
            <tr><td style="padding:28px">
                <p style="margin:0 0 12px">Dear {{ $s['client']['name'] }},</p>
                <p style="margin:0 0 12px">Thank you for considering {{ $s['company']['brand'] }}. Your quotation <strong>{{ $s['label'] }}</strong> for “{{ $s['title'] }}” is ready: <strong>{{ $total }}</strong>@if ($valid), valid until {{ $valid }}@endif.</p>
                <p style="margin:0 0 20px">Open it to see every line, download the PDF (also attached) and accept it online.</p>
                <p style="margin:0 0 24px"><a href="{{ $link }}" style="display:inline-block;background:#0f1e36;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px">View your quotation</a></p>
                <p style="margin:0;color:#6e87ac;font-size:13px">Questions? Reply to this email or WhatsApp us at {{ $s['company']['whatsapp'] }}.</p>
            </td></tr>
            <tr><td style="padding:16px 28px;background:#fcf2e8;color:#6e87ac;font-size:12px">{{ $s['company']['legal_name'] }} · {{ $s['company']['address'] }} · {{ $s['company']['website'] }}</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
