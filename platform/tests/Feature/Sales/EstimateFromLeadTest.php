<?php

declare(strict_types=1);

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Enums\EstimateStatus;
use App\Domain\Sales\Models\Estimate;
use App\Domain\Sales\Models\EstimatorRate;
use App\Livewire\Admin\Crm\LeadShow;
use App\Livewire\Admin\Sales\EstimateBuilder;
use App\Livewire\Admin\Sales\EstimationsIndex;
use Livewire\Livewire;

/*
| Deep QA 21 Oct: the estimates list was empty while 26 real enquiries sat in CRM, because the
| only way into the builder was an empty form. Every enquiry already carries the service, the
| size and the client, so the builder opens from the lead and the estimate is linked to it —
| the Sales pipeline now starts from real work instead of a blank page.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
});

it('opens the estimate builder from an enquiry with the service, size and client filled', function () {
    $lead = Lead::factory()->create(['service' => 'interior-design', 'area_sqft' => 2400]);
    $rate = EstimatorRate::query()->where('key', 'interior-design')->where('is_active', true)->sole();

    Livewire::actingAs($this->owner)
        ->test(EstimateBuilder::class, ['lead' => $lead->id])
        ->assertOk()
        ->assertSet('leadId', $lead->id)
        ->assertSet('contactId', (string) $lead->contact_id)
        ->assertSet('serviceKey', 'interior-design')
        ->assertSet('quantity', '2400')
        ->assertSet('title', $rate->label.' — '.$lead->contact->name)
        ->assertSee($lead->ref);
});

it('prefills from the real lead-page link, where the enquiry arrives as a query string', function () {
    $lead = Lead::factory()->create(['service' => 'architecture', 'area_sqft' => 1350]);

    $this->actingAs($this->owner)
        ->get(route('admin.estimations.create', ['lead' => $lead->id]))
        ->assertOk()
        ->assertSee('Started from enquiry')
        ->assertSee($lead->ref);
});

it('leaves the service unset when the enquiry uses a service the rate book does not price', function () {
    $lead = Lead::factory()->create(['service' => 'kitchen-design', 'area_sqft' => 900]);

    Livewire::actingAs($this->owner)
        ->test(EstimateBuilder::class, ['lead' => $lead->id])
        ->assertSet('serviceKey', '')
        ->assertSet('quantity', '900')
        ->assertSet('leadId', $lead->id)
        // Never a guessed rate: the person chooses the line and sees the price before saving.
        ->assertSee('choose the service');
});

it('saves an estimate linked to the enquiry and its contact, with no fields typed twice', function () {
    $lead = Lead::factory()->create(['service' => 'interior-design', 'area_sqft' => 2400]);

    Livewire::actingAs($this->owner)
        ->test(EstimateBuilder::class, ['lead' => $lead->id])
        ->call('save')
        ->assertHasNoErrors();

    $estimate = Estimate::query()->sole();

    expect($estimate->lead_id)->toBe($lead->id)
        ->and($estimate->contact_id)->toBe($lead->contact_id)
        ->and($estimate->status)->toBe(EstimateStatus::Draft)
        ->and($estimate->inputs['quantity'])->toBe('2400')
        ->and($estimate->computed['headline_minor'])->toBeGreaterThan(0);

    // The list shows the enquiry behind the estimate.
    Livewire::actingAs($this->owner)
        ->test(EstimationsIndex::class)
        ->assertSee($estimate->number)
        ->assertSee($lead->ref);
});

it('shows the enquiry its estimates on the lead page, with the way to start one', function () {
    $lead = Lead::factory()->create(['service' => 'interior-design', 'area_sqft' => 2400]);

    Livewire::actingAs($this->owner)
        ->test(LeadShow::class, ['lead' => $lead])
        ->assertSee('Start estimate');

    Livewire::actingAs($this->owner)
        ->test(EstimateBuilder::class, ['lead' => $lead->id])
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($this->owner)
        ->test(LeadShow::class, ['lead' => $lead->refresh()])
        ->assertSee(Estimate::query()->sole()->number)
        ->assertDontSee('No estimate for this enquiry yet');
});

it('gives every workflow hub its own document title instead of the bare product name', function () {
    $owner = $this->owner;

    $screens = [
        'admin.leads.index' => 'Leads',
        'admin.contacts.index' => 'Contacts',
        'admin.quotes.index' => 'Quotations',
        'admin.invoices.index' => 'Invoices',
        'admin.payments.index' => 'Payments',
        'admin.reports.index' => 'Reports',
        'admin.sales.documents' => 'Documents',
        'admin.tasks.index' => 'Tasks &amp; Visits',
    ];

    foreach ($screens as $route => $title) {
        $this->actingAs($owner)
            ->get(route($route))
            ->assertOk()
            ->assertSee('<title>'.$title.' · Woodex OS</title>', false);
    }
});
