<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Crm\Enums\TaskStatus;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\Task;
use App\Domain\Projects\Models\ProjectMilestone;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Quote;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slice M: the admin assistant. A small, fixed set of **read-only questions**, each answered by the same
 * query its screen uses, with the records it read cited underneath. There is deliberately no free-form
 * generation: in keyless mode (no AI provider configured) these deterministic answers are the whole
 * product, and a question outside the whitelist says so instead of guessing. No external provider is
 * integrated. Any future provider must satisfy the approved aggregate/model/budget/retention gates.
 *
 * @phpstan-type Question array{id: string, label: string, permission: string, keywords: list<string>}
 */
final class Assistant
{
    private const ROW_LIMIT = 8;

    /**
     * The whitelist, in match-priority order. Chips ask by id; typed questions match by keyword.
     * Specific money questions come before generic ones so "overdue bills" never lands on invoices.
     *
     * @var list<Question>
     */
    public const QUESTIONS = [
        ['id' => 'enquiry_diagnostics', 'label' => 'Check enquiry capture and alerts', 'permission' => 'system.health', 'keywords' => ['enquiry health', 'enquiry diagnostics', 'contact form', 'send enquiry', 'lead capture', 'missing enquiry', 'lead notification']],
        ['id' => 'website_diagnostics', 'label' => 'Check website search metadata', 'permission' => 'website.edit', 'keywords' => ['website diagnostics', 'seo diagnostics', 'search metadata', 'website seo', 'canonical health']],
        ['id' => 'runtime_diagnostics', 'label' => 'Check PHP, database and sign-in health', 'permission' => 'system.health', 'keywords' => ['runtime diagnostics', 'diagnose runtime', 'php engine', 'database engine', 'system health']],
        ['id' => 'connection_diagnostics', 'label' => 'Check connection setup safely', 'permission' => 'integrations.manage', 'keywords' => ['connectivity', 'connection status', 'connection setup', 'telegram status', 'provider status']],
        ['id' => 'bills_to_pay', 'label' => 'What do we owe suppliers?', 'permission' => 'accounts.view', 'keywords' => ['bill', 'payable', 'supplier', 'we owe', 'to pay']],
        ['id' => 'overdue_invoices', 'label' => 'What is overdue?', 'permission' => 'invoices.view', 'keywords' => ['overdue', 'late invoice', 'unpaid', 'outstanding']],
        ['id' => 'due_this_week', 'label' => 'What is due this week?', 'permission' => 'invoices.view', 'keywords' => ['due this week', 'due soon', 'coming due', 'due date']],
        ['id' => 'collected_this_month', 'label' => 'How much did we collect this month?', 'permission' => 'invoices.view', 'keywords' => ['collect', 'received this month', 'money in', 'payments this month', 'income this month']],
        ['id' => 'follow_ups_due', 'label' => 'Which leads need follow-up?', 'permission' => 'leads.view', 'keywords' => ['follow', 'chase', 'sla']],
        ['id' => 'new_enquiries', 'label' => 'New enquiries this week', 'permission' => 'leads.view', 'keywords' => ['new enquiries', 'new enquiry', 'new leads', 'enquiries this week', 'leads this week']],
        ['id' => 'quotes_waiting', 'label' => 'Quotes waiting on the client', 'permission' => 'quotes.view', 'keywords' => ['quote', 'quotation', 'proposal']],
        ['id' => 'tasks_overdue', 'label' => 'What tasks are overdue?', 'permission' => 'leads.view', 'keywords' => ['task']],
        ['id' => 'milestones_due', 'label' => 'Milestones due this week', 'permission' => 'projects.view', 'keywords' => ['milestone', 'project due']],
    ];

    /**
     * Questions this user may ask, for the drawer's chips.
     *
     * @return list<Question>
     */
    public static function questionsFor(User $user): array
    {
        return array_values(array_filter(self::QUESTIONS, static fn (array $q): bool => $user->can($q['permission'])));
    }

