<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use App\Domain\Crm\Actions\LogLeadActivity;
use App\Domain\Crm\Actions\SetNextAction;
use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\NextActionType;
use App\Domain\Crm\Models\Lead;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Support\Phone;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * P10.1: the lead tracking board (docs/27, owner-confirmed 17 Oct) — the CRM's follow-up tracker,
 * event-driven exactly like the P8.4 quote tracking board: no new states, no new columns. It reads
 * first_response_at, next_action_at and last_activity_at, so a lead moves because the team acted
 * or the client replied, never because somebody dragged a card. Alerts are passive (A0): the board
 * highlights; My Day and the dashboard carry the counts. Nothing is sent to anyone.
 */
#[Layout('layouts::admin')]
#[Title('Lead tracking')]
class LeadTrackingBoard extends Component
{
    use InteractsWithAdmin;

    /**
     * In the URL (slice D), so a count anywhere in the app can link straight to its column:
     * /admin/leads/board?group=uncontacted. Unknown values fall back to overdue.
     */
    #[Url(except: 'overdue')]
    public string $group = 'overdue';

    public int $logLeadId = 0;

    public string $logNote = '';

    public string $logType = 'call';

    public string $logNextAt = '';

    public string $logNextType = 'call';

    public function render(): View
    {
        $now = now();
        $endOfDay = $now->copy()->endOfDay();
        $staleBefore = $now->copy()->subDays(7);

        $leads = Lead::query()
            ->open()
            ->with(['contact:id,name,phone,whatsapp', 'stage:id,name', 'owner:id,name'])
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('first_response_at')
                ->orWhereNotNull('next_action_at')
                ->orWhere(fn (Builder $quiet) => $quiet
                    ->whereNull('next_action_at')
                    ->where(fn (Builder $silent) => $silent
                        ->whereNull('last_activity_at')
                        ->orWhere('last_activity_at', '<', $staleBefore))))
            ->orderByRaw('next_action_at is null')
            ->orderBy('next_action_at')
            ->limit(300)
            ->get();

        $groups = [
            'overdue' => ['label' => 'Overdue', 'hint' => 'The next action was due before now — chase it or move the date', 'leads' => collect()],
            'uncontacted' => ['label' => 'Uncontacted', 'hint' => 'No first reply yet — the clock is the SLA, the move is the first touch', 'leads' => collect()],
            'due_today' => ['label' => 'Due today', 'hint' => 'The next action lands later today', 'leads' => collect()],
            'stale' => ['label' => 'Stale', 'hint' => 'No next action planned and no activity for 7+ days — call or close', 'leads' => collect()],
            'snoozed' => ['label' => 'Snoozed', 'hint' => 'The next action is scheduled beyond today', 'leads' => collect()],
        ];

        foreach ($leads as $lead) {
            $next = $lead->next_action_at;
            $lastTouch = $lead->last_activity_at ?? $lead->created_at;

            if ($lead->first_response_at === null) {
                $groups['uncontacted']['leads']->push($lead);
            } elseif ($next !== null && $next->isPast()) {
                $groups['overdue']['leads']->push($lead);
            } elseif ($next !== null && $next->lessThanOrEqualTo($endOfDay)) {
                $groups['due_today']['leads']->push($lead);
            } elseif ($next !== null) {
                $groups['snoozed']['leads']->push($lead);
            } elseif ($lastTouch !== null && $lastTouch->lessThan($staleBefore)) {
                $groups['stale']['leads']->push($lead);
            }
        }

        foreach ($groups as $key => $group) {
            $groups[$key]['count'] = $group['leads']->count();
        }

        if (! isset($groups[$this->group])) {
            $this->group = 'overdue';
        }

        return view('livewire.admin.crm.lead-tracking-board', [
            'groups' => $groups,
            'active' => $groups[$this->group]['leads'],
            'canManage' => $this->user()->can('leads.update'),
        ]);
    }

    public function showGroup(string $group): void
    {
        if (array_key_exists($group, ['overdue' => 1, 'uncontacted' => 1, 'due_today' => 1, 'stale' => 1, 'snoozed' => 1])) {
            $this->group = $group;
        }
    }

    public function startLog(int $leadId): void
    {
        abort_unless($this->user()->can('leads.update'), 403);

        $lead = Lead::query()->findOrFail($leadId);

        $this->logLeadId = $lead->id;
        $this->logNote = '';
        $this->logType = 'call';
        $this->logNextAt = ($lead->next_action_at ?? Carbon::now()->addDay()->startOfHour())->format('Y-m-d\TH:i');
        $this->logNextType = $lead->next_action_type->value ?? 'call';
    }

    public function logFollowUp(): void
    {
        abort_unless($this->user()->can('leads.update'), 403);

        $validated = $this->validate([
            'logNote' => ['required', 'string', 'min:3', 'max:500'],
            'logType' => ['required', 'in:call,whatsapp,email,meeting,note'],
            'logNextAt' => ['required', 'date', 'after:now'],
            'logNextType' => ['required', 'in:call,whatsapp,visit,meeting,email'],
        ]);

        $lead = Lead::query()->findOrFail($this->logLeadId);

        app(LogLeadActivity::class)->handle(
            $lead,
            ActivityType::from($validated['logType']),
            $this->user(),
            $validated['logNote'],
        );

        app(SetNextAction::class)->handle(
            $lead,
            NextActionType::from($validated['logNextType']),
            Carbon::parse($validated['logNextAt']),
        );

        $this->logLeadId = 0;
        $this->logNote = '';

        $this->toast('Follow-up logged on '.$lead->ref.'.');
    }

    /**
     * The prefilled WhatsApp chase link, same wording the lead page uses.
     */
    public function whatsapp(Lead $lead): ?string
    {
        $contact = $lead->contact;

        if ($contact === null) {
            return null;
        }

        return Phone::whatsappUrl(
            $contact->whatsappNumber(),
            "Assalam o Alaikum {$contact->firstName()}, this is {$this->user()->name} from Woodex Interior regarding your enquiry ({$lead->ref}).",
        );
    }
}
