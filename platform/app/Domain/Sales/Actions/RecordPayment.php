<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Accounts\Actions\PostPaymentToLedger;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Mail\PaymentMail;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Notifications\PaymentNotification;
use App\Domain\Sales\Services\ReceiptPdf;
use App\Domain\System\Alerts\AlertRouter;
use App\Models\User;
use App\Support\Numbering;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Money the studio received (INV-005): cash at the site, a transfer the owner saw in the bank app, a cheque handed
 * over at a meeting. Staff record it and it is verified at once — a receipt goes out with it.
 *
 * @see SubmitPaymentProof for the money a client claims to have sent, which waits for the accountant.
 */
final class RecordPayment
{
    public function __construct(
        private readonly AllocatePayment $allocate,
        private readonly Numbering $numbering,
        private readonly ReceiptPdf $receipts,
        private readonly PostPaymentToLedger $postToLedger,
    ) {}

    /**
     * @param  array<string, mixed>  $input  contact_id, amount_minor, method, received_on, reference, bank_account_id,
     *                                       project_id, notes, and either invoice_id or allocations
     *                                       (invoice id => amount; empty allocates to the oldest open invoices)
     */
    public function handle(array $input, User $by): Payment
    {
        $by = User::query()->findOrFail($by->id);
        if (! $by->is_active) {
            throw new AuthorizationException;
        }
        Gate::forUser($by)->authorize('create', Payment::class);
        Validator::make($input, [
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'in:PKR'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'received_on' => ['required', 'date'],
            'method' => ['required', 'in:'.implode(',', array_keys(PaymentMethod::options()))],
            'allocations' => ['sometimes', 'array'],
        ])->validate();
        if (! is_int($input['amount_minor']) || (isset($input['invoice_id']) && ! empty($input['allocations']))) {
            throw ValidationException::withMessages(['amount' => 'Use integer paisa and choose either an invoice preference or an explicit allocation map.']);
        }
        $contact = Contact::query()->findOrFail((int) $input['contact_id']);

        return DB::transaction(function () use ($input, $by, $contact): Payment {
            $payment = Payment::query()->create([
                'contact_id' => $contact->id,
                'invoice_id' => $input['invoice_id'] ?? null,
                'project_id' => $input['project_id'] ?? null,
                'amount_minor' => (int) $input['amount_minor'],
                'received_on' => $input['received_on'],
                'method' => PaymentMethod::from((string) $input['method']),
                'reference' => $input['reference'] ?? null,
                'bank_account_id' => $input['bank_account_id'] ?? null,
                'status' => PaymentStatus::Verified,
                'submitted_by' => 'staff',
                'created_by' => $by->id,
                'verified_by' => $by->id,
                'verified_at' => now(),
                'notes' => $input['notes'] ?? null,
            ]);

            $allocations = $input['allocations'] ?? [];

            $this->allocate->handle($payment, $allocations);

            $receipted = $this->receipt($payment, $by);
            $this->postToLedger->handle($receipted, $by);

            return $receipted->refresh();
        });
    }

    /** The receipt for a confirmed payment (INV-007): number, PDF, the client's copy by email, the team alerted. */
    public function receipt(Payment $payment, User $by): Payment
    {
        $by = User::query()->findOrFail($by->id);
        if (! $by->is_active || (! $by->can('payments.record') && ! $by->can('payments.verify'))) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($payment, $by): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->receipt_number !== null) {
                return $payment;
            }
            if ($payment->status !== PaymentStatus::Verified) {
                throw ValidationException::withMessages(['amount' => 'Only confirmed payments can receive a receipt.']);
            }

            $payment->forceFill([
                'receipt_number' => $this->numbering->next('receipt'),
                'verified_by' => $by->id,
                'verified_at' => now(),
            ])->save();

            $bytes = $this->receipts->render($payment->fresh(['contact', 'allocations.invoice', 'bankAccount']));
            $path = 'receipts/'.$payment->public_id.'/'.ReceiptPdf::filename($payment);

            DB::afterRollBack(fn () => Storage::disk('local')->delete($path));
            if (! Storage::disk('local')->put($path, $bytes)) {
                throw new RuntimeException('The receipt could not be stored. No payment was committed.');
            }

            $payment->forceFill(['receipt_pdf_path' => $path])->save();

            $payment->loadMissing('contact');

            if (filled($payment->contact?->email)) {
                Mail::to((string) $payment->contact->email)->queue((new PaymentMail($payment->id, 'verified'))->afterCommit());
            }

            app(AlertRouter::class)->send('payment.verified',
                User::query()->with('telegramLink')->permission('invoices.view')->where('is_active', true)->get(),
                new PaymentNotification($payment, 'verified'),
            );

            return $payment;
        });
    }
}
