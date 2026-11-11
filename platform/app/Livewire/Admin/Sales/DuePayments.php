<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Sales\Actions\SendPaymentReminder;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * P8.2: what the studio is owed, oldest first — overdue, due this week, aging buckets and the
 * clients carrying the most. Reminders go out one invoice at a time, consent-gated (A0 law).
 */
#[Layout('layouts::admin')]
#[Title('Due payments')]
class DuePayments extends Component
{
    use InteractsWithAdmin;

    public function render(): View
    {
        $open = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('balance_minor', '>', 0)
            ->with('contact:id,name')
            ->orderByRaw('case when due_date is not null and due_date < ? then 0 else 1 end', [now()->toDateString()])
            ->orderBy('due_date')
            ->limit(40)
            ->get();

        $agingBase = static fn (): Builder => Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('balance_minor', '>', 0);

        $aging = static fn (int $from, int $to): int => (int) (clone $agingBase())
            ->whereDate('due_date', '<=', now()->subDays($from)->toDateString())
            ->whereDate('due_date', '>=', now()->subDays($to)->toDateString())
            ->sum('balance_minor');

        $current = (int) (clone $agingBase())
            ->where(fn ($query) => $query->whereNull('due_date')->orWhereDate('due_date', '>', now()->toDateString()))
            ->sum('balance_minor');

        $buckets = collect([
            ['label' => 'Current (not due yet)', 'minor' => $current],
            ['label' => '1–30 days overdue', 'minor' => $aging(1, 30)],
            ['label' => '31–60 days overdue', 'minor' => $aging(31, 60)],
            ['label' => '61–90 days overdue', 'minor' => $aging(61, 90)],
            ['label' => '90+ days overdue', 'minor' => $aging(91, 3650)],
        ]);

        $byClient = (clone $open)
            ->filter(static fn (Invoice $invoice): bool => $invoice->contact !== null)
            ->groupBy(static fn (Invoice $invoice): string => (string) $invoice->contact?->name)
            ->map(static fn ($invoices): array => [
                'name' => (string) $invoices->first()->contact?->name,
                'minor' => $invoices->sum('balance_minor'),
                'invoices' => $invoices->count(),
            ])
            ->sortByDesc('minor')
            ->take(5);

        return view('livewire.admin.sales.due-payments', [
            'invoices' => $open,
            'buckets' => $buckets,
            'byClient' => $byClient,
            'totalMinor' => $open->sum('balance_minor'),
        ]);
    }

    public function remind(int $invoiceId): void
    {
        $invoice = Invoice::query()->with('contact')->findOrFail($invoiceId);

        abort_unless($invoice->status === InvoiceStatus::Issued || $invoice->status === InvoiceStatus::PartiallyPaid, 404);

        $result = app(SendPaymentReminder::class)->handle($invoice, $this->user());

        if ($result['sent']) {
            $this->toast("Reminder sent to {$invoice->contact?->name} on WhatsApp.", 'success');
        } else {
            $this->toast('Not sent — '.$result['reason'], 'error');
        }
    }

    public function money(int $minor): string
    {
        return Money::ofMinor($minor)->format();
    }
}
