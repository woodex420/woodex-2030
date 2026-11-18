<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

use App\Support\Concerns\EnumHelpers;

enum QuoteStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case ChangesRequested = 'changes_requested';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Superseded = 'superseded';
    case Void = 'void';

    /**
     * Status colour per docs/05 §4.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft, self::Superseded => 'gray',
            self::Approved, self::Sent, self::Viewed => 'info',
            self::PendingApproval, self::ChangesRequested => 'warning',
            self::Accepted => 'success',
            self::Declined, self::Expired, self::Void => 'error',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Sent, self::Viewed, self::ChangesRequested], true);
    }
}
