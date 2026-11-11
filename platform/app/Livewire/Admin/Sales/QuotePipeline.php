<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Sales\Models\Quote;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The sales pipeline over real quotations (Gauzy parity, slice K). Each column is a stage the OS already records on
 * the quotation; every card is a quotation with its value. The forecast is Σ(value × stage probability) — an
 * arithmetic statement about the records on this page, not a number typed into a dashboard.
 */
#[Layout('layouts::admin')]
#[Title('Sales pipeline')]
class QuotePipeline extends Component
{
    use InteractsWithAdmin;

    /** How likely each open stage is, used only for the weighted forecast shown on the page. */
    public const array WEIGHTS = [
        'draft' => 10,
        'pending_approval' => 25,
        'approved' => 40,
        'changes_requested' => 50,
        'sent' => 60,
        'viewed' => 70,
        'accepted' => 100,
        'declined' => 0,
        'expired' => 0,
        'superseded' => 0,
        'void' => 0,
    ];

    /** Column layout: the stages in the order work moves. */
    public const array COLUMNS = [
        'draft' => 'Draft',
        'pending_approval' => 'Awaiting approval',
        'sent' => 'Sent',
        'viewed' => 'Viewed',
        'changes_requested' => 'Changes requested',
        'accepted' => 'Won',
        'closed' => 'Lost or closed',
    ];

    public function mount(): void
    {
        $this->authorize('quotes.view');
    }

    public function render(): View
    {
        $quotes = Quote::query()
            ->visibleTo($this->user())
            ->with(['contact:id,name', 'owner:id,name'])
            ->orderByDesc('total_minor')
            ->get(['id', 'number', 'title', 'status', 'total_minor', 'contact_id', 'owner_id', 'created_at', 'sent_at', 'valid_until']);

        $columns = [];

        foreach (self::COLUMNS as $key => $label) {
            $columns[$key] = ['label' => $label, 'quotes' => [], 'value' => 0, 'weighted' => 0];
        }

        $forecast = 0;
        $openValue = 0;

        foreach ($quotes as $quote) {
            $status = $quote->status->value;
            $weight = self::WEIGHTS[$status] ?? 0;

            $column = match ($status) {
                'draft' => 'draft',
                'pending_approval', 'approved' => 'pending_approval',
                'sent' => 'sent',
                'viewed' => 'viewed',
                'changes_requested' => 'changes_requested',
                'accepted' => 'accepted',
                default => 'closed',
            };

            $columns[$column]['quotes'][] = $quote;
            $columns[$column]['value'] += (int) $quote->total_minor;

            if (in_array($status, ['accepted', 'declined', 'expired', 'superseded', 'void'], true)) {
                if ($status === 'accepted') {
                    $columns[$column]['weighted'] += (int) $quote->total_minor;
                }

                continue;
            }

            $weighted = intdiv((int) $quote->total_minor * $weight, 100);
            $columns[$column]['weighted'] += $weighted;
            $forecast += $weighted;
            $openValue += (int) $quote->total_minor;
        }

        return view('livewire.admin.sales.quote-pipeline', [
            'columns' => $columns,
            'forecast' => $forecast,
            'openValue' => $openValue,
            'wonValue' => $columns['accepted']['value'],
            'totalQuotes' => $quotes->count(),
            'lakh' => app(SalesSettings::class)->lakh_grouping,
            'weights' => self::WEIGHTS,
        ]);
    }
}
