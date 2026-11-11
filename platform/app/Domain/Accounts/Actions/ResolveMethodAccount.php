<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\PaymentMethodAccount;
use App\Domain\Sales\Enums\PaymentMethod;

/**
 * The ledger account a method's money lands in (P8.2). Mapped on Accounts → Payment methods;
 * an unmapped method is a hard stop, not a guess — nothing posts to a suspense account silently.
 */
final class ResolveMethodAccount
{
    public function handle(PaymentMethod $method): Account
    {
        $mapping = PaymentMethodAccount::query()->where('method', $method->value)->first();

        if ($mapping === null) {
            throw new JournalPostingFailed('Payment method "'.$method->label().'" is not mapped to a ledger account yet. Map it in Accounts → Payment methods, then verify the payment again.');
        }

        $account = Account::query()->whereKey($mapping->account_id)->where('is_active', true)->first();

        if ($account === null) {
            throw new JournalPostingFailed('Payment method "'.$method->label().'" points to a missing or inactive ledger account. Fix it in Accounts → Payment methods.');
        }

        return $account;
    }
}
