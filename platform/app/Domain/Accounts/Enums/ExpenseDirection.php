<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Enums;

use App\Support\Concerns\EnumHelpers;

/** Which way the money moved (slice I): out of the studio (an expense) or in (income that is not a client invoice). */
enum ExpenseDirection: string
{
    use EnumHelpers;

    case Out = 'out';
    case In = 'in';

    public function label(): string
    {
        return match ($this) {
            self::Out => 'Expense',
            self::In => 'Income',
        };
    }

    /** The account type this direction may be booked against. */
    public function accountType(): AccountType
    {
        return $this === self::Out ? AccountType::Expense : AccountType::Income;
    }
}
