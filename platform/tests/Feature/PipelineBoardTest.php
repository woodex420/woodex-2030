<?php

use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Livewire\Admin\Crm\PipelineBoard;
use Livewire\Livewire;

beforeEach(function () {
    $this->manager = $this->userWithRole('sales_manager');
    $this->lead = Lead::factory()->create(['owner_id' => $this->manager->id]);
    $this->actingAs($this->manager);
});

it('moves a lead between open stages and records it on the timeline', function () {
    Livewire::test(PipelineBoard::class)
        ->call('moveLead', $this->lead->id, 'contacted')
        ->assertDispatched('toast');

    expect($this->lead->fresh()->stage->key)->toBe('contacted')
        ->and(Activity::query()->where('lead_id', $this->lead->id)->where('type', 'stage_change')->exists())->toBeTrue();
});

it('asks for a reason before marking a lead as lost', function () {
    Livewire::test(PipelineBoard::class)
        ->call('moveLead', $this->lead->id, 'lost')
        ->assertSet('pendingLeadId', $this->lead->id)
        ->call('confirmLost')
        ->assertHasErrors('lostReason')
        ->set('lostReason', 'Budget too high')
        ->call('confirmLost')
        ->assertSet('pendingLeadId', null);

    $lead = $this->lead->fresh();
    expect($lead->stage->key)->toBe('lost')
        ->and($lead->lost_reason)->toBe('Budget too high')
        ->and($lead->lost_at)->not->toBeNull();
});

it('refuses Won without an accepted quotation', function () {
    Livewire::test(PipelineBoard::class)
        ->call('moveLead', $this->lead->id, 'won')
        ->assertDispatched('toast', type: 'error');

    expect($this->lead->fresh()->stage_id)->toBe(PipelineStage::byKey('new')->id);
});

it('does not let a sales user move someone else\'s lead', function () {
    $other = $this->userWithRole('sales');

    $this->actingAs($other);

    Livewire::test(PipelineBoard::class)
        ->call('moveLead', $this->lead->id, 'contacted')
        ->assertForbidden();
});

it('opens the visit booking modal when a card is dragged to Site visit without a visit', function () {
    Livewire::test(PipelineBoard::class)
        ->call('moveLead', $this->lead->id, 'site_visit')
        ->assertSet('showVisit', true)
        ->assertSet('visitLeadId', $this->lead->id)
        ->assertSee('Book a site visit');

    // Nothing moved yet: cancelling leaves the card where it was.
    expect($this->lead->fresh()->stage->key)->toBe('new');
});

it('books the visit from the board and moves the card to Site visit', function () {
    Livewire::test(PipelineBoard::class)
        ->call('moveLead', $this->lead->id, 'site_visit')
        ->set('visitAt', now()->addDays(2)->setTime(11, 0)->format('Y-m-d\TH:i'))
        ->call('scheduleVisit')
        ->assertHasNoErrors()
        ->assertSet('showVisit', false)
        ->assertDispatched('toast');

    $lead = $this->lead->fresh();
    expect($lead->stage->key)->toBe('site_visit')
        ->and($lead->siteVisits()->upcoming()->count())->toBe(1);
});

it('moves a lead dragged back from a later stage to Site visit once the visit is booked', function () {
    $this->lead->update(['stage_id' => PipelineStage::byKey('negotiation')->id]);

    Livewire::test(PipelineBoard::class)
        ->call('moveLead', $this->lead->id, 'site_visit')
        ->set('visitAt', now()->addDay()->setTime(15, 0)->format('Y-m-d\TH:i'))
        ->call('scheduleVisit');

    expect($this->lead->fresh()->stage->key)->toBe('site_visit');
});

it('moves straight to Site visit when a visit is already booked', function () {
    $this->lead->siteVisits()->create([
        'contact_id' => $this->lead->contact_id,
        'scheduled_at' => now()->addDay(),
        'assigned_to' => $this->manager->id,
        'status' => 'scheduled',
    ]);

    Livewire::test(PipelineBoard::class)
        ->call('moveLead', $this->lead->id, 'site_visit')
        ->assertSet('showVisit', false);

    expect($this->lead->fresh()->stage->key)->toBe('site_visit');
});

it('warns about a double booking and books only after confirmation', function () {
    $at = now()->addDays(3)->setTime(11, 0);
    $other = Lead::factory()->create(['owner_id' => $this->manager->id]);
    $other->siteVisits()->create([
        'contact_id' => $other->contact_id,
        'scheduled_at' => $at->copy()->addMinutes(30),
        'duration_minutes' => 60,
        'assigned_to' => $this->manager->id,
        'status' => 'scheduled',
    ]);

    $board = Livewire::test(PipelineBoard::class)
        ->call('moveLead', $this->lead->id, 'site_visit')
        ->set('visitAt', $at->format('Y-m-d\TH:i'))
        ->set('visitAssignee', (string) $this->manager->id)
        ->call('scheduleVisit')
        ->assertSet('showVisit', true)
        ->assertSee('Double booking');

    expect($this->lead->siteVisits()->count())->toBe(0);

    $board->call('scheduleVisit')->assertSet('showVisit', false);

    expect($this->lead->siteVisits()->count())->toBe(1);
});
