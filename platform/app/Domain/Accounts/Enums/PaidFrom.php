<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Enums;

use App\Support\Concerns\EnumHelpers;

/**
 * The account the money actually moved through. `Payable` means nothing has left yet and the amount sits in
 * Accounts payable (2010) until a bill payment clears it.
 */
enum PaidFrom: string
{
    use EnumHelpers;

    case Cash = 'cash';
    case Bank = 'bank';
    case Wallet = 'wallet';
    case Payable = 'payable';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash in hand',
            self::Bank => 'Bank account',
            self::Wallet => 'Mobile wallet',
            self::Payable => 'On credit (accounts payable)',
        };
    }

    /** The chart code that receives the other side of the entry. */
    public function accountCode(): string
    {
        return match ($this) {
            self::Cash => '1010',
            self::Bank => '1020',
            self::Wallet => '1040',
            self::Payable => '2010',
        };
    }
}
