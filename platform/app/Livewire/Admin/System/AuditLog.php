<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Domain\System\Models\IntegrationLog;
use App\Domain\System\Models\WebhookEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit log (SYS-007): who changed what, when — model changes, webhook deliveries and integration
 * calls, filterable. The "trust, then verify" screen: the same timeline the owner reads in Telegram,
 * in full detail, for the day something needs checking.
 */
#[Layout('layouts::admin')]
#[Title('Audit log')]
class AuditLog extends Component
{
    use WithPagination;

    public string $tab = 'changes';

    public string $causerId = '';

    public string $subjectType = '';

    public string $search = '';

    /** @return list<array{value: string, label: string}> */
    public function tabs(): array
    {
        return [
            ['value' => 'changes', 'label' => 'Model changes'],
            ['value' => 'webhooks', 'label' => 'Webhooks'],
            ['value' => 'integrations', 'label' => 'Integrations'],
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public function causers(): array
    {
        $rows = DB::table('activity_log')
            ->whereNotNull('causer_id')
            ->distinct()
            ->join('users', 'activity_log.causer_id', '=', 'users.id')
            ->select('users.id', 'users.name')
            ->orderBy('users.name')
            ->get();

        return $rows->map(fn ($row): array => ['value' => (string) $row->id, 'label' => $row->name])->all();
    }

    /** @return list<array{value: string, label: string}> */
    public function subjectTypes(): array
    {
        $rows = DB::table('activity_log')
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type');

        return $rows
            ->map(fn (string $type): array => ['value' => $type, 'label' => class_basename($type)])
            ->all();
    }

    /**
     * @return LengthAwarePaginator<int, Activity>
     */
    #[Computed]
    public function changes(): LengthAwarePaginator
    {
        return Activity::query()
            ->when($this->causerId !== '', fn ($query) => $query->where('causer_id', (int) $this->causerId))
            ->when($this->subjectType !== '', fn ($query) => $query->where('subject_type', $this->subjectType))
            ->when($this->search !== '', function ($query): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
                $query->where('description', 'like', $term);
            })
            ->latest('id')
            ->with('causer')
            ->paginate(30);
    }

    /**
     * @return LengthAwarePaginator<int, WebhookEvent>
     */
    #[Computed]
    public function webhooks(): LengthAwarePaginator
    {
        return WebhookEvent::query()
            ->when($this->search !== '', function ($query): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
                $query->where('external_id', 'like', $term);
            })
            ->latest('id')
            ->paginate(30);
    }

    /**
     * @return LengthAwarePaginator<int, IntegrationLog>
     */
    #[Computed]
    public function integrations(): LengthAwarePaginator
    {
        return IntegrationLog::query()
            ->when($this->search !== '', function ($query): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
                $query->where(fn ($q) => $q->where('action', 'like', $term)->orWhere('error', 'like', $term));
            })
            ->latest('id')
            ->paginate(30);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCauserId(): void
    {
        $this->resetPage();
    }

    public function updatedSubjectType(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.system.audit-log');
    }
}
