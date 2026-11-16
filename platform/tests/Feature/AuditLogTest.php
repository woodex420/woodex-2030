<?php

use App\Domain\Crm\Models\Lead;
use App\Livewire\Admin\System\AuditLog;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| SYS-007: the audit-log viewer. Model changes, webhook deliveries and integration calls, filterable,
| behind the audit.view permission that already existed. Trust, then verify.
*/

it('lists model changes with who did them and what changed', function () {
    $owner = $this->userWithRole('owner', ['name' => 'Imtiaz Ahmad']);
    $sales = $this->userWithRole('sales', ['name' => 'Bilal Ahmed']);

    $lead = Lead::factory()->create(['owner_id' => $sales->id]);
    activity()
        ->causedBy($sales)
        ->performedOn($lead)
        ->withProperties(['attributes' => ['city' => 'Lahore']])
        ->log('updated the lead city');

    $component = Livewire::actingAs($owner)->test(AuditLog::class);

    $component->assertSee('updated the lead city')
        ->assertSee('Bilal Ahmed')
        ->assertSee('Lead #'.$lead->id);

    // The causer filter narrows to one person.
    $component->set('causerId', (string) $sales->id)
        ->assertSee('updated the lead city');

    $other = $this->userWithRole('sales_manager', ['name' => 'Ayesha Khan']);
    $component->set('causerId', (string) $other->id)
        ->assertDontSee('updated the lead city');
});

it('lists webhook deliveries and integration calls on their tabs', function () {
    $owner = $this->userWithRole('owner');

    DB::table('webhook_events')->insert([
        'provider' => 'telegram',
        'external_id' => '900001',
        'type' => 'message',
        'payload' => json_encode(['update_id' => 900001]),
        'signature_valid' => true,
        'status' => 'processed',
        'attempts' => 1,
        'processed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('integration_logs')->insert([
        'integration' => 'telegram',
        'direction' => 'out',
        'action' => 'send message',
        'status_code' => 200,
        'ok' => true,
        'duration_ms' => 210,
        'created_at' => now(),
    ]);

    Livewire::actingAs($owner)
        ->test(AuditLog::class)
        ->set('tab', 'webhooks')
        ->assertSee('Telegram')
        ->assertSee('900001')
        ->set('tab', 'integrations')
        ->assertSee('send message')
        ->assertSee('HTTP 200');
});

it('keeps the audit log to those who may audit', function () {
    $owner = $this->userWithRole('owner');
    $sales = $this->userWithRole('sales');

    $this->actingAs($sales)->get('/admin/system/audit')->assertForbidden();
    $this->actingAs($owner)->get('/admin/system/audit')->assertOk();
});
