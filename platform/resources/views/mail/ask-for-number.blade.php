@php
    $brand = strtoupper($business->brand_name);
    $name = $lead->contact->name;
    $first = explode(' ', trim($name))[0] ?? $name;
@endphp
<!DOCTYPE html>
<html lang="en">
<body style="margin:0;background:#f5f6f8;font:15px/1.6 Arial,Helvetica,sans-serif;color:#1a2a45">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6f8;padding:24px 0">
    <tr><td align="center">
        <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden">
            <tr><td style="background:#0f1e36;color:#ffffff;padding:20px 28px;font-size:18px;letter-spacing:3px;font-weight:bold">{{ $brand }}</td></tr>
            <tr><td style="padding:28px">
                <p style="margin:0 0 12px">Dear {{ $first }},</p>

                <p style="margin:0 0 12px">Thank you for your enquiry<strong>{{ $lead->service ? ' about '.\Illuminate\Support\Str::headline($lead->service) : '' }}</strong> — it is with our team as reference <strong>{{ $lead->ref }}</strong>.</p>

                <p style="margin:0 0 12px">One quick thing: we could not read a phone number in your message, and our team works fastest on WhatsApp.</p>

                <p style="margin:0 0 20px;padding:12px 16px;background:#fcf2e8;border-radius:8px">Please reply to this email with your WhatsApp number (or the number you prefer we call), and a designer will be in touch.</p>

                <p style="margin:0;color:#6e87ac;font-size:13px">Questions? Reply to this email or WhatsApp us at {{ $business->whatsapp }}.</p>
            </td></tr>
            <tr><td style="padding:16px 28px;background:#fcf2e8;color:#6e87ac;font-size:12px">{{ $business->legal_name }} · {{ $business->address }} · {{ $business->website }}</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
