<?php

use App\Domain\Crm\Models\AssignmentRule;
use App\Domain\Crm\Models\Lead;
use App\Livewire\Admin\Crm\AssignmentRules;
use App\Livewire\Admin\Crm\VisitsIndex;
use App\Livewire\Admin\System\LeadRulesSettings;
use App\Livewire\Admin\System\SettingsPage;
use App\Settings\BusinessSettings;
use App\Settings\NotificationSettings;
use App\Settings\SalesSettings;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = $this->userWithRole('admin');
    $this->actingAs($this->admin);
});

it('saves holidays from the business tab and rejects bad dates', function () {
    Livewire::test(SettingsPage::class)
        ->set('business.holidays', "2027-03-10 Eid ul Fitr\n2027-02-30 Not a day")
        ->call('saveBusiness')
        ->assertHasErrors('business.holidays')
        ->set('business.holidays', "2027-03-11 Eid ul Fitr (day 2)\n2027-03-10 Eid ul Fitr")
        ->call('saveBusiness')
        ->assertHasNoErrors();

    expect(app(BusinessSettings::class)->refresh()->holidays)->toBe([
        ['date' => '2027-03-10', 'name' => 'Eid ul Fitr'],
        ['date' => '2027-03-11', 'name' => 'Eid ul Fitr (day 2)'],
    ]);
});

it('saves the final escalation roles', function () {
    Livewire::test(SettingsPage::class)
        ->set('notifications.sla_final_roles', ['owner', 'admin'])
        ->call('saveNotifications')
        ->assertHasNoErrors();

    expect(app(NotificationSettings::class)->refresh()->sla_final_roles)->toBe(['owner', 'admin']);
});

it('saves response targets per source and scoring rules', function () {
    $this->get(route('admin.settings', ['tab' => 'leads']))->assertOk();

    Livewire::test(LeadRulesSettings::class)
        ->set('sla.by_source.facebook_lead_ad', '20')
        ->set('sla.escalation', '45')
        ->set('scoring.hot', '75')
        ->set('scoring.cities', "dha = 5\ngulberg: 3")
        ->set('scoring.services', 'turnkey = ten')
        ->call('save')
        ->assertHasErrors('scoring.services')
        ->set('scoring.services', "turnkey = 12\nkitchen = 4")
        ->call('save')
        ->assertHasNoErrors();

    $sales = app(SalesSettings::class)->refresh();
    expect($sales->sla_minutes_by_source['facebook_lead_ad'])->toBe(20)
        ->and($sales->sla_escalation_minutes)->toBe(45)
        ->and($sales->hot_score)->toBe(75)
        ->and($sales->scoring_rules['cities'])->toBe(['dha' => 5, 'gulberg' => 3])
        ->and(\App\Support\JsonObjects::canonical($sales->scoring_rules['services']))->toBe(\App\Support\JsonObjects::canonical(['turnkey' => 12, 'kitchen' => 4]))
        ->and($sales->scoring_rules['budget']['15m-plus'])->toBe(30);
});

it('manages assignment rules from the screen', function () {
    $this->get(route('admin.assignment-rules'))->assertOk()->assertSee('No rules yet');
    $sales = $this->userWithRole('sales', ['name' => 'Hina']);

    Livewire::test(AssignmentRules::class)
        ->call('openCreate')
        ->set('rule.name', 'Furniture showroom')
        ->set('rule.business_lines', ['furniture'])
        ->set('rule.cities', 'Lahore, DHA')
        ->set('rule.assign_to', 'people')
        ->set('rule.user_ids', [(string) $sales->id])
        ->set('rule.max_open_leads', '30')
        ->call('save')
        ->assertHasNoErrors()
        ->call('openCreate')
        ->set('rule.name', 'Everything else')
        ->call('save');

    [$first, $second] = AssignmentRule::query()->orderBy('position')->get()->all();
    expect($first->conditions['cities'])->toBe(['Lahore', 'DHA'])
        ->and($first->user_ids)->toBe([$sales->id])
        ->and($second->role)->toBe('sales');

    Livewire::test(AssignmentRules::class)
        ->call('move', $second->id, -1)
        ->call('toggle', $first->id)
        ->assertSee('Paused');

    expect(AssignmentRule::query()->orderBy('position')->first()->id)->toBe($second->id)
        ->and($first->fresh()->is_active)->toBeFalse();
});

it('keeps assignment rules away from sales users', function () {
    $this->actingAs($this->userWithRole('sales'));
    $this->get(route('admin.assignment-rules'))->assertForbidden();
});

it('shows site visits on week and month calendars', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Karachi'));
    $lead = Lead::factory()->create(['owner_id' => $this->admin->id]);
    $lead->siteVisits()->create(['contact_id' => $lead->contact_id, 'scheduled_at' => now()->addDay()->setTime(11, 30), 'assigned_to' => $this->admin->id, 'status' => 'scheduled']);

    Livewire::test(VisitsIndex::class)
        ->set('range', 'week')
        ->assertSee('19 – 25 Oct 2026')
        ->assertSee($lead->contact->name)
        ->call('shiftCalendar', 1)
        ->assertSet('date', '2026-10-27')
        ->assertDontSee($lead->contact->name)
        ->set('range', 'month')
        ->call('today')
        ->assertSee('October 2026')
        ->assertSee($lead->contact->name)
        ->call('showWeekOf', '2026-10-21')
        ->assertSet('range', 'week');
});
