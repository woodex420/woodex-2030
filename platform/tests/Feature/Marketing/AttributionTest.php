<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Livewire\Admin\Marketing\AttributionIndex;
use App\Settings\SalesSettings;
use Livewire\Livewire;

/*
| Slice M — attribution: source → enquiry → quoted → won value. Cohorts follow the enquiry to its outcome.
| Ad spend is absent on purpose (Meta approval pending): the page must never dress an enquiry count up as
| a cost per lead.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->sales = $this->userWithRole('sales');
    app(SalesSettings::class)->fill(['lakh_grouping' => false])->save();
});

function attributionLead(string $source, ?string $createdAt = null): Lead
{
    $lead = Lead::factory()->create([
        'contact_id' => Contact::factory()->create()->id,
        'owner_id' => test()->owner->id,
        'business_line' => BusinessLine::Interior->value,
        'source' => $source,
    ]);

    if ($createdAt !== null) {
        $lead->forceFill(['created_at' => $createdAt])->saveQuietly();
    }

    return $lead;
}

function attributionQuote(Lead $lead, ?QuoteStatus $status = null, int $totalMinor = 0): void
{
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, test()->owner, $lead, null, 'Attribution work');

    if ($status !== null) {
        $quote->forceFill(['status' => $status->value, 'total_minor' => $totalMinor])->save();
    }
}

it('follows each source from enquiry to won value', function () {
    $won = attributionLead('website_form');
    attributionQuote($won, QuoteStatus::Accepted, 500_000 * 100);
    attributionLead('website_form');

    $quotedOnly = attributionLead('referral');
    attributionQuote($quotedOnly, QuoteStatus::Sent, 300_000 * 100);

    $component = Livewire::actingAs($this->owner)->test(AttributionIndex::class);

    $component->assertViewHas('rows', function ($rows): bool {
        $bySource = collect($rows)->keyBy(fn (array $row): string => $row['source']->value);

        return $bySource['website_form']['enquiries'] === 2
            && $bySource['website_form']['quoted'] === 1
            && $bySource['website_form']['won'] === 1
            && $bySource['website_form']['won_minor'] === 500_000 * 100
            && $bySource['referral']['enquiries'] === 1
            && $bySource['referral']['quoted'] === 1
            && $bySource['referral']['won'] === 0;
    })->assertViewHas('totals', fn (array $totals): bool => $totals['enquiries'] === 3 && $totals['won_minor'] === 500_000 * 100)
        ->assertSee('Website Form')
        ->assertSee('Rs 500,000')
        ->assertSee('Ad spend is not shown yet');
});

it('keeps old enquiries out of the window until asked for all time', function () {
    attributionLead('google', now()->subYear()->toDateTimeString());
    attributionLead('google');

    $component = Livewire::actingAs($this->owner)->test(AttributionIndex::class);

    $component->assertViewHas('totals', fn (array $totals): bool => $totals['enquiries'] === 1);

    $component->set('range', 'all')
        ->assertViewHas('totals', fn (array $totals): bool => $totals['enquiries'] === 2);
});

it('keeps the old planner URL working behind the same permission', function () {
    $this->actingAs($this->owner)->get('/admin/marketing/social')->assertRedirect('/admin/marketing?tab=planner');
    $this->actingAs($this->owner)->get('/admin/marketing')->assertOk();
    $this->actingAs($this->owner)->get('/admin/marketing?tab=attribution')->assertOk();
    $this->actingAs($this->sales)->get('/admin/marketing')->assertForbidden();
    $this->actingAs($this->sales)->get('/admin/marketing/social')->assertForbidden();
});
