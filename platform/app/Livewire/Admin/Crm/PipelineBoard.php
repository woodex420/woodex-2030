<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use App\Domain\Crm\Actions\MoveLeadToStage;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Crm\Models\SiteVisit;
use App\Livewire\Concerns\BooksSiteVisits;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kanban pipeline (CRM-004, CRM-005). Drag a card to change stage; Lost asks for a reason;
 * Won requires an accepted quotation (or the owner); Site visit without a booked visit opens the
 * booking modal, and cancelling it puts the card back (the board re-renders from the database).
 */
#[Layout('layouts::admin')]
#[Title('Pipeline')]
class PipelineBoard extends Component
{
    use BooksSiteVisits, InteractsWithAdmin;

    #[Url(except: '')]
    public string $line = '';

    #[Url(except: '')]
    public string $owner = '';

    public ?int $pendingLeadId = null;

    public string $lostReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Lead::class);
    }

    public function moveLead(int $leadId, string $stageKey): void
    {
        $lead = Lead::query()->with('stage')->findOrFail($leadId);
        $this->authorize('update', $lead);

        $stage = PipelineStage::query()->where('key', $stageKey)->firstOrFail();

        if ($stage->type === StageType::Lost) {
            $this->pendingLeadId = $lead->id;
            $this->lostReason = '';

            return;
        }

        if ($stage->key === 'site_visit' && ! $lead->siteVisits()->upcoming()->exists()) {
            $this->openVisitFor($lead);

            return;
        }

        try {
            app(MoveLeadToStage::class)->handle($lead, $stage, $this->user());
            $this->dispatch('toast', message: "{$lead->ref} moved to {$stage->name}.");
        } catch (ValidationException $exception) {
            $this->dispatch('toast', message: (string) collect($exception->errors())->flatten()->first(), type: 'error');
        }
    }

    public function confirmLost(): void
    {
        $this->validate(['lostReason' => ['required', 'string', 'max:60']], attributes: ['lostReason' => 'reason']);

        $lead = Lead::query()->with('stage')->findOrFail((int) $this->pendingLeadId);
        $this->authorize('update', $lead);

        app(MoveLeadToStage::class)->handle($lead, PipelineStage::byKey('lost'), $this->user(), $this->lostReason);

        $this->reset('pendingLeadId', 'lostReason');
        $this->dispatch('toast', message: "{$lead->ref} marked as lost.");
    }

    public function cancelLost(): void
    {
        $this->reset('pendingLeadId', 'lostReason');
    }

    protected function visitBooked(Lead $lead, SiteVisit $visit): void
    {
        // Booking moves New/Contacted leads itself; a lead dragged back from a later stage moves here.
        if ($lead->stage->type === StageType::Open && $lead->stage->key !== 'site_visit') {
            app(MoveLeadToStage::class)->handle($lead, PipelineStage::byKey('site_visit'), $this->user());
        }

        $this->toast("{$lead->ref}: visit booked for ".$visit->scheduled_at->format('D j M, g:i a').'.');
    }

    public function render(): View
    {
        $user = $this->user();
        $lakh = app(SalesSettings::class)->lakh_grouping;
        $stages = PipelineStage::query()->ordered()->get();

        $leads = Lead::query()
            ->with(['contact', 'owner'])
            ->whereIn('stage_id', $stages->modelKeys())
            ->when(! $user->can('leads.view_all'), fn (Builder $q) => $q->where('owner_id', $user->id))
            ->when($this->line !== '', fn (Builder $q) => $q->where('business_line', $this->line))
            ->when($this->owner === 'me', fn (Builder $q) => $q->where('owner_id', $user->id))
            // Closed columns show the last 30 days only.
            ->where(fn (Builder $q) => $q->whereNull('won_at')->orWhere('won_at', '>=', now()->subDays(30)))
            ->where(fn (Builder $q) => $q->whereNull('lost_at')->orWhere('lost_at', '>=', now()->subDays(30)))
            ->orderByDesc('score')
            ->orderBy('next_action_at')
            ->limit(500)
            ->get()
            ->groupBy('stage_id');

        $totals = [];
        foreach ($stages as $stage) {
            $value = (int) ($leads->get($stage->id)?->sum('expected_value_minor') ?? 0);
            $totals[$stage->id] = $value > 0 ? Money::ofMinor($value)->format($lakh) : null;
        }

        return view('livewire.admin.crm.pipeline-board', [
            'stages' => $stages,
            'leadsByStage' => $leads,
            'totals' => $totals,
            'lines' => BusinessLine::options(),
            'lostReasons' => app(SalesSettings::class)->lost_reasons,
            'staff' => $this->showVisit ? $this->staff() : collect(),
        ]);
    }
}
