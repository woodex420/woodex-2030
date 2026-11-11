<?php

declare(strict_types=1);

namespace App\Domain\System\Alerts;

use App\Domain\System\Models\AlertRule;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * The single door alerts go through (AUT-003, TGM-002). No rule for the event → the default
 * routing: exactly the recipients the caller computed, exactly the channels the notification
 * itself chooses — the matrix the OS shipped with, unchanged. One or more rules → each rule picks
 * its own recipients (roles and named people) and channels, and decides whether quiet hours are
 * respected; a critical rule reaches a muted chat immediately.
 */
final class AlertRouter
{
    /**
     * Route one alert. Returns how many people it was handed to.
     *
     * @param  iterable<User>  $defaultRecipients
     */
    public function send(string $event, iterable $defaultRecipients, Notification $notification): int
    {
        $defaults = collect($defaultRecipients)
            ->filter(fn (User $user): bool => $user->is_active)
            ->unique('id')
            ->values();

        $rules = AlertRule::query()->where('event', $event)->where('is_active', true)->orderBy('id')->get();

        if ($rules->isEmpty()) {
            NotificationFacade::send($defaults, $notification);

            return $defaults->count();
        }

        $sent = 0;

        foreach ($rules as $rule) {
            $users = $this->recipients($rule);

            if ($users->isNotEmpty()) {
                NotificationFacade::send($users, new RoutedAlert($notification, (array) $rule->channels, $rule->priority === 'critical' || ! (bool) $rule->respect_quiet_hours));
            }

            $sent += $users->count();
        }

        return $sent;
    }

    /**
     * A rule's people: everyone holding one of the rule's roles, plus anyone named by id — active
     * staff only. An empty recipient list means the rule silences the event for good.
     *
     * @return Collection<int, User>
     */
    private function recipients(AlertRule $rule): Collection
    {
        $roles = array_values((array) ($rule->recipients['roles'] ?? []));
        $ids = array_values(array_map('intval', (array) ($rule->recipients['users'] ?? [])));

        if ($roles === [] && $ids === []) {
            return collect();
        }

        return User::query()
            ->with('telegramLink')
            ->where('is_active', true)
            ->where(function ($query) use ($roles, $ids): void {
                if ($roles !== []) {
                    $query->whereHas('roles', fn ($rolesQuery) => $rolesQuery->whereIn('name', $roles));
                }

                if ($ids !== []) {
                    $query->orWhereIn('id', $ids);
                }
            })
            ->get();
    }
}
