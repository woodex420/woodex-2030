<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

use App\Support\Concerns\EnumHelpers;

/**
 * The moments the system wants to write to a client without a human typing (WAP-005).
 *
 * A template is mapped to one of these, so the code never holds a template name: it asks "what do we send when a
 * quotation is ready?" and the studio answers by pointing at an approved template. Nothing is hard-coded, and
 * changing the wording is a change in Meta plus a sync — not a deploy.
 *
 * Only `quotation` sends anything today. The others exist so the sequences in AUT-001 have somewhere to point.
 */
enum WhatsAppTemplateEvent: string
{
    use EnumHelpers;

    case Quotation = 'quotation';
    case Enquiry = 'enquiry';
    case VisitReminder = 'visit_reminder';
    case Invoice = 'invoice';
    case PaymentReminder = 'payment_reminder';
    case FollowUp = 'follow_up';

    public function label(): string
    {
        return match ($this) {
            self::Quotation => 'Quotation ready',
            self::Enquiry => 'New enquiry received',
            self::VisitReminder => 'Site visit reminder',
            self::Invoice => 'Invoice ready',
            self::PaymentReminder => 'Payment reminder',
            self::FollowUp => 'Follow-up',
        };
    }

    /** Shown under the mapping dropdown: what the studio should put in the template. */
    public function hint(): string
    {
        return match ($this) {
            self::Quotation => '{{1}} is the client name, {{2}} the quotation link.',
            self::Enquiry => '{{1}} is the client name, {{2}} the service they asked about.',
            self::VisitReminder => '{{1}} is the client name, {{2}} the visit date and time.',
            self::Invoice => '{{1}} is the client name, {{2}} the invoice link.',
            self::PaymentReminder => '{{1}} is the client name, {{2}} the amount due.',
            self::FollowUp => '{{1}} is the client name.',
        };
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
