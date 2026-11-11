<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\System\AlertRulesIndex;
use App\Livewire\Admin\System\AuditLog;
use App\Livewire\Admin\System\ChangeRequests;
use App\Livewire\Admin\System\Health;
use App\Livewire\Admin\System\RecycleBin;

/**
 * System → Platform (deep QA 21 Oct, slice B): is the machine healthy, what did it do, what is
 * waiting for a decision, what was deleted, and which alerts are armed. The five questions asked
 * when something feels wrong — one page, its buttons inside.
 */
final class SystemPlatformHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Platform',
            'description' => 'Health, the audit trail, change requests, alert rules and the recycle bin — the state of the system itself.',
            'breadcrumbs' => ['System' => null, 'Platform' => null],
            'tabs' => [
                ['id' => 'health', 'label' => 'Health', 'component' => Health::class, 'permission' => 'system.health'],
                ['id' => 'audit', 'label' => 'Audit log', 'component' => AuditLog::class, 'permission' => 'audit.view'],
                ['id' => 'changes', 'label' => 'Change requests', 'component' => ChangeRequests::class, 'permission' => 'system.change_requests'],
                ['id' => 'alerts', 'label' => 'Alert rules', 'component' => AlertRulesIndex::class, 'permission' => 'settings.manage'],
                ['id' => 'recycle', 'label' => 'Recycle bin', 'component' => RecycleBin::class, 'permission' => 'leads.delete'],
            ],
        ];
    }
}
