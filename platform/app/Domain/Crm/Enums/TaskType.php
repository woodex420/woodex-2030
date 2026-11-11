<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

use App\Support\Concerns\EnumHelpers;

enum TaskType: string
{
    use EnumHelpers;

    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Visit = 'visit';
    case Meeting = 'meeting';
    case Email = 'email';
    case Todo = 'todo';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::WhatsApp => 'WhatsApp',
            self::Visit => 'Site visit',
            self::Meeting => 'Meeting',
            self::Email => 'Email',
            self::Todo => 'To-do',
        };
    }
}
