<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Shared helpers for admin Livewire screens.
 */
trait InteractsWithAdmin
{
    protected function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', message: $message, type: $type);
    }

    protected function toastValidation(ValidationException $exception): void
    {
        $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
    }

    /**
     * Active staff who can own leads, tasks and visits.
     *
     * @return Collection<int, User>
     */
    protected function staff(): Collection
    {
        return User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }
}
