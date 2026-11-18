<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Livewire\Concerns\InteractsWithAdmin;
use App\Support\AdminNavigation;
use App\Support\WorkspacePreferences as Preferences;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Workspace preferences (master list T-056): how the workspace looks and behaves for the person signed in, and,
 * on a first sign-in, the welcome that leads a new member through their first steps.
 *
 * Every person sets their own. Nothing here changes what anybody may see or do.
 */
#[Layout('layouts::admin')]
#[Title('Workspace preferences')]
class WorkspacePreferences extends Component
{
    use InteractsWithAdmin;

    public string $appearance = 'system';

    public bool $shortcuts = true;

    public string $start = '';

    /** The welcome of a first sign-in is on the page. */
    #[Url(except: false)]
    public bool $welcome = false;

    public function mount(): void
    {
        $user = $this->user();
        $this->appearance = Preferences::appearance($user);
        $this->shortcuts = Preferences::shortcuts($user);
        $chosen = $user->preference('start');
        $this->start = is_string($chosen) && isset(Preferences::startScreens($user)[$chosen]) ? $chosen : '';

        // Sign-in led here once. The mark is taken away now: the next sign-in opens the chosen screen.
        if (session()->pull(Preferences::FIRST_SIGN_IN) === true) {
            $this->welcome = true;
        }
    }

    public function save(): void
    {
        try {
            Preferences::save($this->user(), ['appearance' => $this->appearance, 'shortcuts' => $this->shortcuts, 'start' => $this->start]);
        } catch (ValidationException $exception) {
            $this->toastValidation($exception);

            throw $exception;
        }

        // This browser follows at once: the page takes the appearance and the shortcuts without a reload.
        $this->dispatch('wx-preferences-saved', appearance: $this->appearance, shortcuts: $this->shortcuts);
        $this->toast('Your preferences are saved.');
    }

    public function render(): View
    {
        $user = $this->user();
        $screens = Preferences::startScreens($user);

        return view('livewire.admin.system.workspace-preferences', [
            'appearances' => Preferences::APPEARANCES,
            'screens' => $screens,
            'opens' => $screens[$this->start] ?? ['label' => 'Workspace · Dashboard', 'route' => 'admin.dashboard'],
            'greeting' => 'Welcome, '.$user->name,
            'signedIn' => 'You are signed in'.(($role = (string) $user->getRoleNames()->first()) !== '' ? ' as '.str_replace('_', ' ', $role) : '').'. Three things before you start.',
            'twoFactorOn' => $user->hasTwoFactorEnabled(),
            'twoFactorRequired' => $user->requiresTwoFactorAuthentication(),
            'menu' => AdminNavigation::for($user),
        ]);
    }
}
