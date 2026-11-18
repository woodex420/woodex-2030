<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Domain\Crm\Actions\ScheduleSiteVisit;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\SiteVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

/**
 * "Book a site visit" modal shared by the lead page, the pipeline board and the visit calendar (CRM-008).
 * A double booking for the same team member shows a warning first; submitting again books anyway.
 *
 * Components using it render <x-admin.visit-modal :staff="$staff" :conflicts="$visitConflicts" /> and may
 * override visitBooked() to react (e.g. the board moves the card to "Site visit").
 */
trait BooksSiteVisits
{
    public bool $showVisit = false;

    #[Locked]
    public ?int $visitLeadId = null;

    public string $visitAt = '';

    public int $visitDuration = 60;

    public string $visitAddress = '';

    public string $visitAssignee = '';

    /** @var list<string> */
    public array $visitConflicts = [];

    #[Locked]
    public ?string $visitConflictKey = null;

    protected function openVisitFor(Lead $lead, ?CarbonImmutable $at = null): void
    {
        $this->authorize('update', $lead);

        $this->visitLeadId = $lead->id;
        $this->visitAt = ($at ?? CarbonImmutable::now()->addDay()->setTime(11, 0))->format('Y-m-d\TH:i');
        $this->visitDuration = 60;
        $this->visitAddress = (string) ($lead->address ?? trim(($lead->area ?? '').', '.($lead->city ?? ''), ', '));
        $this->visitAssignee = (string) ($lead->owner_id ?? $this->user()->id);
        $this->visitConflicts = [];
        $this->visitConflictKey = null;
        $this->resetValidation();
        $this->showVisit = true;
    }

    public function scheduleVisit(): void
    {
        $lead = Lead::query()->with(['stage', 'owner'])->findOrFail((int) $this->visitLeadId);
        $this->authorize('update', $lead);

        $this->validate([
            'visitAt' => ['required', 'date', 'after:now'],
            'visitDuration' => ['required', 'integer', 'between:15,480'],
            'visitAddress' => ['nullable', 'string', 'max:255'],
            'visitAssignee' => ['nullable', Rule::exists('users', 'id')->where('is_active', true)],
        ], attributes: ['visitAt' => 'visit time', 'visitAssignee' => 'team member']);

        $assignee = $this->visitAssignee !== '' ? User::query()->find((int) $this->visitAssignee) : null;
        $at = Carbon::parse($this->visitAt);

        // Conflict warning (CRM-008): show it once for this exact slot, then allow "book anyway".
        $key = md5($this->visitAt.'|'.$this->visitDuration.'|'.$this->visitAssignee);
        if ($assignee !== null && $this->visitConflictKey !== $key) {
            $conflicts = SiteVisit::overlapping($assignee->id, CarbonImmutable::instance($at), $this->visitDuration);
            if ($conflicts->isNotEmpty()) {
                $this->visitConflictKey = $key;
                $this->visitConflicts = $conflicts->map(fn (SiteVisit $visit): string => sprintf(
                    '%s already has a visit %s–%s with %s.',
                    $assignee->name,
                    $visit->scheduled_at->format('g:i a'),
                    $visit->endsAt()->format('g:i a'),
                    $visit->contact->name ?? 'a client',
                ))->values()->all();

                return;
            }
        }

        $visit = app(ScheduleSiteVisit::class)->handle(
            $lead,
            $at,
            $this->visitDuration,
            $this->visitAddress !== '' ? $this->visitAddress : null,
            $assignee,
            $this->user(),
        );

        $this->reset('showVisit', 'visitLeadId', 'visitAt', 'visitAddress', 'visitAssignee', 'visitConflicts', 'visitConflictKey');
        $this->visitBooked($lead->refresh(), $visit);
    }

    public function cancelVisit(): void
    {
        $this->reset('showVisit', 'visitLeadId', 'visitAt', 'visitAddress', 'visitAssignee', 'visitConflicts', 'visitConflictKey');
    }

    protected function visitBooked(Lead $lead, SiteVisit $visit): void
    {
        $this->toast('Site visit booked for '.$visit->scheduled_at->format('D j M, g:i a').'.');
    }
}
