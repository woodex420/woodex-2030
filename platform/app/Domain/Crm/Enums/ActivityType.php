<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

use App\Support\Concerns\EnumHelpers;

enum ActivityType: string
{
    use EnumHelpers;

    case Note = 'note';
    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Meeting = 'meeting';
    case Visit = 'visit';
    case StageChange = 'stage_change';
    case Assignment = 'assignment';
    case QuoteEvent = 'quote_event';
    case InvoiceEvent = 'invoice_event';
    case Payment = 'payment';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Note => 'Note',
            self::Call => 'Call',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Meeting => 'Meeting',
            self::Visit => 'Site visit',
            self::StageChange => 'Stage change',
            self::Assignment => 'Assignment',
            self::QuoteEvent => 'Quotation',
            self::InvoiceEvent => 'Invoice',
            self::Payment => 'Payment',
            self::System => 'System',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Note => 'note',
            self::Call => 'phone',
            self::WhatsApp => 'whatsapp',
            self::Email => 'mail',
            self::Meeting => 'users',
            self::Visit => 'map-pin',
            self::StageChange => 'kanban',
            self::Assignment => 'user-check',
            self::QuoteEvent => 'quote',
            self::InvoiceEvent => 'invoice',
            self::Payment => 'wallet',
            self::System => 'sparkles',
        };
    }

    /**
     * Timeline dot colour (Tailwind classes).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Call, self::Meeting => 'bg-navy-50 text-navy-700 dark:bg-white/10 dark:text-white',
            self::WhatsApp => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500',
            self::Email => 'bg-info-50 text-info-700 dark:bg-info-500/15 dark:text-info-500',
            self::Visit => 'bg-wood-50 text-wood-700 dark:bg-wood-500/15 dark:text-wood-300',
            self::Payment, self::InvoiceEvent, self::QuoteEvent => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-500',
            default => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400',
        };
    }
}
