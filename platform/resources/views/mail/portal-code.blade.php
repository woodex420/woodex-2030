@php
    $brand = strtoupper($business->brand_name);
@endphp
<!DOCTYPE html>
<html lang="en">
<body style="margin:0;background:#f5f6f8;font:15px/1.6 Arial,Helvetica,sans-serif;color:#1a2a45">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6f8;padding:24px 0">
    <tr><td align="center">
        <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden">
            <tr><td style="background:#0f1e36;color:#ffffff;padding:20px 28px;font-size:18px;letter-spacing:3px;font-weight:bold">{{ $brand }}</td></tr>
            <tr><td style="padding:28px">
                <p style="margin:0 0 12px">Dear {{ $name }},</p>

                <p style="margin:0 0 20px">Here is the code to open your project portal:</p>

                <p style="margin:0 0 20px;font-size:30px;letter-spacing:8px;font-weight:bold;color:#0f1e36">{{ $code }}</p>

                <p style="margin:0 0 20px;color:#6e87ac;font-size:13px">The code works once and expires in 15 minutes. If you did not ask for it, no one can use it without your email.</p>

                <p style="margin:0;color:#6e87ac;font-size:13px">Questions? Reply to this email or WhatsApp us at {{ $business->whatsapp }}.</p>
            </td></tr>
            <tr><td style="padding:16px 28px;background:#fcf2e8;color:#6e87ac;font-size:12px">{{ $business->legal_name }} · {{ $business->address }} · {{ $business->website }}</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
