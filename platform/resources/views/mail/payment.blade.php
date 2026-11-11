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
                <p style="margin:0 0 12px">Dear {{ $payment->contact->name }},</p>

                @if ($kind === 'verified')
                    <p style="margin:0 0 12px">Thank you. We have received <strong>{{ $received }}</strong>
                        @if ($payment->reference)(reference {{ $payment->reference }})@endif
                        on {{ $payment->received_on?->format('j M Y') }} and it is now against your account.</p>
                    <p style="margin:0 0 20px">Your receipt <strong>{{ $payment->receipt_number }}</strong> is attached to this email.</p>
                @else
                    <p style="margin:0 0 12px">Thank you for sending the details of your payment of <strong>{{ $received }}</strong>. We could not match it in our account yet:</p>
                    <p style="margin:0 0 20px;padding:12px 16px;background:#fcf2e8;border-radius:8px">{{ $payment->rejected_reason }}</p>
                    <p style="margin:0 0 20px">Please check the details and send the proof again — a screenshot or a bank slip with the date and the reference is enough.</p>
                @endif

                <p style="margin:0;color:#6e87ac;font-size:13px">Questions? Reply to this email or WhatsApp us at {{ $business->whatsapp }}.</p>
            </td></tr>
            <tr><td style="padding:16px 28px;background:#fcf2e8;color:#6e87ac;font-size:12px">{{ $business->legal_name }} · {{ $business->address }} · {{ $business->website }}</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
