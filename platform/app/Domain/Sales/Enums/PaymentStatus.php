<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

use App\Support\Concerns\EnumHelpers;

enum PaymentStatus: string
{
    use EnumHelpers;

    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Refunded = 'refunded';

    public function color(): string
    {
        return match ($this) {
            self::PendingVerification => 'info',
            self::Verified => 'success',
            self::Rejected, self::Refunded => 'error',
        };
    }
}
