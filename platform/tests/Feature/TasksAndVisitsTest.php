<?php

use App\Domain\Crm\Actions\ScheduleSiteVisit;
use App\Domain\Crm\Enums\TaskStatus;
use App\Domain\Crm\Enums\VisitStatus;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Crm\Models\Task;
use App\Livewire\Admin\Crm\TasksIndex;
use App\Livewire\Admin\Crm\VisitsIndex;
use App\Livewire\Admin\MyDay;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('creates a task linked to a lead by its reference', function () {
    $sales = $this->userWithRole('sales');
    $lead = Lead::factory()->create(['owner_id' => $sales->id]);
    $this->actingAs($sales);

    Livewire::test(TasksIndex::class)
        ->call('openCreate')
        ->set('title', 'Share 3D renders')
        ->set('leadRef', $lead->ref)
        ->call('create')
        ->assertHasNoErrors()
        ->assertSet('showCreate', false);

    $task = Task::query()->sole();

    expect($task->lead_id)->toBe($lead->id)
        ->and($task->assignee_id)->toBe($sales->id)
        ->and($task->contact_id)->toBe($lead->contact_id);
});

it('groups open tasks into overdue, today and upcoming, and completes them', function () {
    $sales = $this->userWithRole('sales');
    $overdue = Task::query()->create(['title' => 'Overdue call', 'type' => 'call', 'priority' => 'normal', 'status' => 'open', 'due_at' => now()->subDay(), 'assignee_id' => $sales->id, 'created_by' => $sales->id]);
    Task::query()->create(['title' => 'Later', 'type' => 'todo', 'priority' => 'normal', 'status' => 'open', 'due_at' => now()->addDays(3), 'assignee_id' => $sales->id, 'created_by' => $sales->id]);
    Task::query()->create(['title' => 'Someone else', 'type' => 'todo', 'priority' => 'normal', 'status' => 'open', 'due_at' => now()->addDay(), 'assignee_id' => $this->userWithRole('sales')->id]);

    $this->actingAs($sales);

    Livewire::test(TasksIndex::class)
        ->assertViewHas('groups', fn ($groups) => array_keys($groups) === ['Overdue', 'Upcoming'])
        ->assertSee('Overdue call')
        ->assertDontSee('Someone else')
        ->call('toggle', $overdue->id);

    expect($overdue->fresh()->status)->toBe(TaskStatus::Done)
        ->and($overdue->fresh()->completed_at)->not->toBeNull();
});

it('refuses to complete another person\'s task', function () {
    $sales = $this->userWithRole('sales');
    $task = Task::query()->create(['title' => 'Private', 'type' => 'todo', 'priority' => 'normal', 'status' => 'open', 'assignee_id' => $this->userWithRole('sales')->id]);

    $this->actingAs($sales);

    Livewire::test(TasksIndex::class)->call('toggle', $task->id)->assertForbidden();
});

it('records a visit outcome with measurements and creates the quotation task', function () {
    $sales = $this->userWithRole('sales');
    $lead = Lead::factory()->create([
        'owner_id' => $sales->id,
        'area_sqft' => null,
        'stage_id' => PipelineStage::query()->where('key', 'contacted')->value('id'),
    ]);
    $visit = app(ScheduleSiteVisit::class)->handle($lead, now()->addHour(), 60, 'DHA Phase 5', $sales, $sales);

    $this->actingAs($sales);

    Livewire::test(VisitsIndex::class)
        ->assertSee($lead->contact->name)
        ->call('openComplete', $visit->id)
        ->set('notes', 'Measured kitchen and lounge. Client prefers walnut finish.')
        ->set('measurements', [
            ['room' => 'Kitchen', 'length_ft' => '12', 'width_ft' => '10'],
            ['room' => 'Lounge', 'length_ft' => '20', 'width_ft' => '15'],
            ['room' => '', 'length_ft' => '', 'width_ft' => ''],
        ])
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSet('completingId', null);

    $visit->refresh();
    $lead->refresh();
    $task = $lead->tasks()->sole();

    expect($visit->status)->toBe(VisitStatus::Completed)
        ->and($visit->measurements)->toHaveCount(2)
        ->and($visit->measurements[0])->toMatchArray(['room' => 'Kitchen', 'area_sqft' => 120])
        ->and($lead->area_sqft)->toBe(420)
        ->and($task->title)->toContain('quotation')
        ->and($task->due_at->diffInHours(now()->addHours(48), true))->toBeLessThan(1);
});

it('reschedules and marks no-shows on the agenda', function () {
    $sales = $this->userWithRole('sales');
    $lead = Lead::factory()->create(['owner_id' => $sales->id]);
    $visit = app(ScheduleSiteVisit::class)->handle($lead, now()->addDay(), 60, null, $sales, $sales);

    $this->actingAs($sales);
    $newTime = now()->addDays(3)->setTime(16, 0);

    Livewire::test(VisitsIndex::class)
        ->call('openReschedule', $visit->id)
        ->set('rescheduleAt', $newTime->format('Y-m-d\TH:i'))
        ->call('reschedule')
        ->assertHasNoErrors();

    expect($visit->fresh()->status)->toBe(VisitStatus::Rescheduled)
        ->and($visit->fresh()->scheduled_at->format('Y-m-d H:i'))->toBe($newTime->format('Y-m-d H:i'));

    Livewire::test(VisitsIndex::class)->call('noShow', $visit->id);

    expect($visit->fresh()->status)->toBe(VisitStatus::NoShow);
});

it('hides other people\'s visits from sales users', function () {
    $sales = $this->userWithRole('sales');
    $otherLead = Lead::factory()->create(['owner_id' => $this->userWithRole('sales')->id]);
    $visit = app(ScheduleSiteVisit::class)->handle($otherLead, now()->addDay());

    $this->actingAs($sales);

    Livewire::test(VisitsIndex::class)
        ->assertDontSee($otherLead->contact->name)
        ->call('confirm', $visit->id)
        ->assertForbidden();
});

it('builds My Day from uncontacted leads, overdue follow-ups, visits and tasks', function () {
    // Midday, so "due in an hour" is still today in Karachi whenever the suite runs.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'Asia/Karachi'));
    $sales = $this->userWithRole('sales');
    $new = Lead::factory()->create(['owner_id' => $sales->id, 'first_response_at' => null, 'sla_due_at' => now()->addMinutes(5), 'stage_id' => PipelineStage::query()->where('key', 'new')->value('id')]);
    $overdue = Lead::factory()->create(['owner_id' => $sales->id, 'first_response_at' => now()->subDay(), 'next_action_at' => now()->subHours(3), 'stage_id' => PipelineStage::query()->where('key', 'contacted')->value('id')]);
    // A fixed name: the factory picks from 120 names, so a random one can match a lead of mine (flaky assertDontSee).
    $notMine = Lead::factory()->create(['first_response_at' => null, 'contact_id' => Contact::factory()->create(['name' => 'Someone Else Entirely'])->id]);
    Task::query()->create(['title' => 'Call supplier about veneer', 'type' => 'call', 'priority' => 'normal', 'status' => 'open', 'due_at' => now()->addHour(), 'assignee_id' => $sales->id]);

    $this->actingAs($sales)->get(route('admin.dashboard', ['tab' => 'my-day']))->assertOk();

    Livewire::test(MyDay::class)
        ->assertViewHas('uncontacted', fn ($leads) => $leads->modelKeys() === [$new->id])
        ->assertViewHas('overdue', fn ($leads) => $leads->modelKeys() === [$overdue->id])
        ->assertSee('Call supplier about veneer')
        ->assertDontSee($notMine->contact->name);
});
