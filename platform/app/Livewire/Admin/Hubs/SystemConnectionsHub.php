<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\System\DataImport;
use App\Livewire\Admin\System\Integrations;

/**
 * System → Connections (deep QA 21 Oct, slice B): the channels that talk to the outside world and
 * the one screen that brings the old books in. Integrations is the connector engine; Data Import
 * is the dry-run-first importer.
 */
final class SystemConnectionsHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Connections',
            'description' => 'Every outside service the studio connects to — GitHub, Google, WhatsApp, custom APIs, the MCP server — and the import that brings old records in.',
            'breadcrumbs' => ['System' => null, 'Connections' => null],
            'tabs' => [
                ['id' => 'integrations', 'label' => 'Integrations', 'component' => Integrations::class, 'permission' => 'integrations.manage'],
                ['id' => 'import', 'label' => 'Data import', 'component' => DataImport::class, 'permission' => 'system.import'],
            ],
        ];
    }
}