    public function ask(string $text, User $user): AssistantAnswer
    {
        $question = $this->match($text);

        if ($question === null) {
            return AssistantAnswer::refusal("I don't have a query for that. I can answer: ".$this->askableList($user).' — pick one below, or open the relevant page.');
        }

        if (! $user->can($question['permission'])) {
            return AssistantAnswer::refusal("That answer needs the {$question['permission']} permission, which your account doesn't have — I won't show records your pages can't either.");
        }

        return match ($question['id']) {
            'enquiry_diagnostics' => app(Diagnostics::class)->enquiries($user),
            'website_diagnostics' => app(Diagnostics::class)->website($user),
            'runtime_diagnostics' => app(Diagnostics::class)->runtime($user),
            'connection_diagnostics' => app(Diagnostics::class)->connections($user),
            'overdue_invoices' => $this->overdueInvoices(),
            'due_this_week' => $this->dueThisWeek(),
            'collected_this_month' => $this->collectedThisMonth(),
            'bills_to_pay' => $this->billsToPay(),
            'follow_ups_due' => $this->followUpsDue($user),
            'new_enquiries' => $this->newEnquiries($user),
            'quotes_waiting' => $this->quotesWaiting($user),
            'tasks_overdue' => $this->tasksOverdue(),
            'milestones_due' => $this->milestonesDue(),
            default => AssistantAnswer::refusal("I don't have a query for that yet."),
        };
    }

    /**
     * @return Question|null
     */
    private function match(string $text): ?array
    {
        $text = trim($text);

        foreach (self::QUESTIONS as $question) {
            if ($question['id'] === $text) {
                return $question;
            }
        }

        $needle = Str::lower($text);

        foreach (self::QUESTIONS as $question) {
            foreach ($question['keywords'] as $keyword) {
                if (str_contains($needle, $keyword)) {
                    return $question;
                }
            }
        }

        return null;
    }

    private function askableList(User $user): string
    {
        $labels = array_map(static fn (array $q): string => '“'.Str::rtrim($q['label'], '?').'”', self::questionsFor($user));

        return $labels === [] ? 'nothing yet for your permissions' : collect($labels)->join(', ', ' and ');
    }

    private function overdueInvoices(): AssistantAnswer
    {
        $query = Invoice::query()->overdue()->with('contact:id,name')->orderBy('due_date');
        $count = (clone $query)->count();
        $total = (int) (clone $query)->sum('balance_minor');
        $rows = $query->limit(self::ROW_LIMIT)->get()->map(fn (Invoice $i): array => [
            'label' => $i->number.' · '.($i->contact->name ?? '—'),
            'detail' => $this->money((int) $i->balance_minor).' · due '.$i->due_date?->format('j M Y'),
            'url' => route('admin.invoices.show', $i),
        ])->all();

        return new AssistantAnswer(
            $count === 0
                ? 'Nothing is overdue — no issued invoice is past its due date with a balance.'
                : $count.' '.Str::plural('invoice', $count).' overdue, '.$this->money($total).' outstanding. Oldest first'.($count > self::ROW_LIMIT ? '; showing the first '.self::ROW_LIMIT : '').'.',
            $rows,
            'Invoices · the same overdue rule as Invoice tracking',
            totalMinor: $total,
        );
    }

    private function dueThisWeek(): AssistantAnswer
    {
        $query = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('balance_minor', '>', 0)
            ->whereDate('due_date', '>=', today())
            ->whereDate('due_date', '<=', today()->addDays(7))
            ->with('contact:id,name')->orderBy('due_date');
        $count = (clone $query)->count();
        $total = (int) (clone $query)->sum('balance_minor');
        $rows = $query->limit(self::ROW_LIMIT)->get()->map(fn (Invoice $i): array => [
            'label' => $i->number.' · '.($i->contact->name ?? '—'),
            'detail' => $this->money((int) $i->balance_minor).' · due '.$i->due_date?->format('j M Y'),
            'url' => route('admin.invoices.show', $i),
        ])->all();

        return new AssistantAnswer(
            $count === 0
                ? 'Nothing falls due in the next seven days.'
                : $count.' '.Str::plural('invoice', $count).' due within seven days, '.$this->money($total).' expected.',
            $rows,
            'Invoices · open balances due in the next 7 days',
            totalMinor: $total,
        );
    }

