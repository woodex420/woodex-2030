<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use App\Domain\Crm\Actions\UpdateSiteVisit;
use App\Domain\Crm\Enums\VisitStatus;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\SiteVisit;
use App\Livewire\Concerns\InteractsWithAdmin;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Site visit agenda (CRM-008): confirm, reschedule, record the outcome and room measurements.
 * Completing a visit creates the "prepare quotation" task (48-hour turnaround target).
 */
#[Layout('layouts::admin')]
#[Title('Site visits')]
class VisitsIndex extends Component
{
    use InteractsWithAdmin;

    #[Url(except: 'mine')]
    public string $scope = 'mine';

    /** upcoming | past | week | month (calendar views, CRM-008) */
    #[Url(except: 'upcoming')]
    public string $range = 'upcoming';

    /** Calendar anchor date (Y-m-d); empty = today. */
    #[Url(except: '')]
    public string $date = '';

    public ?int $completingId = null;

    public string $notes = '';

    public string $nextStep = '';

    /** @var list<array{room: string, length_ft: string, width_ft: string}> */
    public array $measurements = [];

    public ?int $reschedulingId = null;

    public string $rescheduleAt = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Lead::class);

        if (! $this->user()->can('leads.view_all')) {
            $this->scope = 'mine';
        }
    }

    public function confirm(int $visitId): void
    {
        app(UpdateSiteVisit::class)->confirm($this->findVisit($visitId), $this->user());
        $this->toast('Visit confirmed.');
    }

    public function noShow(int $visitId): void
    {
        app(UpdateSiteVisit::class)->markNoShow($this->findVisit($visitId), $this->user());
        $this->toast('Marked as no-show. A call-back is set for 2 hours from now.', 'warning');
    }

    public function cancel(int $visitId): void
    {
        app(UpdateSiteVisit::class)->cancel($this->findVisit($visitId), null, $this->user());
        $this->toast('Visit cancelled.');
    }

    public function openReschedule(int $visitId): void
    {
        $visit = $this->findVisit($visitId);

        $this->reschedulingId = $visit->id;
        $this->rescheduleAt = $visit->scheduled_at->format('Y-m-d\TH:i');
        $this->resetValidation();
    }

    public function reschedule(): void
    {
        $visit = $this->findVisit((int) $this->reschedulingId);

        $this->validate(['rescheduleAt' => ['required', 'date', 'after:now']], attributes: ['rescheduleAt' => 'new time']);

        app(UpdateSiteVisit::class)->reschedule($visit, Carbon::parse($this->rescheduleAt), $this->user());

        $this->reset('reschedulingId', 'rescheduleAt');
        $this->toast('Visit moved.');
    }

    public function openComplete(int $visitId): void
    {
        $visit = $this->findVisit($visitId);

        $this->completingId = $visit->id;
        $this->notes = '';
        $this->nextStep = 'Share quotation';
        $this->measurements = [['room' => '', 'length_ft' => '', 'width_ft' => '']];
        $this->resetValidation();
    }

    public function addRow(): void
    {
        $this->measurements[] = ['room' => '', 'length_ft' => '', 'width_ft' => ''];
    }

    public function removeRow(int $index): void
    {
        unset($this->measurements[$index]);
        $this->measurements = array_values($this->measurements);
    }

    public function complete(): void
    {
        $visit = $this->findVisit((int) $this->completingId);

        $this->validate([
            'notes' => ['required', 'string', 'max:5000'],
            'nextStep' => ['nullable', 'string', 'max:255'],
            'measurements' => ['array', 'max:60'],
            'measurements.*.room' => ['nullable', 'string', 'max:60'],
            'measurements.*.length_ft' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'measurements.*.width_ft' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ], attributes: ['notes' => 'visit notes', 'measurements.*.length_ft' => 'length', 'measurements.*.width_ft' => 'width']);

        app(UpdateSiteVisit::class)->complete($visit, $this->notes, $this->measurements, $this->nextStep !== '' ? $this->nextStep : null, $this->user());

        $this->reset('completingId', 'notes', 'nextStep', 'measurements');
        $this->toast('Visit completed. A "prepare quotation" task is due in 48 hours.');
    }

    public function closeModals(): void
    {
        $this->reset('completingId', 'reschedulingId');
    }

    public function shiftCalendar(int $steps): void
    {
        $anchor = $this->anchor();
        $this->date = ($this->range === 'month' ? $anchor->addMonthsNoOverflow($steps) : $anchor->addWeeks($steps))->format('Y-m-d');
    }

    public function showWeekOf(string $date): void
    {
        $this->range = 'week';
        $this->date = $this->anchor($date)->format('Y-m-d');
    }

    public function today(): void
    {
        $this->date = '';
    }

    private function anchor(?string $date = null): CarbonImmutable
    {
        $date ??= $this->date;

        try {
            return $date !== '' ? CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay() : CarbonImmutable::today();
        } catch (\Throwable) {
            return CarbonImmutable::today();
        }
    }

    /**
     * @return array{title: string, days: list<CarbonImmutable>, visits: Collection<array-key, \Illuminate\Database\Eloquent\Collection<int, SiteVisit>>, anchor: CarbonImmutable}
     */
    private function calendar(): array
    {
        $anchor = $this->anchor();
        [$start, $end] = $this->range === 'month'
            ? [$anchor->startOfMonth()->startOfWeek(CarbonInterface::MONDAY), $anchor->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY)]
            : [$anchor->startOfWeek(CarbonInterface::MONDAY), $anchor->endOfWeek(CarbonInterface::SUNDAY)];

        $days = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $days[] = $day;
        }

        $visits = $this->visibleVisits()
            ->with(['contact:id,name', 'assignee:id,name', 'lead:id,ref'])
            ->whereBetween('scheduled_at', [$start, $end])
            ->where('status', '!=', VisitStatus::Cancelled->value)
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy(fn (SiteVisit $visit): string => $visit->scheduled_at->format('Y-m-d'))
            ->toBase();

        $title = $this->range === 'month'
            ? $anchor->format('F Y')
            : $start->format($start->month === $end->month ? 'j' : 'j M').' – '.$end->format('j M Y');

        return ['title' => $title, 'days' => $days, 'visits' => $visits, 'anchor' => $anchor];
    }

    public function render(): View
    {
        if (in_array($this->range, ['week', 'month'], true)) {
            return view('livewire.admin.crm.visits-index', [
                'calendar' => $this->calendar(),
                'groups' => collect(),
                'canSeeTeam' => $this->user()->can('leads.view_all'),
                'completing' => null,
                'measuredArea' => 0,
                'todayCount' => $this->visibleVisits()->whereIn('status', [VisitStatus::Scheduled->value, VisitStatus::Confirmed->value, VisitStatus::Rescheduled->value])->whereDate('scheduled_at', today())->count(),
            ]);
        }

        $open = [VisitStatus::Scheduled->value, VisitStatus::Confirmed->value, VisitStatus::Rescheduled->value];

        $query = $this->visibleVisits()->with(['lead:id,ref,contact_id,owner_id,area,city', 'contact:id,name,phone,whatsapp', 'assignee:id,name']);

        $visits = $this->range === 'past'
            ? $query->whereNotIn('status', $open)->where('scheduled_at', '>=', now()->subDays(60))->latest('scheduled_at')->limit(100)->get()
            : $query->whereIn('status', $open)->orderBy('scheduled_at')->limit(200)->get();

        $groups = $visits->groupBy(function (SiteVisit $visit): string {
            if ($this->range !== 'past' && $visit->scheduled_at->lt(now()->startOfDay())) {
                return 'Needs an update';
            }

            return match (true) {
                $visit->scheduled_at->isToday() => 'Today',
                $visit->scheduled_at->isTomorrow() => 'Tomorrow',
                default => $visit->scheduled_at->format('l j F'),
            };
        });

        $completing = $this->completingId ? SiteVisit::query()->with('contact:id,name')->find($this->completingId) : null;
        $area = collect($this->measurements)->sum(fn ($row) => (float) ($row['length_ft'] ?: 0) * (float) ($row['width_ft'] ?: 0));

        return view('livewire.admin.crm.visits-index', [
            'calendar' => null,
            'groups' => $groups,
            'canSeeTeam' => $this->user()->can('leads.view_all'),
            'completing' => $completing,
            'measuredArea' => $area,
            'todayCount' => $this->visibleVisits()->whereIn('status', $open)->whereDate('scheduled_at', today())->count(),
        ]);
    }

    /**
     * @return Builder<SiteVisit>
     */
    private function visibleVisits(): Builder
    {
        $user = $this->user();

        return SiteVisit::query()->when(
            $this->scope !== 'team' || ! $user->can('leads.view_all'),
            fn (Builder $q) => $q->where(fn (Builder $mine) => $mine->where('assigned_to', $user->id)
                ->orWhereHas('lead', fn (Builder $lead) => $lead->where('owner_id', $user->id))),
        );
    }

    private function findVisit(int $visitId): SiteVisit
    {
        $user = $this->user();
        $visit = SiteVisit::query()->with('lead')->findOrFail($visitId);

        abort_unless(
            $user->can('leads.view_all') || $visit->assigned_to === $user->id || $visit->lead->owner_id === $user->id,
            403,
        );

        abort_unless($user->can('leads.update'), 403);

        return $visit;
    }
}
