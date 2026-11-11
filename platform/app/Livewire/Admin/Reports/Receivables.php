<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Reports;

use App\Domain\Crm\Models\Contact;
use App\Settings\SalesSettings;
use App\Support\Phone;
use App\Support\Reports\Csv;
use App\Support\Reports\ReceivablesReport;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Receivables (INV-005b): who owes what, aged by how long it has been owed, worst first. Each client links
 * to their ledger and offers a WhatsApp nudge — the follow-up entry point the aging table was missing.
 */
#[Layout('layouts::admin')]
#[Title('Receivables')]
class Receivables extends Component
{
    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    #[Computed]
    public function report(): array
    {
        return app(ReceivablesReport::class)->handle();
    }

    /**
     * The WhatsApp entry point for a friendly nudge about an outstanding balance.
     */
    /**
     * The WhatsApp entry point for a friendly nudge about an outstanding balance.
     */
    public function whatsappFor(int $contactId): ?string
    {
        $contact = Contact::query()->find($contactId);

        return $contact !== null ? Phone::whatsappUrl($contact->whatsappNumber()) : null;
    }

    public function export(): StreamedResponse
    {
        $this->authorize('invoices.view');

        $report = $this->report();

        $rows = [
            ['Client', 'Invoices', 'Invoiced (Rs)', 'Paid (Rs)', 'Balance (Rs)', 'Not due (Rs)', '1-30 days (Rs)', '31-60 days (Rs)', '61-90 days (Rs)', '90+ days (Rs)', 'Oldest due'],
        ];

        foreach ($report['rows'] as $row) {
            $rows[] = [
                $row['contact'],
                $row['invoices'],
                round($row['invoiced_minor'] / 100, 2),
                round($row['paid_minor'] / 100, 2),
                round($row['balance_minor'] / 100, 2),
                round($row['current'] / 100, 2),
                round($row['d1_30'] / 100, 2),
                round($row['d31_60'] / 100, 2),
                round($row['d61_90'] / 100, 2),
                round($row['d90_plus'] / 100, 2),
                $row['oldest_due'] !== null ? $row['oldest_due']->format('j M Y') : '',
            ];
        }

        $totals = $report['totals'];

        $rows[] = [
            'Total',
            '',
            '',
            '',
            round($totals['balance_minor'] / 100, 2),
            round($totals['current'] / 100, 2),
            round($totals['d1_30'] / 100, 2),
            round($totals['d31_60'] / 100, 2),
            round($totals['d61_90'] / 100, 2),
            round($totals['d90_plus'] / 100, 2),
            '',
        ];

        return Csv::download('receivables-'.now()->format('Y-m-d').'.csv', ['Receivables aging'], $rows);
    }

    public function render(SalesSettings $settings): View
    {
        return view('livewire.admin.reports.receivables', [
            'lakh' => $settings->lakh_grouping,
        ]);
    }
}
