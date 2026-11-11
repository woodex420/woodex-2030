<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Enums;

use App\Support\Concerns\EnumHelpers;
use Illuminate\Support\Str;

/**
 * Where a received invoice stands (slice I). A bill is entered as a draft, posted to the ledger when it is
 * received, and moves to part-paid / paid as money leaves the bank. Void keeps the document and reverses its
 * journal — nothing is ever deleted.
 */
enum BillStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case Received = 'received';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Received => 'info',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Void => 'error',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PartiallyPaid => 'Part paid',
            default => Str::headline($this->name),
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Received, self::PartiallyPaid], true);
    }
}
