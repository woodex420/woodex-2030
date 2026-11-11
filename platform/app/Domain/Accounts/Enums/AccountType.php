<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    /** @return list<self> */
    public static function balanced(): array
    {
        return [self::Asset, self::Liability, self::Equity, self::Income, self::Expense];
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Assets',
            self::Liability => 'Liabilities',
            self::Equity => 'Equity',
            self::Income => 'Income',
            self::Expense => 'Expenses',
        };
    }
}
