<?php

declare(strict_types=1);

use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\PipelineStage;
use App\Livewire\Admin\Crm\LeadsIndex;
use Livewire\Livewire;

/*
| The owner works the list, not a page per lead (request, 2026-10-20): the client drawer opens over the
| list, and stage/owner changes save in place without navigating away.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);

    $this->lead = app(CaptureLead::class)->handle(new LeadInput(
        name: 'Ayesha Khan',
        phone: '0322 4000768',
        email: null,
        source: LeadSource::WebsiteForm,
        businessLine: BusinessLine::Interior,
        city: 'Lahore',
        message: 'Wardrobe for the master bedroom.',
    ))->lead->refresh();
});

it('opens the client drawer without leaving the list', function () {
    Livewire::test(LeadsIndex::class)
        ->call('openLeadDrawer', $this->lead->id)
        ->assertSet('drawerId', $this->lead->id)
        ->assertSet('drawerStage', $this->lead->stage_id)
        ->assertSee('Ayesha Khan')
        ->call('closeLeadDrawer')
        ->assertSet('drawerId', null);
});

it('moves a lead to another stage from the drawer', function () {
    $contacted = PipelineStage::query()->where('key', 'contacted')->firstOrFail();

    Livewire::test(LeadsIndex::class)
        ->call('openLeadDrawer', $this->lead->id)
        ->set('drawerStage', $contacted->id)
        ->call('updateDrawerStage')
        ->assertHasNoErrors()
        ->assertSet('drawerStage', $contacted->id);

    expect($this->lead->refresh()->stage_id)->toBe($contacted->id);
});

it('reassigns the owner from the drawer', function () {
    $manager = $this->userWithRole('sales_manager');

    Livewire::test(LeadsIndex::class)
        ->call('openLeadDrawer', $this->lead->id)
        ->set('drawerOwner', $manager->id)
        ->call('updateDrawerOwner');

    expect($this->lead->refresh()->owner_id)->toBe($manager->id);
});

it('keeps the stage when a manager tries to mark a lead won without an accepted quotation', function () {
    $manager = $this->userWithRole('sales_manager');
    $this->actingAs($manager);

    $won = PipelineStage::query()->where('key', 'won')->firstOrFail();

    Livewire::test(LeadsIndex::class)
        ->call('openLeadDrawer', $this->lead->id)
        ->set('drawerStage', $won->id)
        ->call('updateDrawerStage')
        ->assertSet('drawerStage', $this->lead->stage_id);

    expect($this->lead->refresh()->stage_id)->not->toBe($won->id);
});

/*
| Slice V: the drawer grows the v2.1 side-panel tabs — Details stays pinned, Log activity
| works the lead through the same actions as the full page, Timeline shows what happened.
*/

it('opens the drawer on the details tab with log and timeline alongside', function () {
    Livewire::test(LeadsIndex::class)
        ->call('openLeadDrawer', $this->lead->id)
        ->assertSet('drawerTab', 'details')
        ->assertSee('Log activity')
        ->assertSee('Timeline')
        ->set('drawerTab', 'log')
        ->assertSee('What happened');
});

it('logs a call from the drawer and follows the lead to contacted', function () {
    $contacted = PipelineStage::query()->where('key', 'contacted')->firstOrFail();

    Livewire::test(LeadsIndex::class)
        ->call('openLeadDrawer', $this->lead->id)
        ->set('drawerTab', 'log')
        ->set('drawerLogType', 'call')
        ->set('drawerLogOutcome', 'connected')
        ->set('drawerLogBody', 'Discussed the wardrobe sizes.')
        ->call('drawerLog')
        ->assertHasNoErrors()
        ->assertSet('drawerLogBody', '')
        ->assertSet('drawerStage', $contacted->id);

    expect(Activity::query()->where('lead_id', $this->lead->id)->where('type', 'call')->where('body', 'like', '%wardrobe%')->exists())->toBeTrue()
        ->and($this->lead->refresh()->stage_id)->toBe($contacted->id);
});

it('sets the next action in the same save as the log', function () {
    $when = now()->addDays(2)->setTime(11, 0);

    Livewire::test(LeadsIndex::class)
        ->call('openLeadDrawer', $this->lead->id)
        ->set('drawerTab', 'log')
        ->set('drawerLogType', 'whatsapp')
        ->set('drawerLogBody', 'Sent the wardrobe catalogue.')
        ->set('drawerNextType', 'call')
        ->set('drawerNextAt', $when->format('Y-m-d\TH:i'))
        ->call('drawerLog')
        ->assertHasNoErrors();

    $lead = $this->lead->refresh();

    expect($lead->next_action_type?->value)->toBe('call')
        ->and($lead->next_action_at?->format('Y-m-d H:i'))->toBe($when->format('Y-m-d H:i'))
        ->and(Activity::query()->where('lead_id', $lead->id)->where('type', 'whatsapp')->where('body', 'like', '%catalogue%')->exists())->toBeTrue();
});

it('sets a next action from a quick chip when the lead has none', function () {
    $this->lead->forceFill(['next_action_type' => null, 'next_action_at' => null])->save();

    Livewire::test(LeadsIndex::class)
        ->call('openLeadDrawer', $this->lead->id)
        ->call('drawerQuickNext', 'tomorrow')
        ->assertHasNoErrors();

    expect($this->lead->refresh()->next_action_at)->not->toBeNull();
});

it('never lets a quick chip erase an existing next action', function () {
    $planned = now()->addDays(5)->setTime(10, 30)->startOfMinute();
    $this->lead->forceFill(['next_action_type' => 'whatsapp', 'next_action_at' => $planned])->save();

    Livewire::test(LeadsIndex::class)
        ->call('openLeadDrawer', $this->lead->id)
        ->call('drawerQuickNext', '1h')
        ->assertHasNoErrors();

    $lead = $this->lead->refresh();

    expect($lead->next_action_at?->format('Y-m-d H:i:s'))->toBe($planned->format('Y-m-d H:i:s'))
        ->and($lead->next_action_type?->value)->toBe('whatsapp');
});

it('shows logged activity on the drawer timeline', function () {
    Livewire::test(LeadsIndex::class)
        ->call('openLeadDrawer', $this->lead->id)
        ->set('drawerTab', 'log')
        ->set('drawerLogType', 'note')
        ->set('drawerLogBody', 'Prefers shaker-style wardrobe doors.')
        ->call('drawerLog')
        ->assertHasNoErrors()
        ->set('drawerTab', 'timeline')
        ->assertSee('Prefers shaker-style wardrobe doors.')
        ->assertViewHas('drawerActivityCount', fn ($count) => $count >= 1);
});
