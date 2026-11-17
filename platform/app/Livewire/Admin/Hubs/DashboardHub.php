<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\MyDay;
use App\Livewire\Admin\OperationsControl;
use App\Livewire\Admin\System\Health;

/**
 * P10.1: the Overview workflow page — what is happening and what needs doing now.
 */
final class DashboardHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Dashboard',
            'description' => 'Your operating control centre — performance, personal work, cross-module queues and system health.',
            'breadcrumbs' => ['Overview' => null, 'Dashboard' => null],
            'tabs' => [
                ['id' => 'overview', 'label' => 'Overview', 'component' => Dashboard::class, 'permission' => 'dashboard.view'],
                ['id' => 'my-day', 'label' => 'My Day', 'component' => MyDay::class, 'permission' => 'leads.view'],
                ['id' => 'operations', 'label' => 'Operations', 'component' => OperationsControl::class, 'permission' => 'dashboard.view'],
                ['id' => 'system', 'label' => 'System', 'component' => Health::class, 'permission' => 'system.health'],
            ],
        ];
    }
}
