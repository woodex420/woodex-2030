<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * P2 part 2b: what happens to the advance invoice the moment a quotation is accepted (QUO-011, INV-001/004).
 *
 * The default is to issue it (the number, the PDF and the bank details are ready) but not to send it: the owner
 * checks it first and sends it with the thank-you message. "Send" is for when they trust the numbers completely.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('sales.advance_invoice_mode', 'issue');

        $this->migrator->add('sales.invoice_terms', implode("\n", [
            'Payment is due within the days stated on this invoice.',
            'Please share the payment proof on WhatsApp or by replying to the email that carried this invoice.',
            'Work begins after the advance is received and confirmed.',
        ]));
    }
};
