<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use App\Domain\Crm\Actions\AssignLead;
use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Actions\LogLeadActivity;
use App\Domain\Crm\Actions\MoveLeadToStage;
use App\Domain\Crm\Actions\SetNextAction;
use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\CallOutcome;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Enums\NextActionType;
use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Livewire\Concerns\HasSavedViews;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Models\User;
use App\Support\Money;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Leads list (CRM-003, CAP-008, GLB-003): search by name / phone in any format / reference, filters,
 * sort, page size, column chooser, saved views, CSV export and bulk assign / move / delete.
 */
#[Layout('layouts::admin')]
#[Title('Leads')]
class LeadsIndex extends Component
{
    use HasSavedViews, InteractsWithAdmin, WithPagination;

    /** @var array<string, string> optional columns */
    public const COLUMNS = ['contact' => 'Contact', 'line' => 'Line · Service', 'source' => 'Source', 'stage' => 'Stage', 'owner' => 'Owner', 'score' => 'Score', 'next' => 'Next action'];

    /** @var array<string, string> */
    public const SORTS = ['newest' => 'Newest first', 'oldest' => 'Oldest first', 'score' => 'Highest score', 'next_action' => 'Next action due', 'name' => 'Name A–Z', 'value' => 'Expected value'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $stage = '';

    #[Url(except: '')]
    public string $line = '';

    #[Url(except: '')]
    public string $source = '';

    #[Url(except: '')]
    public string $owner = '';

    /**
     * Quick filters the control view links to (deep QA slice D): `created=today|week` is the same
     * "enquiries today" the dashboard counts, `due=overdue` is Lead::followUpDue() — the tile, the
     * list and the CSV all run one query.
     */
    #[Url(except: '')]
    public string $created = '';

    #[Url(except: '')]
    public string $due = '';

    /** Slice V: the v2.1 header month — `Y-m`, read in Asia/Karachi like the dates the list prints. */
    #[Url(except: '')]
    public string $month = '';

    #[Url(except: 'newest')]
    public string $sort = 'newest';

    #[Url(except: 25)]
    public int $perPage = 25;

    /** @var list<string> */
    public array $columns = ['contact', 'line', 'source', 'stage', 'owner', 'next'];

    /** @var list<int|string> lead ids ticked for bulk actions */
    public array $selected = [];

    public bool $showCreate = false;

    public string $newName = '';

    public string $newPhone = '';

    public string $newEmail = '';

    public string $newLine = 'interior';

    public string $newService = '';

    public string $newSource = 'manual';

    public string $newMessage = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Lead::class);
        $this->applyDefaultView();

