<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoices list (INV-001/003): number, client, project, status, amount and balance, with the overdue ones first.
 * An invoice is raised when a quotation is accepted (the advance) and later when the work reaches a milestone;
 * work without a quotation is billed with "New invoice" (Slice P).
 */
#[Layout('layouts::admin')]
#[Title('Invoices')]
class InvoicesIndex extends Component
{
    use InteractsWithAdmin, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Invoice::class);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function export(): StreamedResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $query = $this->query()->with('contact:id,name')->orderByDesc('issue_date')->latest('id')->limit(10000);
        $clean = static function (mixed $value): string {
            $value = (string) $value;

            // CSV injection guard: spreadsheet formulas never run from exported client data.
            return preg_match('/^[=+\-@\t\r]/', $value) && ! preg_match('/^\+?\d+$/', $value) ? "'".$value : $value;
        };

        return response()->streamDownload(function () use ($query, $clean): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['Number', 'Kind', 'Status', 'Issue date', 'Due date', 'Client', 'Line', 'Total', 'Paid', 'Balance'], escape: '');

            foreach ($query->lazy(500) as $invoice) {
                /** @var Invoice $invoice */
                fputcsv($out, array_map($clean, [
                    $invoice->number,
                    $invoice->kind->label(),
                    $invoice->status->label(),
                    $invoice->issue_date?->format('Y-m-d'),
                    $invoice->due_date?->format('Y-m-d'),
                    $invoice->contact?->name,
                    $invoice->business_line->label(),
                    Money::ofMinor((int) $invoice->total_minor)->format(false),
                    Money::ofMinor((int) $invoice->paid_minor)->format(false),
                    Money::ofMinor((int) $invoice->balance_minor)->format(false),
                ]), escape: '');
            }

            fclose($out);
        }, 'invoices-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return Builder<Invoice> */
    private function query(): Builder
    {
        return Invoice::query()
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhereHas('contact', fn (Builder $c) => $c->where('name', 'like', '%'.$this->search.'%'))
                ->orWhereHas('quote', fn (Builder $c) => $c->where('number', 'like', '%'.$this->search.'%'))))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status));
    }

    public function render(): View
    {
        $invoices = $this->query()
            ->with(['contact:id,name,phone', 'project:id,code,name', 'quote:id,number,title'])
            ->orderByRaw("case when due_date is not null and due_date < ? and status not in ('paid','void') then 0 else 1 end", [now()->toDateString()])
            ->orderByDesc('issue_date')
            ->latest('id')
            ->paginate(25);

        $counts = Invoice::query()->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        $overdue = Invoice::query()
            ->whereNotIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Void->value])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->sum('balance_minor');

        return view('livewire.admin.sales.invoices-index', [
            'invoices' => $invoices,
            'counts' => $counts,
            'total' => (int) $counts->sum(),
            'overdue' => (int) $overdue,
            'chips' => [
                InvoiceStatus::Draft, InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid, InvoiceStatus::Void,
            ],
            'canCreate' => $this->user()->can('create', Invoice::class),
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }
}
