<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P8.2: a verified receipt becomes a ledger entry — the method's cash/bank account is debited,
 * and the credit clears receivables for the allocated part, customer advances for any unallocated
 * credit. Idempotent per payment via the journal source guard.
 */
final class PostPaymentToLedger
{
    public function __construct(
        private readonly ResolveMethodAccount $resolveMethod,
    ) {}

    public function handle(Payment $payment, ?User $actor = null): JournalEntry
    {
        return DB::transaction(function () use ($payment, $actor): JournalEntry {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::Verified) {
                throw new JournalPostingFailed('Only verified payments can be posted to the ledger.');
            }
            $existing = JournalEntry::query()
                ->where('source_type', 'payment')
                ->where('source_id', $payment->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $amount = (int) $payment->amount_minor;

            if ($amount <= 0) {
                throw new JournalPostingFailed('Payment '.$payment->public_id.' has no amount to post.');
            }

            $debitAccount = $this->resolveMethod->handle($payment->method);

            $allocated = (int) PaymentAllocation::query()->where('payment_id', $payment->id)->sum('amount_minor');
            $unallocated = max(0, $amount - $allocated);

            $lines = [
                ['account_id' => $debitAccount->id, 'debit_minor' => $amount, 'memo' => 'Receipt '.($payment->receipt_number ?? $payment->public_id).' — '.$payment->method->label()],
            ];

            if ($allocated > 0) {
                $receivable = Account::query()->where('code', '1100')->where('is_active', true)->first()
                    ?? throw new JournalPostingFailed('Account 1100 (Accounts receivable) is missing from the chart. Restore it before verifying payments.');

                $lines[] = ['account_id' => $receivable->id, 'credit_minor' => $allocated, 'memo' => 'Receipt '.($payment->receipt_number ?? $payment->public_id).' — invoices settled'];
            }

            if ($unallocated > 0) {
                $advances = Account::query()->where('code', '1150')->where('is_active', true)->first()
                    ?? throw new JournalPostingFailed('Account 1150 (Customer advances) is missing from the chart. Restore it before verifying payments.');

                $lines[] = ['account_id' => $advances->id, 'credit_minor' => $unallocated, 'memo' => 'Receipt '.($payment->receipt_number ?? $payment->public_id).' — client credit'];
            }

            $actor ??= $this->systemActor();

            $contact = $payment->contact;
            $contactName = $contact === null ? 'client' : $contact->name;

            return app(PostJournalEntry::class)->handle($actor, $lines, [
                'source_type' => 'payment',
                'source_id' => $payment->id,
                'business_date' => $payment->received_on?->toDateString() ?? today()->toDateString(),
                'reference' => $payment->receipt_number ?? $payment->public_id,
                'project_id' => $payment->project_id,
                'note' => 'Receipt '.($payment->receipt_number ?? $payment->public_id)." from {$contactName} by ".$payment->method->label().'.',
            ]);
        });
    }

    /**
     * Who the entry is recorded against when no human actor is known: the owner, then an
     * admin, then any active staff member. The books never stop because a role is missing;
     * the owner reads created_by on the entry either way.
     */
    private function systemActor(): User
    {
        foreach (['owner', 'admin'] as $role) {
            $user = User::query()->role($role)->where('is_active', true)->orderBy('id')->first();

            if ($user !== null) {
                return $user;
            }
        }

        return User::query()->where('is_active', true)->orderBy('id')->first()
            ?? throw new JournalPostingFailed('No active staff account exists to post a system entry to the ledger.');
    }
}
