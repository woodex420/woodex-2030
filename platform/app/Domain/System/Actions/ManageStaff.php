<?php

declare(strict_types=1);

namespace App\Domain\System\Actions;

use App\Domain\System\Models\TelegramLink;
use App\Domain\System\Notifications\StaffInvitation;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Team management (SYS-002): invite by email (the person sets their own password), change role,
 * deactivate. There is always at least one active owner, and nobody can lock themselves out.
 */
final class ManageStaff
{
    public function invite(string $name, string $email, string $role, ?string $phone = null, ?string $title = null): User
    {
        $user = DB::transaction(function () use ($name, $email, $role, $phone, $title): User {
            $user = User::create([
                'name' => trim($name),
                'email' => Str::lower(trim($email)),
                'password' => Str::password(40),
                'phone' => Phone::normalize($phone),
                'title' => $title !== null && trim($title) !== '' ? trim($title) : null,
                'is_active' => true,
            ]);

            $user->syncRoles([$role]);

            return $user;
        });

        $this->sendInvitation($user);

        return $user;
    }

    public function sendInvitation(User $user): void
    {
        $token = Password::broker()->createToken($user);

        $user->notify(new StaffInvitation($token));
    }

    public function update(User $user, User $by, string $name, string $role, ?string $phone = null, ?string $title = null): User
    {
        $currentRole = $user->getRoleNames()->first();

        if ($currentRole !== $role) {
            $this->guardRoleChange($user, $by, $role);
        }

        DB::transaction(function () use ($user, $name, $role, $phone, $title, $currentRole): void {
            $user->forceFill([
                'name' => trim($name),
                'phone' => Phone::normalize($phone),
                'title' => $title !== null && trim($title) !== '' ? trim($title) : null,
            ])->save();

            if ($currentRole !== $role) {
                $user->syncRoles([$role]);
                if (! $user->canUseTelegram()) {
                    TelegramLink::query()->where('user_id', $user->id)->update([
                        'is_active' => false, 'link_code' => null, 'link_code_expires_at' => null,
                    ]);
                }
            }
        });

        return $user;
    }

    public function setActive(User $user, User $by, bool $active): User
    {
        if (! $active) {
            if ($user->is($by)) {
                throw ValidationException::withMessages(['user' => 'You cannot deactivate your own account.']);
            }

            if ($user->hasRole('owner') && ! $by->hasRole('owner')) {
                throw ValidationException::withMessages(['user' => 'Only an owner can deactivate another owner.']);
            }

            if ($user->hasRole('owner') && $this->activeOwnerCount() <= 1) {
                throw ValidationException::withMessages(['user' => 'There must always be at least one active owner.']);
            }
        }

        $user->forceFill(['is_active' => $active])->save();

        if (! $active) {
            // Sign them out everywhere straight away (database sessions) and cut off Telegram (TGM-007).
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->forceFill(['remember_token' => Str::random(60)])->save();
            TelegramLink::query()->where('user_id', $user->id)->update(['is_active' => false, 'link_code' => null, 'link_code_expires_at' => null]);
        }

        return $user;
    }

    private function guardRoleChange(User $user, User $by, string $role): void
    {
        if ($user->is($by)) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
        }

        if (($role === 'owner' || $user->hasRole('owner')) && ! $by->hasRole('owner')) {
            throw ValidationException::withMessages(['role' => 'Only an owner can give or remove the owner role.']);
        }

        if ($user->hasRole('owner') && $role !== 'owner' && $this->activeOwnerCount() <= 1) {
            throw ValidationException::withMessages(['role' => 'There must always be at least one active owner.']);
        }
    }

    private function activeOwnerCount(): int
    {
        return User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', 'owner'))->count();
    }
}
