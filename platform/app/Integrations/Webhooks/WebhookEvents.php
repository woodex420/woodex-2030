<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

/** The moments an endpoint can subscribe to — the same list the hub's checkboxes offer. */
final class WebhookEvents
{
    public const string LEAD_CREATED = 'lead.created';

    public const string QUOTE_SENT = 'quote.sent';

    public const string INVOICE_PAID = 'invoice.paid';

    public const string TEST = 'webhook.test';

    /** @return array<string, string> event => label */
    public static function options(): array
    {
        return [
            self::LEAD_CREATED => 'Lead created',
            self::QUOTE_SENT => 'Quotation sent',
            self::INVOICE_PAID => 'Invoice paid in full',
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::options());
    }
}
