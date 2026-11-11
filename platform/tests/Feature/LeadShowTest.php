<?php

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\TaskStatus;
use App\Domain\Crm\Enums\VisitStatus;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Livewire\Admin\Crm\LeadShow;
use Livewire\Livewire;

beforeEach(function () {
    $this->sales = $this->userWithRole('sales');
    $this->lead = Lead::factory()->create([
        'owner_id' => $this->sales->id,
        'stage_id' => PipelineStage::query()->where('key', 'new')->value('id'),
        'first_response_at' => null,
        'sla_due_at' => now()->addMinutes(10),
    ]);
});

it('opens the lead page for its owner and blocks other sales users', function () {
    $this->actingAs($this->sales)
        ->get(route('admin.leads.show', $this->lead))
        ->assertOk()
        ->assertSeeLivewire(LeadShow::class)
        ->assertSee($this->lead->contact->name)
        ->assertSee($this->lead->ref);

    $this->actingAs($this->userWithRole('sales'))
        ->get(route('admin.leads.show', $this->lead))
        ->assertForbidden();

    $this->actingAs($this->userWithRole('sales_manager'))
        ->get(route('admin.leads.show', $this->lead))
        ->assertOk();
});

it('stops the response timer on the first call even without an answer, but keeps the lead in New', function () {
    $this->actingAs($this->sales);

    Livewire::test(LeadShow::class, ['lead' => $this->lead])
        ->set('logType', 'call')
        ->set('logOutcome', 'no_answer')
        ->call('logActivity')
        ->assertHasNoErrors();

    $lead = $this->lead->fresh();

    expect($lead->first_response_at)->not->toBeNull()
        ->and($lead->stage->key)->toBe('new')
        ->and($lead->activities()->where('type', ActivityType::Call->value)->where('outcome', 'no_answer')->exists())->toBeTrue();
});

it('moves a new lead to Contacted once the client is reached', function () {
    $this->actingAs($this->sales);

    Livewire::test(LeadShow::class, ['lead' => $this->lead])
        ->set('logType', 'call')
        ->set('logOutcome', 'connected')
        ->set('logBody', 'Wants a kitchen and two wardrobes, budget 25 lakh.')
        ->call('logActivity')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $lead = $this->lead->fresh();

    expect($lead->stage->key)->toBe('contacted')
        ->and($lead->first_response_at)->not->toBeNull()
        ->and($lead->last_activity_at)->not->toBeNull();
});

it('suggests WhatsApp after three unanswered calls', function () {
    $this->actingAs($this->sales);
    $page = Livewire::test(LeadShow::class, ['lead' => $this->lead]);

    foreach (['no_answer', 'busy', 'switched_off'] as $outcome) {
        $page->set('logType', 'call')->set('logOutcome', $outcome)->call('logActivity');
    }

    $page->assertDispatched('toast', message: 'Three calls without an answer. Try a WhatsApp message instead.', type: 'warning');
});

it('requires a note body for notes', function () {
    $this->actingAs($this->sales);

    Livewire::test(LeadShow::class, ['lead' => $this->lead])
        ->set('logType', 'note')
        ->set('logBody', '')
        ->call('logActivity')
        ->assertHasErrors(['logBody' => 'required']);
});

it('sets the next action with a quick preset', function () {
    $this->actingAs($this->sales);

    Livewire::test(LeadShow::class, ['lead' => $this->lead])
        ->set('nextType', 'whatsapp')
        ->call('quickNext', 'tomorrow')
        ->assertHasNoErrors();

    $lead = $this->lead->fresh();

    expect($lead->next_action_type->value)->toBe('whatsapp')
        ->and($lead->next_action_at->format('Y-m-d H:i'))->toBe(now()->addDay()->format('Y-m-d').' 11:00');
});

it('books a site visit, moves the lead to Site visit and makes it the next action', function () {
    $this->actingAs($this->sales);
    $when = now()->addDays(2)->setTime(15, 0);

    Livewire::test(LeadShow::class, ['lead' => $this->lead])
        ->call('openVisit')
        ->assertSet('showVisit', true)
        ->set('visitAt', $when->format('Y-m-d\TH:i'))
        ->set('visitAddress', 'House 12, Block K, DHA Phase 6, Lahore')
        ->call('scheduleVisit')
        ->assertHasNoErrors()
        ->assertSet('showVisit', false);

    $lead = $this->lead->fresh();
    $visit = $lead->siteVisits()->sole();

    expect($visit->status)->toBe(VisitStatus::Scheduled)
        ->and($visit->scheduled_at->format('Y-m-d H:i'))->toBe($when->format('Y-m-d H:i'))
        ->and($visit->assigned_to)->toBe($this->sales->id)
        ->and($lead->stage->key)->toBe('site_visit')
        ->and($lead->next_action_type->value)->toBe('visit')
        ->and($lead->next_action_at->format('Y-m-d H:i'))->toBe($when->format('Y-m-d H:i'));
});

it('rejects a site visit in the past', function () {
    $this->actingAs($this->sales);

    Livewire::test(LeadShow::class, ['lead' => $this->lead])
        ->call('openVisit')
        ->set('visitAt', now()->subHour()->format('Y-m-d\TH:i'))
        ->call('scheduleVisit')
        ->assertHasErrors(['visitAt']);
});

it('adds and completes a task from the lead page', function () {
    $this->actingAs($this->sales);

    $page = Livewire::test(LeadShow::class, ['lead' => $this->lead])
        ->set('taskTitle', 'Send kitchen catalogue')
        ->set('taskDue', now()->addDay()->format('Y-m-d\TH:i'))
        ->call('addTask')
        ->assertHasNoErrors();

    $task = $this->lead->tasks()->sole();
    expect($task->assignee_id)->toBe($this->sales->id)->and($task->status)->toBe(TaskStatus::Open);

    $page->call('toggleTask', $task->id);
    expect($task->fresh()->status)->toBe(TaskStatus::Done);
});

it('lets a manager reassign the lead', function () {
    $manager = $this->userWithRole('sales_manager');
    $other = $this->userWithRole('sales');
    $this->actingAs($manager);

    Livewire::test(LeadShow::class, ['lead' => $this->lead])
        ->call('assignTo', (string) $other->id)
        ->assertSet('showReassign', true)
        ->call('confirmReassign')
        ->assertHasErrors('reassignReason')
        ->set('reassignReason', 'Owner is on leave')
        ->call('confirmReassign')
        ->assertHasNoErrors()
        ->assertSet('showReassign', false);

    expect($this->lead->fresh()->owner_id)->toBe($other->id)
        ->and($this->lead->activities()->where('type', 'assignment')->latest('id')->value('body'))->toContain('Owner is on leave');
});

it('does not let a sales user act on another person\'s lead', function () {
    $this->actingAs($this->userWithRole('sales'));

    Livewire::test(LeadShow::class, ['lead' => $this->lead])->assertForbidden();
});