    private function collectedThisMonth(): AssistantAnswer
    {
        $query = Payment::query()
            ->where('status', PaymentStatus::Verified->value)
            ->whereDate('received_on', '>=', today()->startOfMonth())
            ->whereDate('received_on', '<=', today())
            ->with('invoice:id,number,contact_id', 'invoice.contact:id,name')
            ->orderByDesc('received_on');
        $count = (clone $query)->count();
        $total = (int) (clone $query)->sum('amount_minor');
        $rows = $query->limit(self::ROW_LIMIT)->get()->map(fn (Payment $p): array => [
            'label' => ($p->receipt_number ?? 'Payment').' · '.($p->invoice->number ?? '—'),
            'detail' => $this->money((int) $p->amount_minor).' · '.($p->invoice->contact->name ?? '—').' · '.$p->received_on?->format('j M'),
            'url' => $p->invoice !== null ? route('admin.invoices.show', $p->invoice) : route('admin.payments.index'),
        ])->all();

        return new AssistantAnswer(
            $count === 0
                ? 'No verified payment has been received in '.today()->format('F').' yet.'
                : $this->money($total).' collected in '.today()->format('F').' across '.$count.' verified '.Str::plural('payment', $count).'.',
            $rows,
            'Payments · verified, received this month',
            totalMinor: $total,
        );
    }

    private function billsToPay(): AssistantAnswer
    {
        $query = Bill::query()
            ->whereIn('status', [BillStatus::Received->value, BillStatus::PartiallyPaid->value])
            ->with('vendor:id,name')->orderBy('due_date');
        $count = (clone $query)->count();
        $total = (int) (clone $query)->sum(DB::raw('total_minor - paid_minor'));
        $rows = $query->limit(self::ROW_LIMIT)->get()->map(fn (Bill $b): array => [
            'label' => $b->number.' · '.($b->vendor->name ?? '—'),
            'detail' => $this->money($b->balanceMinor()).' outstanding'.($b->due_date !== null ? ' · due '.$b->due_date->format('j M Y') : ''),
            'url' => route('admin.accounts.payables', ['tab' => 'bills']),
        ])->all();

        return new AssistantAnswer(
            $count === 0
                ? 'Nothing is owed to suppliers — no received bill has a balance.'
                : $this->money($total).' owed across '.$count.' open '.Str::plural('bill', $count).'. Earliest due first.',
            $rows,
            'Payables · received and part-paid bills',
            totalMinor: $total,
        );
    }

    private function followUpsDue(User $user): AssistantAnswer
    {
        $query = Lead::query()->visibleTo($user)->followUpDue()->with('contact:id,name')->orderBy('next_action_at');
        $count = (clone $query)->count();
        $rows = $query->limit(self::ROW_LIMIT)->get()->map(fn (Lead $l): array => [
            'label' => $l->ref.' · '.($l->contact->name ?? '—'),
            'detail' => ($l->next_action_at !== null ? 'next action was '.$l->next_action_at->diffForHumans() : 'first reply overdue'),
            'url' => route('admin.leads.show', $l),
        ])->all();

        return new AssistantAnswer(
            $count === 0
                ? 'No lead is waiting on a follow-up — every next action and first reply is in time.'
                : $count.' '.Str::plural('lead', $count).' need'.($count === 1 ? 's' : '').' a follow-up now.',
            $rows,
            'Leads · the same follow-up rule as the dashboard and Leads list',
        );
    }

