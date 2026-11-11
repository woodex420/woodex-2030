<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Digests;

use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\SiteVisit;
use App\Domain\Crm\Models\Task;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Quote;
use App\Domain\System\Enums\ApprovalStatus;
use App\Domain\System\Models\Approval;
use App\Models\User;
use App\Support\Money;

/**
 * S4: the morning digest — one message per linked staff member at 09:00.
 *
 * The same honesty as /today: everybody sees their own day, and each number appears only
 * for roles who can act on it. A muted chat does not miss the digest — it waits out the
 * mute and is delivered by woodex:telegram-flush.
 */
final class DailyDigest
{
    /** Build the message for one staff member. */
    public function build(User $user): string
    {
        $lines = ['☀️ '.now()->format('l d M').' — your day'];

        $visits = SiteVisit::query()
            ->whereDate('scheduled_at', now()->toDateString())
            ->where(fn ($query) => $query->where('assigned_to', $user->id)->orWhereNull('assigned_to'))
            ->with('contact:id,name')
            ->orderBy('scheduled_at')
            ->get();

        $lines[] = '';
        $lines[] = 'Visits today:';

        if ($visits->isEmpty()) {
            $lines[] = '— none';
        }

        foreach ($visits as $visit) {
            $lines[] = sprintf(
                '%s · %s%s',
                $visit->scheduled_at->format('H:i'),
                $visit->contact->name,
                $visit->address !== null && $visit->address !== '' ? ' · '.mb_strimwidth($visit->address, 0, 40, '…') : '',
            );
        }

        $tasks = Task::query()
            ->where('status', 'open')
            ->where('assignee_id', $user->id)
            ->where(fn ($query) => $query->whereNull('due_at')->orWhere('due_at', '<=', now()->endOfDay()))
            ->orderBy('due_at')
            ->limit(6)
            ->get();

        $lines[] = '';
        $lines[] = 'Your open tasks:';

        if ($tasks->isEmpty()) {
            $lines[] = '— none';
        }

        foreach ($tasks as $task) {
            $lines[] = sprintf('%s%s', $task->title, $task->due_at !== null ? ' · due '.$task->due_at->diffForHumans() : '');
        }

        if ($user->can('leads.view')) {
            $followUps = Lead::query()->visibleTo($user)
                ->open()
                ->whereNotNull('next_action_at')
                ->where('next_action_at', '<=', now()->endOfDay())
                ->with('contact:id,name')
                ->orderBy('next_action_at')
                ->limit(6)
                ->get();

            $lines[] = '';
            $lines[] = 'Follow-ups due:';

            if ($followUps->isEmpty()) {
                $lines[] = '— none today';
            }

            foreach ($followUps as $lead) {
                $lines[] = sprintf('%s · %s · %s', $lead->ref, $lead->contact->name, $lead->next_action_at->isPast() ? 'overdue '.$lead->next_action_at->diffForHumans() : 'due '.$lead->next_action_at->diffForHumans());
            }

            $newLeads = Lead::query()->visibleTo($user)->whereBetween('created_at', [now()->startOfDay()->subDay(), now()->startOfDay()])->count();
            $lines[] = '';
            $lines[] = sprintf('Yesterday: %d new lead%s.', $newLeads, $newLeads === 1 ? '' : 's');
        }

        if ($user->can('quotes.view')) {
            $quotes = Quote::query()->visibleTo($user)->whereBetween('sent_at', [now()->startOfDay()->subDay(), now()->startOfDay()])->count();
            $lines[] = sprintf('Quotations sent yesterday: %d.', $quotes);
        }

        if ($user->can('payments.view')) {
            $payments = Payment::query()
                ->whereBetween('verified_at', [now()->startOfDay()->subDay(), now()->startOfDay()])
                ->selectRaw('count(*) as c, coalesce(sum(amount_minor), 0) as total')
                ->first();

            $lines[] = sprintf(
                'Money in yesterday: %s across %d payment%s.',
                Money::ofMinor((int) ($payments->total ?? 0))->format(),
                (int) ($payments->c ?? 0),
                (int) ($payments->c ?? 0) === 1 ? '' : 's',
            );
        }

        if ($user->can('quotes.approve')) {
            $approvals = Approval::query()->where('status', ApprovalStatus::Pending->value)->count();
            $lines[] = sprintf('Approvals waiting: %d — /approvals to decide.', $approvals);
        }

        return implode("\n", $lines);
    }
}
