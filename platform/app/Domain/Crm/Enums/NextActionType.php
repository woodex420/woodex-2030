<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

use App\Support\Concerns\EnumHelpers;
use Illuminate\Support\Str;

enum NextActionType: string
{
    use EnumHelpers;

    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Visit = 'visit';
    case Meeting = 'meeting';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::Visit => 'Site visit',
            default => Str::headline($this->name),
        };
    }
}
