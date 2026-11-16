<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\SiteVisit;
use App\Domain\Crm\Models\Task;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Quote;
use App\Domain\System\Enums\ApprovalStatus;
use App\Domain\System\Models\Approval;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;
use App\Support\Money;

/**
 * /today — the morning view (TGM-004): everything that wants attention today in one message.
 *
 * Visits and tasks are the linked staff member's own; leads follow their visibility; payments and approvals
 * appear only to roles who can act on them, so nobody is shown work they cannot do.
 */
final class TodayCommand implements TelegramCommand
{
    public function name(): string
    {
        return '/today';
    }

    public function usage(): string
    {
        return '/today';
    }

    public function description(): string
    {
        return 'your visits, tasks and everything waiting for attention';
    }

    public function permission(): string
    {
        return 'leads.view';
    }

    public function handle(User $user, string $argument): string
    {
        $lines = ['☀️ Today · '.now()->format('D d M')];

        $visits = SiteVisit::query()
            ->whereDate('scheduled_at', now()->toDateString())
            ->where(fn ($query) => $query->where('assigned_to', $user->id)->orWhereNull('assigned_to'))
            ->with('contact:id,name')
            ->orderBy('scheduled_at')
            ->get();

        $lines[] = '';
        $lines[] = 'Visits:';

        if ($visits->isEmpty()) {
            $lines[] = '— none today';
        }

        foreach ($visits as $visit) {
            $lines[] = sprintf(
                '%s · %s%s',
                $visit->scheduled_at->format('H:i'),
                $visit->contact->name,
                $visit->address !== null && $visit->address !== '' ? ' · '.$visit->address : '',
            );
        }

        $lines[] = '';
        $lines[] = 'Waiting too long (SLA):';

        $overdue = Lead::query()->visibleTo($user)
            ->whereNull('first_response_at')
            ->where('sla_due_at', '<', now())
            ->with('contact:id,name', 'owner:id,name')
            ->orderBy('sla_due_at')
            ->limit(8)
            ->get();

        if ($overdue->isEmpty()) {
            $lines[] = '— nothing overdue';
        }

        foreach ($overdue as $lead) {
            $lines[] = sprintf('%s · %s · due %s', $lead->ref, $lead->contact->name, $lead->sla_due_at->diffForHumans());
        }

        $tasks = Task::query()
            ->where('status', 'open')
            ->where('assignee_id', $user->id)
            ->where(fn ($query) => $query->whereNull('due_at')->orWhere('due_at', '<=', now()->endOfDay()))
            ->orderBy('due_at')
            ->limit(8)
            ->get();

        $lines[] = '';
        $lines[] = 'Your tasks:';

        if ($tasks->isEmpty()) {
            $lines[] = '— nothing open';
        }

        foreach ($tasks as $task) {
            $lines[] = sprintf('%s%s', $task->title, $task->due_at !== null ? ' · due '.$task->due_at->diffForHumans() : '');
        }

        if ($user->can('payments.verify')) {
            $payments = Payment::query()
                ->where('status', PaymentStatus::PendingVerification->value)
                ->with(['invoice:id,number', 'contact:id,name'])
                ->latest('id')
                ->limit(6)
                ->get();

            $lines[] = '';
            $lines[] = 'Payments to verify:';

            if ($payments->isEmpty()) {
                $lines[] = '— none waiting';
            }

            foreach ($payments as $payment) {
                $lines[] = sprintf(
                    '%s · %s · %s',
                    $payment->invoice !== null ? $payment->invoice->number : 'invoice',
                    $payment->contact->name,
                    Money::ofMinor((int) $payment->amount_minor)->format(),
                );
            }
        }

        if ($user->can('quotes.approve')) {
            $approvals = Approval::query()
                ->where('status', ApprovalStatus::Pending->value)
                ->whereHasMorph('approvable', [Quote::class])
                ->with(['approvable', 'requester:id,name'])
                ->latest('id')
                ->limit(6)
                ->get();

            $lines[] = '';
            $lines[] = 'Approvals waiting:';

            if ($approvals->isEmpty()) {
                $lines[] = '— nothing to approve';
            }

            foreach ($approvals as $approval) {
                $quote = $approval->approvable;
                $lines[] = sprintf(
                    '#%d · %s · asked by %s',
                    $approval->id,
                    $quote instanceof Quote ? $quote->label() : 'quote',
                    $approval->requester !== null ? $approval->requester->name : 'someone',
                );
            }

            if ($approvals->isNotEmpty()) {
                $lines[] = 'Reply /approve <number> to decide.';
            }
        }

        return implode("\n", $lines);
    }
}
