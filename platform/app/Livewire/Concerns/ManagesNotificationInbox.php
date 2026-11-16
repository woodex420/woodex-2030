<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

/** Shared own-user notification actions. Archive is metadata, never deletion or a schema change. */
trait ManagesNotificationInbox
{
    public string $search = '';

    public function open(string $id): void
    {
        $notification = $this->ownNotification($id);
        $notification->markAsRead();
        $url = $notification->data['url'] ?? null;
        if (! is_string($url) || preg_match('/[\x00-\x20\\\\]/', rawurldecode($url))) {
            return;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || str_starts_with($url, '//')) {
            return;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $origin = parse_url(url('/'));
            if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || ($parts['scheme'] ?? '') !== ($origin['scheme'] ?? '')
                || ($parts['host'] ?? '') !== ($origin['host'] ?? '')
                || ($parts['port'] ?? null) !== ($origin['port'] ?? null)) {
                return;
            }
        } elseif (! str_starts_with($url, '/')) {
            return;
        }
        $path = $parts['path'] ?? '/';
        if (str_starts_with(rawurldecode($path), '//')) {
            return;
        }
        $this->redirect($path.(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : ''), navigate: true);
    }

    public function archive(string $id): void
    {
        $notification = $this->ownNotification($id);
        $notification->update(['data' => array_merge($notification->data, ['woodex_archived_at' => now()->toIso8601String()]), 'read_at' => $notification->read_at ?? now()]);
    }

    public function restore(string $id): void
    {
        $notification = $this->ownNotification($id);
        $data = $notification->data;
        unset($data['woodex_archived_at']);
        $notification->update(['data' => $data]);
    }

    public function markAllRead(): void
    {
        $this->user()->unreadNotifications()->update(['read_at' => now()]);
    }

    private function ownNotification(string $id): DatabaseNotification
    {
        $notification = $this->user()->notifications()->find($id);
        abort_unless($notification instanceof DatabaseNotification, 403);

        return $notification;
    }

    /** @return Builder<DatabaseNotification> */
    protected function notificationQuery(): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $this->user()->getMorphClass())
            ->where('notifiable_id', $this->user()->getKey())
            ->when($this->filter === 'archived', fn (Builder $q) => $q->whereNotNull('data->woodex_archived_at'), fn (Builder $q) => $q->whereNull('data->woodex_archived_at'))
            ->when($this->filter === 'unread', fn (Builder $q) => $q->whereNull('read_at'))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('data->title', 'like', '%'.trim($this->search).'%')
                ->orWhere('data->body', 'like', '%'.trim($this->search).'%')));
    }
}
