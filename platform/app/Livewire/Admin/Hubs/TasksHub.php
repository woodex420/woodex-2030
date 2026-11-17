<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\Crm\TasksIndex;
use App\Livewire\Admin\Crm\VisitsIndex;

/**
 * P10.1: the Tasks & Visits workflow page — what the team must do and where it must be.
 */
final class TasksHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Tasks & Visits',
            'description' => 'The team\'s to-do list and the site-visit agenda, together.',
            'breadcrumbs' => ['CRM' => null, 'Tasks & Visits' => null],
            'tabs' => [
                ['id' => 'tasks', 'label' => 'Tasks', 'component' => TasksIndex::class, 'permission' => 'leads.view'],
                ['id' => 'visits', 'label' => 'Site visits', 'component' => VisitsIndex::class, 'permission' => 'leads.view'],
            ],
        ];
    }
}
