<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\AssignmentRule;
use App\Domain\Crm\Models\Lead;
use App\Livewire\Admin\System\UsersIndex;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → Lead assignment (CRM-009): ordered routing rules by line, service, city/area and source.
 */
#[Layout('layouts::admin')]
#[Title('Lead assignment')]
class AssignmentRules extends Component
{
    use InteractsWithAdmin;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $rule = [];

    public function mount(): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);
    }

    public function openCreate(): void
    {
        $this->editingId = null;
        $this->rule = [
            'name' => '', 'is_active' => true, 'business_lines' => [], 'sources' => [], 'services' => '', 'cities' => '',
            'assign_to' => 'role', 'role' => 'sales', 'user_ids' => [], 'strategy' => 'round_robin',
            'max_open_leads' => '', 'working_hours_only' => false,
        ];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $rule = AssignmentRule::query()->findOrFail($id);
        $conditions = $rule->conditions ?? [];

        $this->editingId = $rule->id;
        $this->rule = [
            'name' => $rule->name,
            'is_active' => $rule->is_active,
            'business_lines' => $conditions['business_lines'] ?? [],
            'sources' => $conditions['sources'] ?? [],
            'services' => implode(', ', $conditions['services'] ?? []),
            'cities' => implode(', ', $conditions['cities'] ?? []),
            'assign_to' => ($rule->user_ids ?? []) !== [] ? 'people' : 'role',
            'role' => $rule->role ?? 'sales',
            'user_ids' => array_map('strval', $rule->user_ids ?? []),
            'strategy' => $rule->strategy,
            'max_open_leads' => (string) ($rule->max_open_leads ?? ''),
            'working_hours_only' => $rule->working_hours_only,
        ];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $this->validate([
            'rule.name' => ['required', 'string', 'max:120'],
            'rule.business_lines.*' => [Rule::enum(BusinessLine::class)],
            'rule.sources.*' => [Rule::enum(LeadSource::class)],
            'rule.services' => ['nullable', 'string', 'max:500'],
            'rule.cities' => ['nullable', 'string', 'max:500'],
            'rule.assign_to' => ['required', Rule::in(['role', 'people'])],
            'rule.role' => ['required_if:rule.assign_to,role', 'nullable', Rule::in(array_keys(UsersIndex::ROLES))],
            'rule.user_ids' => ['required_if:rule.assign_to,people', 'array'],
            'rule.user_ids.*' => [Rule::exists('users', 'id')->where('is_active', true)],
            'rule.strategy' => ['required', Rule::in(array_keys(AssignmentRule::STRATEGIES))],
            'rule.max_open_leads' => ['nullable', 'integer', 'between:1,1000'],
        ], attributes: ['rule.name' => 'name', 'rule.user_ids' => 'team members', 'rule.max_open_leads' => 'workload cap']);

        $list = fn (string $text): array => array_values(array_unique(array_filter(array_map('trim', explode(',', $text)))));
        $people = $this->rule['assign_to'] === 'people';

        $attributes = [
            'name' => trim((string) $this->rule['name']),
            'is_active' => (bool) $this->rule['is_active'],
            'conditions' => [
                'business_lines' => array_values((array) $this->rule['business_lines']),
                'sources' => array_values((array) $this->rule['sources']),
                'services' => array_map('mb_strtolower', $list((string) $this->rule['services'])),
                'cities' => $list((string) $this->rule['cities']),
            ],
            'role' => $people ? null : $this->rule['role'],
            'user_ids' => $people ? array_map('intval', (array) $this->rule['user_ids']) : null,
            'strategy' => $this->rule['strategy'],
            'max_open_leads' => $this->rule['max_open_leads'] !== '' ? (int) $this->rule['max_open_leads'] : null,
            'working_hours_only' => (bool) $this->rule['working_hours_only'],
        ];

        if ($this->editingId !== null) {
            AssignmentRule::query()->findOrFail($this->editingId)->update($attributes);
        } else {
            AssignmentRule::create($attributes + ['position' => (int) AssignmentRule::query()->max('position') + 1]);
        }

        $this->showForm = false;
        $this->toast('Assignment rule saved.');
    }

    public function toggle(int $id): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);
        $rule = AssignmentRule::query()->findOrFail($id);
        $rule->update(['is_active' => ! $rule->is_active]);
    }

    public function move(int $id, int $direction): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $rules = AssignmentRule::query()->orderBy('position')->orderBy('id')->get()->values();
        $index = $rules->search(fn (AssignmentRule $r): bool => $r->id === $id);
        $swap = $index === false ? null : $rules->get($index + ($direction < 0 ? -1 : 1));

        if ($index === false || $swap === null) {
            return;
        }

        $ordered = $rules->all();
        [$ordered[$index], $ordered[$index + ($direction < 0 ? -1 : 1)]] = [$swap, $rules[$index]];

        foreach (array_values($ordered) as $position => $rule) {
            $rule->update(['position' => $position + 1]);
        }
    }

    public function delete(int $id): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);
        AssignmentRule::query()->findOrFail($id)->delete();
        $this->toast('Rule deleted.');
    }

    public function render(): View
    {
        $rules = AssignmentRule::query()->with('lastAssigned:id,name')->orderBy('position')->orderBy('id')->get();
        $staff = User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $openLoads = Lead::query()->open()->whereNotNull('owner_id')->selectRaw('owner_id, count(*) as aggregate')->groupBy('owner_id')->pluck('aggregate', 'owner_id');

        return view('livewire.admin.crm.assignment-rules', [
            'rules' => $rules,
            'staff' => $staff,
            'staffNames' => $staff->pluck('name', 'id'),
            'loads' => $openLoads,
            'lines' => BusinessLine::options(),
            'sources' => LeadSource::options(),
            'roles' => UsersIndex::ROLES,
            'roleCounts' => collect(array_keys(UsersIndex::ROLES))->mapWithKeys(fn (string $role) => [
                $role => User::query()->where('is_active', true)->whereHas('roles', fn (Builder $q) => $q->where('name', $role))->count(),
            ]),
        ]);
    }
}
