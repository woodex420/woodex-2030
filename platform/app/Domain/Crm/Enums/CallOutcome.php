<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

use App\Support\Concerns\EnumHelpers;

/**
 * Result of a phone call (CRM-016).
 */
enum CallOutcome: string
{
    use EnumHelpers;

    case Connected = 'connected';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case SwitchedOff = 'switched_off';
    case WrongNumber = 'wrong_number';
    case CallBack = 'call_back';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::NoAnswer => 'No answer',
            self::Busy => 'Busy',
            self::SwitchedOff => 'Switched off',
            self::WrongNumber => 'Wrong number',
            self::CallBack => 'Asked to call back',
        };
    }

    /**
     * Did we actually speak to the client?
     */
    public function reached(): bool
    {
        return in_array($this, [self::Connected, self::CallBack], true);
    }
}
