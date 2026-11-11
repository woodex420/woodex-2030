<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Settings\BusinessSettings;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

/**
 * A client statement on the studio letterhead (P8.4): every invoice and receipt in the chosen
 * period with a running balance, and the current outstanding with its aging — one honest page
 * the owner can hand over. Read-only: it renders live data and never posts or promises anything.
 */
final class ClientStatementPdf
{
    public function __construct(private readonly BusinessSettings $business) {}

    /**
     * @return array{contact: Contact, from: ?Carbon, to: Carbon, lines: list<array{date: string, kind: string, label: string, debit: string, credit: string, balance: string, balance_minor: int}>, total_invoiced_minor: int, total_received_minor: int, outstanding_minor: int, aging: list<array{label: string, amount: string}>, generated_on: string}
     */
    public function data(Contact $contact, ?string $from = null, ?string $to = null): array
    {
        $fromDate = $from !== null && $from !== '' ? Carbon::parse($from)->startOfDay() : null;
        $toDate = $to !== null && $to !== '' ? Carbon::parse($to)->endOfDay() : now();

        $invoices = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value])
            ->where('contact_id', $contact->id)
            ->when($fromDate, static fn ($query) => $query->where('issue_date', '>=', $fromDate->copy()->startOfDay()))
            ->where('issue_date', '<=', $toDate)
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        $payments = Payment::query()
            ->where('contact_id', $contact->id)
            ->whereNotNull('receipt_number')
            ->when($fromDate, static fn ($query) => $query->where('received_on', '>=', $fromDate->copy()->startOfDay()))
            ->where('received_on', '<=', $toDate)
            ->orderBy('received_on')
            ->orderBy('id')
            ->get();

        $rows = $invoices->map(static fn (Invoice $invoice): array => [
            'sort' => $invoice->issue_date->format('Ymd').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT),
            'date' => $invoice->issue_date->format('d M Y'),
            'kind' => 'invoice',
            'label' => $invoice->number,
            'debit_minor' => (int) $invoice->total_minor,
            'credit_minor' => 0,
        ])->concat($payments->map(static fn (Payment $payment): array => [
            'sort' => $payment->received_on->format('Ymd').'-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
            'date' => $payment->received_on->format('d M Y'),
            'kind' => 'receipt',
            'label' => (string) $payment->receipt_number,
            'debit_minor' => 0,
            'credit_minor' => (int) $payment->amount_minor,
        ]))->sortBy('sort')->values();

        $running = 0;
        $lines = [];

        foreach ($rows as $row) {
            $running += $row['debit_minor'] - $row['credit_minor'];

            $lines[] = [
                'date' => $row['date'],
                'kind' => $row['kind'],
                'label' => $row['label'],
                'debit' => $row['debit_minor'] > 0 ? Money::ofMinor($row['debit_minor'])->format() : '—',
                'credit' => $row['credit_minor'] > 0 ? Money::ofMinor($row['credit_minor'])->format() : '—',
                'balance' => Money::ofMinor(max(0, $running))->format(),
                'balance_minor' => max(0, $running),
            ];
        }

        $open = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('contact_id', $contact->id)
            ->where('balance_minor', '>', 0)
            ->orderBy('due_date')
            ->get();

        $aging = static fn (int $from, int $to): int => (int) (clone $open)
            ->filter(static fn (Invoice $invoice): bool => $invoice->due_date !== null
                && $invoice->due_date->diffInDays(now()) >= $from
                && $invoice->due_date->diffInDays(now()) <= $to)
            ->sum('balance_minor');

        $overdueMinor = (clone $open)
            ->filter(static fn (Invoice $invoice): bool => $invoice->due_date !== null && $invoice->due_date->isPast())
            ->sum('balance_minor');

        return [
            'contact' => $contact,
            'from' => $fromDate,
            'to' => $toDate,
            'lines' => $lines,
            'total_invoiced_minor' => (int) $invoices->sum('total_minor'),
            'total_received_minor' => (int) $payments->sum('amount_minor'),
            'outstanding_minor' => (int) $open->sum('balance_minor'),
            'aging' => [
                ['label' => 'Current', 'amount' => Money::ofMinor((int) $open->sum('balance_minor') - $overdueMinor)->format()],
                ['label' => '1–30 days overdue', 'amount' => Money::ofMinor($aging(1, 30))->format()],
                ['label' => '31–60 days overdue', 'amount' => Money::ofMinor($aging(31, 60))->format()],
                ['label' => '61+ days overdue', 'amount' => Money::ofMinor($aging(61, 3650))->format()],
            ],
            'generated_on' => today()->format('d M Y'),
        ];
    }

    /** @return array<string, mixed> */
    public function viewData(Contact $contact, ?string $from = null, ?string $to = null): array
    {
        return [
            'statement' => $this->data($contact, $from, $to),
            'business' => [
                'name' => $this->business->brand_name,
                'legal_name' => $this->business->legal_name,
                'tagline' => $this->business->tagline,
                'address' => $this->business->address,
                'city' => $this->business->city,
                'phone' => $this->business->phone,
                'email' => $this->business->email,
                'website' => $this->business->website,
                'ntn' => $this->business->ntn,
            ],
        ];
    }

    public function render(Contact $contact, ?string $from = null, ?string $to = null): string
    {
        return Pdf::loadView('pdf.client-statement', $this->viewData($contact, $from, $to))
            ->setPaper('a4')
            ->setOption('isRemoteEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }

    public static function filename(Contact $contact): string
    {
        return 'statement-'.str_replace([' ', '/'], '-', $contact->name).'-'.today()->format('Ymd').'.pdf';
    }
}
