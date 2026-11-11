<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Concerns\InteractsWithAdmin;
use App\Livewire\Concerns\ManagesNotificationInbox;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Header bell (GLB-008): unread count, latest notifications, mark read and deep links.
 * Polls every 30 s while the tab is visible (real-time broadcasting replaces this in P4).
 */
class NotificationBell extends Component
{
    use InteractsWithAdmin, ManagesNotificationInbox;

    public string $filter = 'all';

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.admin.notification-bell', [
            'notifications' => $this->notificationQuery()->latest()->limit(12)->get(),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }
}
