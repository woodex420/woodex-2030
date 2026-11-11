<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\Crm\LeadsIndex;
use App\Livewire\Admin\Crm\LeadTrackingBoard;
use App\Livewire\Admin\Crm\PipelineBoard;
use App\Livewire\Admin\Crm\WebChatLeads;

/**
 * P10.1: the Leads workflow page — list, board and the new tracking view on one page.
 */
final class LeadsHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Leads',
            'description' => 'Every enquiry: list, pipeline, follow-up tracking and website chat capture.',
            'breadcrumbs' => ['CRM' => null, 'Leads' => null],
            'tabs' => [
                ['id' => 'list', 'label' => 'List', 'component' => LeadsIndex::class, 'permission' => 'leads.view'],
                ['id' => 'pipeline', 'label' => 'Pipeline', 'component' => PipelineBoard::class, 'permission' => 'leads.view'],
                ['id' => 'web_chat', 'label' => 'Web Chat', 'component' => WebChatLeads::class, 'permission' => 'leads.view'],
                ['id' => 'tracking', 'label' => 'Tracking', 'component' => LeadTrackingBoard::class, 'permission' => 'leads.view'],
            ],
        ];
    }

    protected function tabVisible(array $tab): bool
    {
        return parent::tabVisible($tab) && ($tab['id'] !== 'web_chat' || auth()->user()?->can('inbox.view'));
    }
}
