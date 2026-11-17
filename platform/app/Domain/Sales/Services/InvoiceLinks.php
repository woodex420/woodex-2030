<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Models\Invoice;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * The client's invoice link (INV-005/006): a relative signed URL, no login, that shows the invoice, its PDF, the
 * bank details and a form to report a payment. It stays open until the invoice is settled, and 90 days after that.
 */
final class InvoiceLinks
{
    public static function expiresAt(Invoice $invoice): CarbonImmutable
    {
        return CarbonImmutable::now()->addDays(90);
    }

    public static function portal(Invoice $invoice): string
    {
        return url(self::signed('invoices.portal', $invoice));
    }

    public static function signed(string $route, Invoice $invoice): string
    {
        return URL::temporarySignedRoute($route, self::expiresAt($invoice), ['invoice' => $invoice->public_id], absolute: false);
    }

    /** "Share on WhatsApp" with a ready message (click-to-chat; the API arrives in P4). */
    public static function whatsappShare(Invoice $invoice): ?string
    {
        $text = "Assalam o Alaikum {$invoice->contact->name}, here is invoice {$invoice->number} from Woodex for "
            .$invoice->total()->format().". You can see it, download the PDF and report a payment here:\n".self::portal($invoice);

        return Phone::whatsappUrl($invoice->contact->whatsappNumber(), $text);
    }
}
