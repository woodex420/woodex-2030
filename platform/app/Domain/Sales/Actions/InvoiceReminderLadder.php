<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Notifications\OverdueInvoiceNotification;
use App\Domain\System\Alerts\AlertRouter;
use App\Models\User;
use App\Settings\SalesSettings;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * The overdue ladder (INV automation, slice E): an unpaid invoice is nudged on the 7th, 14th and 30th day
 * after it falls due — once per rung, never twice.
 *
 * Each rung is one act with two halves: the studio is told (bell + Telegram through the alert rules) and,
 * when the owner allows it and the client has consented, the client gets the payment reminder on WhatsApp
 * through the same consent-gated action a person uses by hand. Nothing is invented: if WhatsApp is not
 * connected or the client never opted in, the rung is still recorded and the team is told exactly why the
 * client was not messaged, so the next step is a phone call rather than a silent failure.
 *
 * The rung already fired is read from the audit trail (an `invoice_reminder` activity carrying the rung), so
 * the ladder needs no new table and the same record answers "what happened to this invoice?" on the Audit log.
 * Paid, void, draft and cancelled invoices are never in the ladder.
 */
final class InvoiceReminderLadder
{
    /** The days past due a reminder goes out, when settings are unreadable. */
    public const array DEFAULT_RUNGS = [7, 14, 30];

    public function __construct(
        private readonly SalesSettings $sales,
        private readonly SendPaymentReminder $reminder,
    ) {}

    /**
     * @return array{invoices: int, fired: int, delivered: int, held: int, rungs: list<int>}
     */
    public function handle(?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();
        $rungs = $this->rungs();

        $invoices = Invoice::query()
            ->with(['contact'])
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('balance_minor', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today->toDateString())
            ->orderBy('due_date')
            ->get();

        $fired = 0;
        $delivered = 0;
        $held = 0;

        foreach ($invoices as $invoice) {
            $daysLate = (int) $invoice->due_date->startOfDay()->diffInDays($today);
            $rung = $this->rungFor($daysLate, $rungs);

            if ($rung === null || $this->alreadyFired($invoice, $rung)) {
                continue;
            }

            $outcome = match (true) {
                ! $this->sales->invoice_reminder_send_client => ['sent' => false, 'reason' => 'client reminders are switched off in Settings — the studio was told instead.'],
                $this->actorFor($invoice) === null => ['sent' => false, 'reason' => 'no active owner or accountant could send it — call the client.'],
                default => $this->reminder->handle($invoice, $this->actorFor($invoice)),
            };

            $this->record($invoice, $rung, $daysLate, $outcome);

            app(AlertRouter::class)->send(
                'invoice.overdue',
                $this->watchers(),
                new OverdueInvoiceNotification($invoice, $rung, $daysLate, $outcome['sent'], $outcome['reason']),
            );

            $fired++;
            $outcome['sent'] ? $delivered++ : $held++;
        }

        return [
            'invoices' => $invoices->count(),
            'fired' => $fired,
            'delivered' => $delivered,
            'held' => $held,
            'rungs' => $rungs,
        ];
    }

    /**
     * The highest rung this invoice has reached — 8 days late is still the 7-day rung, 15 days late is the
     * 14-day one; null before the first rung.
     *
     * @param  list<int>  $rungs
     */
    public function rungFor(int $daysLate, ?array $rungs = null): ?int
    {
        $reached = null;

        foreach ($rungs ?? $this->rungs() as $rung) {
            if ($daysLate >= $rung) {
                $reached = $rung;
            }
        }

        return $reached;
    }

    /** @return list<int> */
    public function rungs(): array
    {
        $rungs = array_values(array_unique(array_map('intval', $this->sales->invoice_reminder_rungs)));
        $rungs = array_values(array_filter($rungs, static fn (int $value): bool => $value > 0));

        sort($rungs);

        return $rungs === [] ? self::DEFAULT_RUNGS : $rungs;
    }

    /** Has this exact rung already gone out for this invoice? Read from the audit trail. */
    public function alreadyFired(Invoice $invoice, int $rung): bool
    {
        return Activity::query()
            ->where('event', self::EVENT)
            ->where('subject_type', $invoice->getMorphClass())
            ->where('subject_id', $invoice->id)
            ->where('properties->rung', $rung)
            ->exists();
    }

    public const string EVENT = 'invoice_reminder';

    /**
     * One row in the audit trail per rung: what went out, to whom, and why not.
     *
     * @param  array{sent: bool, reason: string}  $outcome
     */
    private function record(Invoice $invoice, int $rung, int $daysLate, array $outcome): void
    {
        activity('billing')
            ->performedOn($invoice)
            ->event(self::EVENT)
            ->withProperties([
                'invoice' => $invoice->number,
                'rung' => $rung,
                'days_late' => $daysLate,
                'balance_minor' => (int) $invoice->balance_minor,
                'client_messaged' => $outcome['sent'],
                'reason' => $outcome['reason'],
            ])
            ->log(sprintf(
                '%d-day reminder for %s (%s overdue): %s',
                $rung,
                $invoice->number,
                $daysLate.' day'.($daysLate === 1 ? '' : 's'),
                $outcome['sent'] ? 'client messaged on WhatsApp.' : 'client not messaged — '.$outcome['reason'],
            ));
    }

    /**
     * Who the automation sends as. The conversation action authorizes a real staff member, so the ladder
     * speaks as the person who raised the invoice, else the first active person who may verify payments.
     */
    private function actorFor(Invoice $invoice): ?User
    {
        if ($invoice->created_by !== null) {
            $creator = User::query()->find($invoice->created_by);

            if ($creator !== null && $creator->is_active) {
                return $creator;
            }
        }

        return $this->watchers()[0] ?? null;
    }

    /** @return list<User> */
    private function watchers(): array
    {
        return User::query()
            ->with('telegramLink')
            ->where('is_active', true)
            ->permission(['invoices.manage', 'payments.verify'])
            ->orderBy('id')
            ->get()
            ->all();
    }
}
