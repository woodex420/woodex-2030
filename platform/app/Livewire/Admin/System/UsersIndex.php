<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Domain\System\Actions\ManageStaff;
use App\Livewire\Admin\Crm\ContactsIndex;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * Users & roles (SYS-002): invite the team, set roles from the permission matrix, deactivate leavers.
 */
#[Layout('layouts::admin')]
#[Title('Users & roles')]
class UsersIndex extends Component
{
    use InteractsWithAdmin;

    /** Role => one-line description shown in the picker (PRD §7). */
    public const ROLES = [
        'owner' => 'Everything, including approvals, voiding invoices and settings',
        'admin' => 'Everything except approvals and voiding invoices',
        'sales_manager' => 'All leads, assignment, quotation approval, reports',
        'sales' => 'Own leads, quotations and follow-ups',
        'designer_pm' => 'Quotation drafting and projects',
        'accountant' => 'Invoices, payments, receivables and finance reports',
        'editor' => 'Website pages and social posts',
        'support' => 'Inbox replies and new leads',
    ];

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $title = '';

    public string $role = 'sales';

    public function mount(): void
    {
        abort_unless($this->user()->can('users.manage'), 403);
    }

    public function openInvite(): void
    {
        $this->reset('editingId', 'name', 'email', 'phone', 'title');
        $this->role = 'sales';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function openEdit(int $userId): void
    {
        $member = User::query()->findOrFail($userId);

        $this->editingId = $member->id;
        $this->name = $member->name;
        $this->email = $member->email;
        $this->phone = (string) $member->phone;
        $this->title = (string) $member->title;
        $this->role = (string) ($member->getRoleNames()->first() ?? 'sales');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless($this->user()->can('users.manage'), 403);

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($this->editingId)],
            'phone' => ['nullable', 'string', 'max:30', ContactsIndex::phoneRule()],
            'title' => ['nullable', 'string', 'max:80'],
            'role' => ['required', Rule::in(array_keys(self::ROLES))],
        ]);

        $staff = app(ManageStaff::class);

        try {
            if ($this->editingId !== null) {
                $member = User::query()->findOrFail($this->editingId);
                $staff->update($member, $this->user(), $this->name, $this->role, $this->phone, $this->title);
                $this->toast("{$member->name} updated.");
            } else {
                if ($this->role === 'owner' && ! $this->user()->hasRole('owner')) {
                    throw ValidationException::withMessages(['role' => 'Only an owner can invite another owner.']);
                }

                $member = $staff->invite($this->name, $this->email, $this->role, $this->phone, $this->title);
                $this->toast("Invitation sent to {$member->email}.");
            }
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }

        $this->showForm = false;
    }

    public function toggleActive(int $userId): void
    {
        abort_unless($this->user()->can('users.manage'), 403);

        $member = User::query()->findOrFail($userId);

        try {
            app(ManageStaff::class)->setActive($member, $this->user(), ! $member->is_active);
            $this->toast($member->is_active ? "{$member->name} can sign in again." : "{$member->name} has been deactivated and signed out.");
        } catch (ValidationException $exception) {
            $this->toastValidation($exception);
        }
    }

    public function resendInvite(int $userId): void
    {
        abort_unless($this->user()->can('users.manage'), 403);

        $member = User::query()->where('is_active', true)->findOrFail($userId);
        app(ManageStaff::class)->sendInvitation($member);

        $this->toast("A new set-password link was sent to {$member->email}.");
    }

    public function render(): View
    {
        return view('livewire.admin.system.users-index', [
            'members' => User::query()->with(['roles', 'telegramLink'])->orderByDesc('is_active')->orderBy('name')->get(),
            'roles' => collect(self::ROLES)->only(Role::query()->pluck('name')->all())->all() ?: self::ROLES,
            'roleLabels' => collect(self::ROLES)->keys()->mapWithKeys(fn (string $r) => [$r => Str::headline($r)])->all(),
        ]);
    }
}
