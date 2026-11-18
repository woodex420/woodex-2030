<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Domain\System\Actions\ManageStaff;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Team onboarding (master list T-056): how far each member is on the way into the workspace.
 *
 * A member is invited on Access & team and gets a link by email to set a password. Their first sign-in opens the
 * welcome of Workspace preferences. A role that must use two-factor sign-in reaches no other screen until it is
 * on (App\Http\Middleware\EnsureTwoFactorIsEnabled). This screen shows, for every active member, which of these
 * steps are done, and sends the link again to somebody who has not come yet.
 *
 * It reads what the other screens write and keeps no record of its own.
 */
#[Layout('layouts::admin')]
#[Title('Team onboarding')]
class TeamOnboarding extends Component
{
    use InteractsWithAdmin;

    public function sendAgain(int $userId): void
    {
        abort_unless($this->user()->can('users.manage'), 403);

        $member = User::query()->where('is_active', true)->whereNull('last_login_at')->find($userId);
        if ($member === null) {
            // Signed in meanwhile, deactivated or removed: the list on the page is older than the record.
            $this->toast('That member has signed in meanwhile, or is no longer active. Nothing was sent.', 'warning');

            return;
        }

        app(ManageStaff::class)->sendInvitation($member);
        $this->toast("A new link to set a password was sent to {$member->email}.");
    }

    public function render(): View
    {
        $minutes = (int) config('auth.passwords.'.config('fortify.passwords', 'users').'.expire', 60);
        $links = DB::table((string) config('auth.passwords.'.config('fortify.passwords', 'users').'.table', 'password_reset_tokens'))
            ->pluck('created_at', 'email');

        $members = User::query()->where('is_active', true)->with('roles')->orderBy('name')->get()
            ->map(function (User $member) use ($links, $minutes): array {
                $sent = $links->get($member->email);
                $sent = $sent !== null ? Carbon::parse($sent) : null;
                $signedIn = $member->last_login_at !== null;
                $twoFactor = $member->hasTwoFactorEnabled() ? 'on' : ($member->requiresTwoFactorAuthentication() ? 'missing' : 'optional');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => str_replace('_', ' ', (string) $member->getRoleNames()->first()),
                    'invited' => $member->created_at,
                    // A link stands only for somebody who has not signed in: a later "forgot my password" is not onboarding.
                    'link' => $signedIn ? 'used' : ($sent === null ? 'none' : ($sent->copy()->addMinutes($minutes)->isPast() ? 'expired' : 'waiting')),
                    'link_sent' => $signedIn ? null : $sent,
                    'signed_in' => $member->last_login_at,
                    'two_factor' => $twoFactor,
                    'through' => $signedIn && $twoFactor !== 'missing',
                ];
            })
            // Who still needs something comes first.
            ->sortBy(fn (array $member): string => ($member['through'] ? '1' : '0').mb_strtolower($member['name']))
            ->values();

        return view('livewire.admin.system.team-onboarding', [
            'members' => $members,
            'counts' => [
                'members' => $members->count(),
                'through' => $members->where('through', true)->count(),
                'not_signed_in' => $members->whereNull('signed_in')->count(),
                'two_factor_missing' => $members->where('two_factor', 'missing')->count(),
            ],
            'linkMinutes' => $minutes,
            'twoFactorEnforced' => (bool) config('woodex.security.enforce_2fa'),
            'twoFactorRoles' => array_map(static fn (string $role): string => str_replace('_', ' ', $role), (array) config('woodex.security.require_2fa_roles', [])),
        ]);
    }
}