    private function newEnquiries(User $user): AssistantAnswer
    {
        $query = Lead::query()->visibleTo($user)->where('created_at', '>=', now()->subDays(7))->with('contact:id,name')->latest('id');
        $count = (clone $query)->count();
        $rows = $query->limit(self::ROW_LIMIT)->get()->map(fn (Lead $l): array => [
            'label' => $l->ref.' · '.($l->contact->name ?? '—'),
            'detail' => $l->source->label().' · '.$l->created_at->format('j M'),
            'url' => route('admin.leads.show', $l),
        ])->all();

        return new AssistantAnswer(
            $count === 0
                ? 'No new enquiries in the last seven days.'
                : $count.' new '.Str::plural('enquiry', $count).' in the last seven days.',
            $rows,
            'Leads · created in the last 7 days',
        );
    }

    private function quotesWaiting(User $user): AssistantAnswer
    {
        $query = Quote::query()->visibleTo($user)
            ->whereIn('status', [QuoteStatus::Sent->value, QuoteStatus::Viewed->value])
            ->with('contact:id,name')->orderBy('valid_until');
        $count = (clone $query)->count();
        $total = (int) (clone $query)->sum('total_minor');
        $rows = $query->limit(self::ROW_LIMIT)->get()->map(fn (Quote $q): array => [
            'label' => $q->number.' · '.($q->contact->name ?? '—'),
            'detail' => $this->money((int) $q->total_minor).' · '.$q->status->label().($q->valid_until !== null ? ' · valid until '.$q->valid_until->format('j M Y') : ''),
            'url' => route('admin.quotes.show', ['quote' => $q->id]),
        ])->all();

        return new AssistantAnswer(
            $count === 0
                ? 'No quotation is sitting with a client right now.'
                : $count.' '.Str::plural('quotation', $count).' with the client, '.$this->money($total).' in play. Soonest to lapse first.',
            $rows,
            'Quotations · sent or viewed, not yet answered',
            totalMinor: $total,
        );
    }

    private function tasksOverdue(): AssistantAnswer
    {
        $query = Task::query()->where('status', TaskStatus::Open)->where('due_at', '<', now())->with('lead:id,number')->orderBy('due_at');
        $count = (clone $query)->count();
        $rows = $query->limit(self::ROW_LIMIT)->get()->map(fn (Task $t): array => [
            'label' => $t->title,
            'detail' => 'was due '.$t->due_at?->diffForHumans().($t->lead !== null ? ' · '.$t->lead->ref : ''),
            'url' => route('admin.tasks.index'),
        ])->all();

        return new AssistantAnswer(
            $count === 0
                ? 'No task is overdue.'
                : $count.' open '.Str::plural('task', $count).' past '.($count === 1 ? 'its' : 'their').' due time.',
            $rows,
            'Tasks · open and past due',
        );
    }

    private function milestonesDue(): AssistantAnswer
    {
        $query = ProjectMilestone::query()
            ->whereNull('reached_at')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', today()->addDays(7))
            ->with('project:id,code,name')->orderBy('due_date');
        $count = (clone $query)->count();
        $total = (int) (clone $query)->sum('amount_minor');
        $rows = $query->limit(self::ROW_LIMIT)->get()->map(fn (ProjectMilestone $m): array => [
            'label' => ($m->project->code ?? '—').' · '.$m->name,
            'detail' => $this->money((int) $m->amount_minor).' · due '.$m->due_date?->format('j M Y'),
            'url' => $m->project !== null ? route('admin.projects.show', $m->project) : route('admin.projects.index'),
        ])->all();

        return new AssistantAnswer(
            $count === 0
                ? 'No project milestone is due in the next seven days.'
                : $count.' '.Str::plural('milestone', $count).' due within seven days (or already), '.$this->money($total).' of stage value.',
            $rows,
            'Projects · milestones not yet reached',
            totalMinor: $total,
        );
    }

    private function money(int $minor): string
    {
        return Money::ofMinor($minor)->format(app(SalesSettings::class)->lakh_grouping);
    }
}
