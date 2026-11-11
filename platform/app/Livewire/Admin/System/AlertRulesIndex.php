<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Domain\System\Alerts\AlertCatalogue;
use App\Domain\System\Models\AlertRule;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * The alert-rules builder (AUT-003, TGM-002, P5 step S7): event → recipients → channels → quiet
 * hours → priority, editable here. The catalogue lists every event the OS raises today with the
 * routing it ships with; a rule overrides one event's routing, and deleting the rule returns the
 * event to that default. Nothing is pre-seeded — out of the box, every alert behaves exactly as it
 * did before this screen existed.
 */
#[Layout('layouts::admin')]
#[Title('Alert rules')]
class AlertRulesIndex extends Component
{
    use InteractsWithAdmin;

    /** The add/edit form. */
    public ?int $ruleId = null;

    public string $event = '';

    public string $name = '';

    /** @var list<string> */
    public array $roles = [];

    /** @var list<int> */
    public array $users = [];

    /** @var list<string> */
    public array $channels = ['database', 'telegram'];

    public bool $respectQuietHours = true;

    public string $priority = 'normal';

    public bool $showRuleForm = false;

    public function mount(): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);
    }

    public function addRule(): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $this->reset('ruleId', 'name', 'roles', 'users');
        $this->event = AlertCatalogue::keys()[0];
        $this->channels = ['database', 'telegram'];
        $this->respectQuietHours = true;
        $this->priority = 'normal';
        $this->showRuleForm = true;
    }

    public function editRule(int $id): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $rule = AlertRule::query()->findOrFail($id);

        $this->ruleId = $rule->id;
        $this->event = $rule->event;
        $this->name = $rule->name;
        $this->roles = (array) ($rule->recipients['roles'] ?? []);
        $this->users = array_map('intval', (array) ($rule->recipients['users'] ?? []));
        $this->channels = (array) $rule->channels;
        $this->respectQuietHours = (bool) $rule->respect_quiet_hours;
        $this->priority = (string) $rule->priority;

        $this->showRuleForm = true;
    }

    public function save(): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $this->validate([
            'event' => ['required', 'string', 'in:'.implode(',', AlertCatalogue::keys())],
            'name' => ['required', 'string', 'max:120'],
            'roles' => ['array'],
            'users' => ['array'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['in:database,telegram,mail'],
            'priority' => ['in:normal,critical'],
        ], [], [
            'name' => 'rule name',
            'channels' => 'channels',
        ]);

        if ($this->roles === [] && $this->users === []) {
            $this->addError('roles', 'Pick at least one role or one person — an alert nobody receives is a typo, not a choice.');

            return;
        }

        AlertRule::query()->updateOrCreate(
            ['id' => $this->ruleId ?? 0],
            [
                'event' => $this->event,
                'name' => trim($this->name),
                'conditions' => null,
                'recipients' => ['roles' => array_values($this->roles), 'users' => array_values($this->users)],
                'channels' => array_values($this->channels),
                'respect_quiet_hours' => $this->respectQuietHours,
                'priority' => $this->priority,
                'is_active' => true,
            ],
        );

        $this->showRuleForm = false;
        $this->toast($this->ruleId !== null ? 'Rule updated.' : "Rule added — it takes over this event's routing now.");
    }

    public function toggleRule(int $id): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $rule = AlertRule::query()->findOrFail($id);
        $rule->is_active = ! $rule->is_active;
        $rule->save();

        $this->toast($rule->is_active ? 'Rule back on.' : 'Rule paused — the default routing applies again.');
    }

    public function removeRule(int $id): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        AlertRule::query()->findOrFail($id)->delete();
        $this->toast('Rule removed — the default routing applies again.');
    }

    public function render(): View
    {
        return view('livewire.admin.system.alert-rules', [
            'catalogue' => AlertCatalogue::events(),
            'rules' => AlertRule::query()->orderBy('event')->orderBy('id')->get(),
            'allRoles' => Role::query()->orderBy('name')->pluck('name'),
            'staff' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
