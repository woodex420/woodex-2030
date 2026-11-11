<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Concerns\InteractsWithAdmin;
use App\Livewire\Concerns\ManagesNotificationInbox;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Notification centre (GLB-008): everything the bell has shown, with unread filter.
 */
#[Layout('layouts::admin')]
#[Title('Notifications')]
class NotificationsPage extends Component
{
    use InteractsWithAdmin, ManagesNotificationInbox, WithPagination;

    #[Url(except: 'all')]
    public string $filter = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = $this->notificationQuery();

        return view('livewire.admin.notifications-page', [
            'notifications' => $query->latest()->paginate(25),
            'unread' => $this->user()->unreadNotifications()->count(),
        ]);
    }
}
