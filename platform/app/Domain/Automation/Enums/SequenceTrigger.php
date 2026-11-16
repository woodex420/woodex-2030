<?php

declare(strict_types=1);

namespace App\Domain\Automation\Enums;

use App\Support\Concerns\EnumHelpers;

/**
 * What puts a lead into a sequence (AUT-001). The moments the studio actually works in — an
 * enquiry arriving, a quotation going out (and being seen), an invoice raised, a visit done,
 * a lead lost. `manual` is for enrolling by hand from the screen.
 */
enum SequenceTrigger: string
{
    use EnumHelpers;

    case LeadCreated = 'lead_created';
    case QuotationSent = 'quotation_sent';
    case QuotationViewed = 'quotation_viewed';
    case InvoiceIssued = 'invoice_issued';
    case VisitCompleted = 'visit_completed';
    case LeadLost = 'lead_lost';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::LeadCreated => 'New lead captured',
            self::QuotationSent => 'Quotation sent',
            self::QuotationViewed => 'Quotation viewed',
            self::InvoiceIssued => 'Invoice issued',
            self::VisitCompleted => 'Site visit completed',
            self::LeadLost => 'Lead lost',
            self::Manual => 'By hand',
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
