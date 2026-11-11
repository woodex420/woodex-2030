<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

use App\Support\Concerns\EnumHelpers;
use Illuminate\Support\Str;

enum PaymentMethod: string
{
    use EnumHelpers;

    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Cheque = 'cheque';
    case JazzCash = 'jazzcash';
    case Easypaisa = 'easypaisa';
    case Card = 'card';
    case Gateway = 'gateway';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::JazzCash => 'JazzCash',
            self::Easypaisa => 'Easypaisa',
            default => Str::headline($this->name),
        };
    }
}
