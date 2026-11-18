<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Crm\Models\PipelineStage;
use Illuminate\Database\Seeder;

/**
 * New → Contacted → Site Visit → Quotation → Negotiation → On Hold → Won / Lost (CRM-004).
 * Keys map 1:1 from v2.1 stages (visit → site_visit, quote → quotation, hold → on_hold).
 */
class PipelineStageSeeder extends Seeder
{
    public function run(): void
    {
        $stages = [
            ['key' => 'new', 'name' => 'New', 'type' => 'open', 'probability' => 10, 'color' => 'gray'],
            ['key' => 'contacted', 'name' => 'Contacted', 'type' => 'open', 'probability' => 20, 'color' => 'info'],
            ['key' => 'site_visit', 'name' => 'Site Visit', 'type' => 'open', 'probability' => 40, 'color' => 'info'],
            ['key' => 'quotation', 'name' => 'Quotation', 'type' => 'open', 'probability' => 60, 'color' => 'info'],
            ['key' => 'negotiation', 'name' => 'Negotiation', 'type' => 'open', 'probability' => 75, 'color' => 'warning'],
            ['key' => 'on_hold', 'name' => 'On Hold', 'type' => 'open', 'probability' => 15, 'color' => 'warning'],
            ['key' => 'won', 'name' => 'Won', 'type' => 'won', 'probability' => 100, 'color' => 'success'],
            ['key' => 'lost', 'name' => 'Lost', 'type' => 'lost', 'probability' => 0, 'color' => 'error'],
        ];

        foreach ($stages as $position => $stage) {
            // firstOrCreate: re-running (woodex:install, the web installer) never undoes the owner's edits.
            PipelineStage::query()->firstOrCreate(['key' => $stage['key']], $stage + ['position' => $position, 'is_active' => true]);
        }
    }
}
