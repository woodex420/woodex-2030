<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * /statement <client> — what a client owes and what they paid recently (P8.2). A text statement
 * on the phone; the printable statement comes with P8.3/P8.4 documents. Ambiguous names are
 * answered with the matches so the owner picks; the client never sees this — it is staff-only.
 */
final class StatementCommand implements TelegramCommand
{
    private const int LIMIT = 6;

    public function name(): string
    {
        return '/statement';
    }

    public function usage(): string
    {
        return '/statement <client name>';
    }

    public function description(): string
    {
        return 'what a client owes and recently paid';
    }

    public function permission(): string
    {
        return 'invoices.view';
    }

    public function handle(User $user, string $argument): string
    {
        $query = trim($argument);

        if ($query === '') {
            return 'Whose statement? Send /statement <client name> — or /statement INV-2026-0007 to start from an invoice number.';
        }

        if (preg_match('/^inv[-\\s]?\\d+/i', $query)) {
            $invoice = Invoice::query()->where('number', (string) Str::of($query)->replaceMatches('/[\\s-]/', '')->upper())->first();

            if ($invoice === null) {
                return 'There is no invoice '.$query.'.';
            }

            if ($invoice->contact === null) {
                return $invoice->number.' has no client contact attached.';
            }

            return $this->statement($invoice->contact);
        }

        $matches = Contact::query()
            ->whereRaw('lower(name) like ?', ['%'.Str::lower($query).'%'])
            ->orderBy('name')
            ->limit(self::LIMIT + 1)
            ->get();

        if ($matches->isEmpty()) {
            return 'No client matches "'.$query.'."';
        }

        if ($matches->count() === 1) {
            return $this->statement($matches->first());
        }

        if ($matches->count() > self::LIMIT) {
            $matches = $matches->take(self::LIMIT);
        }

        $lines = ['Several clients match "'.$query.'" — send /statement again with the full name:'];

        foreach ($matches as $match) {
            $lines[] = '• '.$match->name;
        }

        return implode("\n", $lines);
    }

    private function statement(Contact $contact): string
    {
        $open = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('contact_id', $contact->id)
            ->orderBy('due_date')
            ->limit(8)
            ->get();

        $outstandingMinor = (int) Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('contact_id', $contact->id)
            ->sum('balance_minor');

        $overdueMinor = (int) Invoice::query()
            ->overdue()
            ->where('contact_id', $contact->id)
            ->sum('balance_minor');

        $payments = Payment::query()
            ->where('contact_id', $contact->id)
            ->whereNotNull('receipt_number')
            ->orderByDesc('received_on')
            ->limit(3)
            ->get();

        $lines = ['Statement — '.$contact->name];

        if ($open->isEmpty()) {
            $lines[] = 'Nothing outstanding.';
        } else {
            foreach ($open as $invoice) {
                $overdueTag = $invoice->due_date !== null && $invoice->due_date->isPast() ? ' (overdue)' : '';
                $lines[] = sprintf(
                    '• %s — %s due %s%s',
                    $invoice->number,
                    Money::ofMinor((int) $invoice->balance_minor)->format(),
                    $invoice->due_date?->format('j M Y') ?? '—',
                    $overdueTag,
                );
            }
        }

        $lines[] = '';
        $lines[] = 'Total outstanding: '.Money::ofMinor($outstandingMinor)->format()
            .($overdueMinor > 0 ? ' ('.Money::ofMinor($overdueMinor)->format().' overdue)' : '');

        if ($payments->isNotEmpty()) {
            $lines[] = 'Recent receipts:';

            foreach ($payments as $payment) {
                $lines[] = sprintf(
                    '• %s — %s on %s',
                    $payment->receipt_number,
                    Money::ofMinor((int) $payment->amount_minor)->format(),
                    $payment->received_on?->format('j M Y') ?? '—',
                );
            }
        }

        return implode("\n", $lines);
    }
}
