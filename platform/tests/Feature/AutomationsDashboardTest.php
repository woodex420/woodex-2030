<?php

use App\Domain\System\Models\Automation;
use App\Domain\System\Models\ScheduledTaskRun;
use App\Livewire\Admin\System\AutomationsIndex;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
| P8.5 part 1: the automation control dashboard. The catalog is code, a missing row means
| enabled (fail-safe), a toggle writes the row with attribution and lands within a minute via
| the cache, and the schedule entries actually consult the switch.
*/

it('treats every automation as enabled until somebody pauses it', function () {
    expect(Automation::query()->count())->toBe(0)
        ->and(Automation::enabled('woodex:sla-check'))->toBeTrue()
        ->and(Automation::enabled('woodex:quote-follow-ups'))->toBeTrue();
});

it('pauses and resumes an automation from the dashboard with attribution', function () {
    $owner = $this->userWithRole('owner');

    Livewire::actingAs($owner)
        ->test(AutomationsIndex::class)
        ->assertOk()
        ->assertSee('Quotation follow-up chaser')
        ->assertSee('Platform jobs')
        ->call('toggle', 'woodex:quote-follow-ups')
        ->assertHasNoErrors();

    $row = Automation::query()->sole();

    expect($row->key)->toBe('woodex:quote-follow-ups')
        ->and($row->is_enabled)->toBeFalse()
        ->and($row->toggled_by)->toBe($owner->id)
        ->and(Automation::enabled('woodex:quote-follow-ups'))->toBeFalse();

    $this->assertDatabaseHas('activity_log', [
        'causer_id' => $owner->id,
        'description' => 'Paused automation Quotation follow-up chaser.',
    ]);

    // Resuming flips it back without growing extra rows.
    Livewire::actingAs($owner)
        ->test(AutomationsIndex::class)
        ->call('toggle', 'woodex:quote-follow-ups');

    expect(Automation::query()->count())->toBe(1)
        ->and(Automation::query()->sole()->is_enabled)->toBeTrue()
        ->and(Automation::enabled('woodex:quote-follow-ups'))->toBeTrue();
});

it('keeps the control behind the automation permission', function () {
    $this->get('/admin/automation')->assertRedirect('/admin/login');

    $this->actingAs($this->userWithRole('support'))->get('/admin/automation')->assertForbidden();

    $sales = $this->userWithRole('sales'); // no automation.manage
    $this->actingAs($sales)->get('/admin/automation')->assertForbidden();
});

it('does not offer the platform jobs for toggling', function () {
    $owner = $this->userWithRole('owner');

    Livewire::actingAs($owner)
        ->test(AutomationsIndex::class)
        ->call('toggle', 'woodex:heartbeat')
        ->assertStatus(403);

    Livewire::actingAs($owner)
        ->test(AutomationsIndex::class)
        ->call('toggle', 'not-a-real-automation')
        ->assertStatus(403);

    expect(Automation::query()->count())->toBe(0);
});

it('shows the scheduler\u{2019}s own run history per automation', function () {
    ScheduledTaskRun::query()->create([
        'task' => 'woodex:sla-check',
        'ran_at' => now()->subMinutes(2),
        'runtime_ms' => 340,
    ]);

    Livewire::actingAs($this->userWithRole('owner'))
        ->test(AutomationsIndex::class)
        ->assertOk()
        ->assertSee('First-response SLA')
        ->assertSee('2 minutes ago');
});

it('actually skips a paused automation in the schedule', function () {
    Automation::toggle('woodex:sla-check', User::query()->first());

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'woodex:sla-check'));

    expect($events)->toHaveCount(1);

    $event = $events->first();
    expect($event->filtersPass(app()))->toBeFalse()
        ->and(Automation::enabled('woodex:sla-check'))->toBeFalse();

    Automation::toggle('woodex:sla-check', User::query()->first());

    // The switch is cached for a minute — the toggle itself clears its own key.
    expect(Automation::enabled('woodex:sla-check'))->toBeTrue()
        ->and($event->filtersPass(app()))->toBeTrue();
});

it('keeps the catalog in step with the gated schedule entries', function () {
    $schedule = file_get_contents(base_path('routes/console.php'));

    foreach (Automation::catalog() as $entry) {
        if (! ($entry['toggleable'] && $entry['scheduled'])) {
            continue; // Event-driven automations are gated where they fire, not in the schedule.
        }

        expect($schedule)->toContain("Automation::enabled('{$entry['key']}')")
            ->and($entry['key'])->toStartWith('woodex:');
    }
});