        // GLB-016: the 'N' shortcut lands on /leads?new=1 — open the composer.
        if (request()->boolean('new') && $this->user()->can('create', Lead::class)) {
            $this->showCreate = true;
        }
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'stage', 'line', 'source', 'owner', 'created', 'due', 'month', 'sort', 'perPage'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array($this->perPage, [25, 50, 100], true) ? $this->perPage : 25;
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'stage', 'line', 'source', 'owner', 'created', 'due', 'month', 'sort', 'selected');
        $this->resetPage();
    }

    /** The chip's ✕ — clears the control-view filters and leaves the rest of the list alone. */
    public function clearQuickFilters(): void
    {
        $this->reset('created', 'due');
        $this->resetPage();
    }

    public function toggleColumn(string $column): void
    {
        if (! array_key_exists($column, self::COLUMNS)) {
            return;
        }

        $this->columns = in_array($column, $this->columns, true)
            ? array_values(array_diff($this->columns, [$column]))
            : array_values(array_intersect(array_keys(self::COLUMNS), [...$this->columns, $column]));
    }

    protected function savedViewScreen(): string
    {
        return 'leads';
    }

    protected function savedViewProperties(): array
    {
        return ['search', 'stage', 'line', 'source', 'owner', 'month', 'sort', 'perPage', 'columns'];
    }

    public function bulkAssign(string $userId): void
    {
        $this->authorize('assign', Lead::class);
        $to = User::query()->where('is_active', true)->findOrFail((int) $userId);

        $count = 0;
        foreach ($this->selectedLeads() as $lead) {
            if ($lead->owner_id !== $to->id) {
                app(AssignLead::class)->handle($lead, $to, $this->user(), "Bulk assigned to {$to->name}");
                $count++;
            }
        }

        $this->selected = [];
        $this->toast("{$count} ".str('lead')->plural($count)." assigned to {$to->name}.");
    }

    public function bulkMove(string $stageKey): void
    {
        $stage = PipelineStage::query()->where('key', $stageKey)->where('type', StageType::Open->value)->firstOrFail();

        $moved = 0;
        foreach ($this->selectedLeads() as $lead) {
            if ($this->user()->can('update', $lead) && $lead->stage_id !== $stage->id && $lead->stage->type === StageType::Open) {
                app(MoveLeadToStage::class)->handle($lead, $stage, $this->user());
                $moved++;
            }
        }

        $this->selected = [];
        $this->toast("{$moved} ".str('lead')->plural($moved)." moved to {$stage->name}.");
    }

    // ---------------------------------------------------------------------------------------------------
    // Client drawer (owner request, 2026-10-20): clicking a name opens the lead on the right of the list,
    // and stage/owner changes save in place. The full page stays one click away for notes and history.
    // ---------------------------------------------------------------------------------------------------

    public ?int $drawerId = null;

    public ?int $drawerStage = null;

    public ?int $drawerOwner = null;

    // Slice V: the drawer works the lead, not just reads it — Details / Log activity / Timeline tabs
    // over the same pinned fields, the same actions the full page uses (LogLeadActivity, SetNextAction).
    public string $drawerTab = 'details';

    public string $drawerLogType = 'call';

    public string $drawerLogOutcome = 'connected';

    public string $drawerLogBody = '';

    public string $drawerNextType = 'call';

    public string $drawerNextAt = '';

    public function openLeadDrawer(int $lead): void
    {
        $found = Lead::query()->findOrFail($lead);
        $this->authorize('view', $found);

        $this->drawerId = $found->id;
        $this->drawerStage = $found->stage_id;
        $this->drawerOwner = $found->owner_id;
        $this->drawerTab = 'details';
        $this->reset('drawerLogType', 'drawerLogOutcome', 'drawerLogBody');
        $this->drawerNextType = $found->next_action_type->value ?? NextActionType::Call->value;
        $this->drawerNextAt = ($found->next_action_at ?? CarbonImmutable::now()->addHour()->startOfHour())->format('Y-m-d\TH:i');
        $this->resetValidation();
    }

    public function closeLeadDrawer(): void
    {
        $this->drawerId = null;
        $this->drawerStage = null;
        $this->drawerOwner = null;
    }

    public function updateDrawerStage(): void
    {
        $lead = Lead::query()->findOrFail($this->drawerId);
        $this->authorize('update', $lead);

        $stage = PipelineStage::query()->findOrFail((int) $this->drawerStage);

        try {
            app(MoveLeadToStage::class)->handle(
                $lead,
                $stage,
                $this->user(),
                null,
                $this->user()->hasRole('owner'),
            );
        } catch (ValidationException $exception) {
            $this->drawerStage = $lead->stage_id;
            $this->toastValidation($exception);

            return;
        }

        $this->drawerStage = $lead->refresh()->stage_id;
        $this->toast("{$lead->ref} moved to {$stage->name}.");
    }

    public function updateDrawerOwner(): void
    {
        $lead = Lead::query()->findOrFail($this->drawerId);
        $this->authorize('assign', Lead::class);

        $owner = User::query()->where('is_active', true)->findOrFail((int) $this->drawerOwner);

        if ($lead->owner_id !== $owner->id) {
            app(AssignLead::class)->handle($lead, $owner, $this->user(), 'Reassigned from the lead drawer');
        }

        $this->drawerOwner = $lead->refresh()->owner_id;
        $this->toast("{$lead->ref} assigned to {$owner->name}.");
    }

    /**
     * The drawer's Log activity tab: the activity is written first, then the next action —
     * the timeline always explains why the follow-up changed. Stage moves stay on Details.
     */
    public function drawerLog(): void
    {
        $lead = Lead::query()->findOrFail($this->drawerId);
        $this->authorize('update', $lead);

        $this->validate([
            'drawerLogType' => ['required', Rule::in(['call', 'whatsapp', 'email', 'meeting', 'note'])],
            'drawerLogOutcome' => [Rule::requiredIf($this->drawerLogType === 'call'), 'nullable', Rule::enum(CallOutcome::class)],
            'drawerLogBody' => [Rule::requiredIf($this->drawerLogType === 'note'), 'nullable', 'string', 'max:5000'],
            'drawerNextType' => ['required', Rule::enum(NextActionType::class)],
            'drawerNextAt' => ['nullable', 'date'],
        ], attributes: ['drawerLogBody' => 'note', 'drawerLogOutcome' => 'call outcome', 'drawerNextAt' => 'next action time']);

        $type = ActivityType::from($this->drawerLogType);
        $outcome = $type === ActivityType::Call ? CallOutcome::from($this->drawerLogOutcome) : null;
        $action = app(LogLeadActivity::class);

        $action->handle($lead, $type, $this->user(), $this->drawerLogBody, $outcome);

        $nextAt = $this->drawerNextAt !== '' ? Carbon::parse($this->drawerNextAt) : null;
        if ($nextAt !== null) {
            app(SetNextAction::class)->handle($lead->refresh(), NextActionType::from($this->drawerNextType), $nextAt);
        }

        $this->reset('drawerLogBody');
        $this->drawerStage = $lead->refresh()->stage_id;

        if ($type === ActivityType::Call && $action->consecutiveNoAnswers($lead) >= 3) {
            $this->toast('Three calls without an answer. Try a WhatsApp message instead.', 'warning');

            return;
        }

        $message = match ($type) {
            ActivityType::Call => 'Call logged: '.$outcome?->label().'.',
            ActivityType::Note => 'Note added.',
            default => $type->label().' logged.',
        };
        $this->toast($nextAt !== null
            ? $message.' Next: '.NextActionType::from($this->drawerNextType)->label().' · '.$nextAt->timezone('Asia/Karachi')->format('D j M, g:i a').'.'
            : $message);
    }

    /** Quick chips set a next action only when the lead has none — they never erase one someone planned. */
    public function drawerQuickNext(string $preset): void
    {
        $lead = Lead::query()->findOrFail($this->drawerId);
        $this->authorize('update', $lead);

        if ($lead->next_action_at !== null) {
            $this->toast("{$lead->ref} already has a next action (".($lead->next_action_type?->label() ?? 'Follow up').' · '.$lead->next_action_at->diffForHumans().') — kept.', 'warning');

            return;
        }

        $now = CarbonImmutable::now();
        $at = match ($preset) {
            '1h' => $now->addHour()->startOfMinute(),
            'tomorrow' => $now->addDay()->setTime(11, 0),
            '3d' => $now->addDays(3)->setTime(11, 0),
            'week' => $now->addWeek()->setTime(11, 0),
            default => null,
        };

        if ($at === null) {
            return;
        }

        $type = NextActionType::tryFrom($this->drawerNextType) ?? NextActionType::Call;
        app(SetNextAction::class)->handle($lead, $type, $at);

        $this->drawerNextType = $type->value;
        $this->drawerNextAt = $at->format('Y-m-d\TH:i');
        $this->toast('Next action: '.$type->label().' · '.$at->timezone('Asia/Karachi')->format('D j M, g:i a').'.');
    }

    public function bulkDelete(): void
    {
        $leads = $this->selectedLeads();
        foreach ($leads as $lead) {
            $this->authorize('delete', $lead);
        }

        $leads->each->delete();
        $this->selected = [];
        $this->toast($leads->count().' '.str('lead')->plural($leads->count()).' moved to the recycle bin.');
    }

    public function export(): StreamedResponse
    {
        abort_unless($this->user()->can('leads.export'), 403);

        $lakh = false;
        $query = $this->query()->with(['contact', 'stage', 'owner'])->limit(10000);
        $clean = static function (mixed $value): string {
            $value = (string) $value;

            // CSV injection guard: spreadsheet formulas never run from exported client data.
            return preg_match('/^[=+\-@\t\r]/', $value) && ! preg_match('/^\+\d+$/', $value) ? "'".$value : $value;
        };

        return response()->streamDownload(function () use ($query, $clean, $lakh): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['Reference', 'Created', 'Name', 'Phone', 'Email', 'Line', 'Service', 'Source', 'Stage', 'Owner', 'Score', 'Budget', 'Area (sq ft)', 'City', 'Area', 'Next action', 'Next action at', 'Expected value'], escape: '');

            foreach ($query->lazy(500) as $lead) {
                /** @var Lead $lead */
                fputcsv($out, array_map($clean, [
                    $lead->ref,
                    $lead->created_at?->format('Y-m-d H:i'),
                    $lead->contact->name,
                    $lead->contact->phone,
                    $lead->contact->email,
                    $lead->business_line->label(),
                    $lead->service,
                    $lead->source->label(),
                    $lead->stage->name,
                    $lead->owner->name ?? '',
                    $lead->score,
                    $lead->budget_band,
                    $lead->area_sqft,
                    $lead->city,
                    $lead->area,
                    $lead->next_action_type?->label(),
                    $lead->next_action_at?->format('Y-m-d H:i'),
                    $lead->expected_value_minor !== null ? Money::ofMinor($lead->expected_value_minor)->format($lakh) : '',
                ]), escape: '');
            }

            fclose($out);
        }, 'woodex-leads-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return Collection<int, Lead>
     */
    private function selectedLeads(): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $this->selected)));

        return $this->query()->with(['stage', 'owner'])->whereIn('leads.id', $ids)->get();
    }

    /**
     * The filtered, permission-scoped query shared by the table, export and bulk actions.
     *
     * @return Builder<Lead>
     */
    private function query(): Builder
    {
        $user = $this->user();
        $term = trim($this->search);

        return Lead::query()
            ->when(! $user->can('leads.view_all'), fn (Builder $q) => $q->where('owner_id', $user->id))
            ->when($term !== '', function (Builder $q) use ($term): void {
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('ref', 'like', "%{$term}%")
                        ->orWhereHas('contact', fn (Builder $contact) => $contact->search($term));
                });
            })
            ->when($this->stage !== '', fn (Builder $q) => $q->whereHas('stage', fn (Builder $s) => $s->where('key', $this->stage)))
            ->when($this->line !== '', fn (Builder $q) => $q->where('business_line', $this->line))
            ->when($this->source !== '', fn (Builder $q) => $q->where('source', $this->source))
            ->when($this->owner === 'me', fn (Builder $q) => $q->where('owner_id', $user->id))
            ->when($this->owner === 'unassigned', fn (Builder $q) => $q->whereNull('owner_id'))
            ->when($this->created === 'today', fn (Builder $q) => $q->where('created_at', '>=', now()->startOfDay()))
            ->when($this->created === 'week', fn (Builder $q) => $q->where('created_at', '>=', now()->startOfWeek()))
            ->when($this->due === 'overdue', fn (Builder $q) => $q->followUpDue())
            ->when(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $this->month) === 1, function (Builder $q): void {
                $start = CarbonImmutable::createFromFormat('!Y-m', $this->month, 'Asia/Karachi')->utc();
                $q->where('created_at', '>=', $start)->where('created_at', '<', $start->addMonth());
            })
            ->tap(fn (Builder $q) => match ($this->sort) {
                'oldest' => $q->oldest('id'),
                'score' => $q->orderByDesc('score')->latest('id'),
                'next_action' => $q->orderByRaw('next_action_at is null')->orderBy('next_action_at'),
                'name' => $q->orderBy(Contact::query()->select('name')->whereColumn('contacts.id', 'leads.contact_id')->limit(1)),
                'value' => $q->orderByRaw('expected_value_minor is null')->orderByDesc('expected_value_minor'),
                default => $q->latest('id'),
            });
    }

    public function createLead(): void
    {
        $this->authorize('create', Lead::class);

        $this->validate([
            'newName' => ['required', 'string', 'min:2', 'max:160'],
            'newPhone' => ['nullable', 'required_without:newEmail', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && ! Phone::isValid((string) $value)) {
                    $fail('Enter a valid phone number, e.g. 0322 4000768.');
                }
            }],
            'newEmail' => ['nullable', 'required_without:newPhone', 'email', 'max:160'],
            'newLine' => ['required', Rule::enum(BusinessLine::class)],
            'newService' => ['nullable', 'string', 'max:60'],
            'newSource' => ['required', Rule::in(array_map(fn (LeadSource $s) => $s->value, LeadSource::manualSources()))],
            'newMessage' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['newName' => 'name', 'newPhone' => 'phone', 'newEmail' => 'email']);

        $user = $this->user();

        $result = app(CaptureLead::class)->handle(new LeadInput(
            name: $this->newName,
            phone: $this->newPhone !== '' ? $this->newPhone : null,
            email: $this->newEmail !== '' ? $this->newEmail : null,
            source: LeadSource::from($this->newSource),
            businessLine: BusinessLine::from($this->newLine),
            service: $this->newService !== '' ? $this->newService : null,
            message: $this->newMessage !== '' ? $this->newMessage : null,
            ownerId: $user->hasRole('sales') ? $user->id : null,
        ));

        $this->reset('showCreate', 'newName', 'newPhone', 'newEmail', 'newService', 'newMessage');
        $this->newLine = 'interior';
        $this->newSource = 'manual';

        $this->dispatch('toast',
            message: $result->leadCreated
                ? "Lead {$result->lead->ref} created."
                : "This contact already has an open lead ({$result->lead->ref}); the enquiry was added to it.",
            type: $result->leadCreated ? 'success' : 'warning',
        );

        $this->redirectRoute('admin.leads.show', $result->lead, navigate: true);
    }

    public function render(): View
    {
        $user = $this->user();
        $leads = $this->query()->with(['contact', 'stage', 'owner'])->paginate(in_array($this->perPage, [25, 50, 100], true) ? $this->perPage : 25);

        $drawerActivities = collect();
        $drawerActivityCount = 0;
        if ($this->drawerId !== null && ($drawerLead = Lead::query()->find($this->drawerId)) !== null) {
            $this->authorize('view', $drawerLead);
            $drawerTimeline = Activity::query()
                ->where(fn (Builder $q) => $q->where('lead_id', $drawerLead->id)
                    ->orWhere(fn (Builder $c) => $c->where('contact_id', $drawerLead->contact_id)->whereNull('lead_id')));
            $drawerActivityCount = (clone $drawerTimeline)->count();
            $drawerActivities = $drawerTimeline->with('user:id,name')->latest('occurred_at')->latest('id')->limit(30)->get();
        }

        return view('livewire.admin.crm.leads-index', [
            'leads' => $leads,
            'workspaceStats' => [
                'matching' => $leads->total(),
                'due' => (clone $this->query())->followUpDue()->count(),
                'unassigned' => (clone $this->query())->whereNull('owner_id')->count(),
            ],
            'stages' => PipelineStage::query()->ordered()->get(),
            'lines' => BusinessLine::options(),
            'sources' => LeadSource::options(),
            'hasFilters' => trim($this->search) !== '' || $this->stage !== '' || $this->line !== '' || $this->source !== '' || $this->owner !== '' || $this->created !== '' || $this->due !== '' || $this->month !== '',
            'views' => $this->savedViews(),
            'staff' => $user->can('leads.assign') ? $this->staff() : collect(),
            'canExport' => $user->can('leads.export'),
            'canDelete' => $user->can('leads.delete'),
            'canImport' => $user->can('system.import'),
            'drawerActivities' => $drawerActivities,
            'drawerActivityCount' => $drawerActivityCount,
            'callOutcomes' => CallOutcome::options(),
            'nextTypes' => NextActionType::options(),
        ]);
    }
}
