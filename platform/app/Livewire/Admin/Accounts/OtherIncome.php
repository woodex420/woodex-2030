<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Accounts;

use App\Domain\Accounts\Enums\ExpenseDirection;
use Livewire\Attributes\Title;

/**
 * Income that is not a client invoice (slice I): scrap sales, interest, a supplier refund. Same screen as
 * Expenses with the direction flipped — Dr cash/bank, Cr the income account — so there is one posting path and
 * one place for a bug to hide.
 */
#[Title('Other income')]
final class OtherIncome extends ExpensesIndex
{
    protected function direction(): ExpenseDirection
    {
        return ExpenseDirection::In;
    }
}
