<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Sales\Actions\CollectPayment;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\VoidInvoice;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Services\InvoiceLinks;
use App\Domain\Sales\Services\InvoicePdf;
use App\Domain\Sales\Services\InvoiceSnapshot;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One invoice, in full (INV-003/005/006). The list answered "what is billed"; this is the record itself: the frozen
 * lines, what has been paid against it, the ledger entry it became, the client's link — and the two actions the
 * studio actually needs from here: collect a payment and (for the owner) void it.
 *
 * Nothing on this page is invented: the totals come from the invoice row, the payments from the payments table, the
 * ledger card from the journal the invoice posted. An invoice that never posted shows no ledger card.
 */
#[Layout('layouts::admin')]
#[Title('Invoice')]
class InvoiceShow extends Component
{
    use InteractsWithAdmin;

    public int $invoiceId;

    public string $amount = '';

    public string $method = 'bank_transfer';

    public string $reference = '';

    public string $voidReason = '';

    public bool $showVoid = false;

    /** Live preview drawer (drafts only): the invoice document as it will print. */
    public bool $showPreview = false;

    public function mount(Invoice $invoice): void
    {
        $this->authorize('view', $invoice);

        $this->invoiceId = $invoice->id;
        $this->amount = $this->amountFor($invoice);
    }

    public function openVoid(): void
    {
        $this->authorize('void', $this->invoice());

        $this->resetValidation();
        $this->voidReason = '';
        $this->showVoid = true;
    }

    public function closeVoid(): void
    {
        $this->showVoid = false;
        $this->resetValidation('voidReason');
    }

    public function collect(CollectPayment $collect): void
    {
        $invoice = $this->invoice();

        $this->authorize('payments.record');
        $this->authorize('payments.verify');

        $data = $this->validate([
            'amount' => ['required', 'string', 'max:30'],
            'method' => ['required', 'string', 'in:'.implode(',', array_column(PaymentMethod::cases(), 'value'))],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $payment = $collect->handle(
            $invoice,
            Money::ofRupees($data['amount'])->minor,
            PaymentMethod::from($data['method']),
            $this->user(),
            $data['reference'] !== '' ? $data['reference'] : null,
        );

        $this->reference = '';
        $this->amount = $this->amountFor($invoice->refresh());

        session()->flash('status', sprintf(
            'Collected %s on %s — payment %s verified and posted to the ledger.',
            $payment->amount()->format(),
            $invoice->number,
            $payment->receipt_number ?? '#'.$payment->id,
        ));
    }

    public function void(VoidInvoice $void): void
    {
        $invoice = $this->invoice();

        $this->authorize('void', $invoice);

        $this->validate(['voidReason' => ['required', 'string', 'min:4', 'max:500']]);

        $invoice = $void->handle($invoice, $this->user(), $this->voidReason);

        $this->showVoid = false;
        $this->voidReason = '';

        session()->flash('status', "Invoice {$invoice->number} is void. Its ledger entry, if it had one, was reversed, not deleted.");
    }

    public function issue(IssueInvoice $issue): void
    {
        $invoice = $this->invoice();

        $this->authorize('issue', $invoice);

        try {
            $invoice = $issue->handle($invoice, $this->user());
        } catch (QuoteActionDenied|JournalPostingFailed $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        session()->flash('status', "Invoice {$invoice->number} issued — frozen and booked in the ledger.");
    }

    public function render(): View
    {
        $invoice = $this->invoice();

        $journal = JournalEntry::query()
            ->with(['lines.account'])
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->id)
            ->first();

        return view('livewire.admin.sales.invoice-show', [
            'invoice' => $invoice,
            'payments' => $invoice->payments()->orderByDesc('id')->get(),
            'journal' => $journal,
            'canCollect' => $this->canCollect($invoice),
            'canVoid' => Gate::allows('void', $invoice),
            // State first: the owner passes every permission check (Gate::before), but an issued invoice stays issued.
            'canIssue' => $invoice->status === InvoiceStatus::Draft && Gate::allows('issue', $invoice),
            'previewHtml' => $this->showPreview && $invoice->status === InvoiceStatus::Draft
                ? app(InvoicePdf::class)->html(app(InvoiceSnapshot::class)->build($invoice))
                : null,
            'portal' => InvoiceLinks::portal($invoice),
            'whatsapp' => InvoiceLinks::whatsappShare($invoice),
            'methods' => PaymentMethod::cases(),
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }

    private function canCollect(Invoice $invoice): bool
    {
        $user = $this->user();

        return $user->can('payments.record')
            && $user->can('payments.verify')
            && in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true)
            && (int) $invoice->balance_minor > 0;
    }

    private function invoice(): Invoice
    {
        return Invoice::query()
            ->with(['contact:id,name,phone,email', 'quote:id,number,title', 'project:id,code,name', 'bankAccount', 'items'])
            ->findOrFail($this->invoiceId);
    }

    private function amountFor(Invoice $invoice): string
    {
        return (int) $invoice->balance_minor > 0
            ? number_format((int) $invoice->balance_minor / 100, 2, '.', '')
            : '';
    }
}
