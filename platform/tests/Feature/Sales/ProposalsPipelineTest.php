<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Livewire\Admin\Sales\ProposalsIndex;
use App\Livewire\Admin\Sales\QuotePipeline;
use App\Settings\SalesSettings;
use Livewire\Livewire;

/*
| Slice K — proposals and the pipeline. Both are views over the same `quotes` table, so they cannot disagree with the
| quotation screens: a proposal is a quotation in one of the negotiation statuses, and a pipeline column is a status.
| The forecast is arithmetic on stored totals (Σ total × stage weight) rather than a hand-entered target.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    app(SalesSettings::class)->fill(['lakh_grouping' => false])->save();
});

function pipelineQuote(string $status, int $totalMinor, string $clientName = 'Pipeline Client'): array
{
    $contact = Contact::factory()->create(['name' => $clientName]);
    $lead = Lead::factory()->create([
        'contact_id' => $contact->id,
        'owner_id' => test()->owner->id,
        'business_line' => BusinessLine::Interior->value,
        'source' => 'website_form',
    ]);

    $quote = app(CreateQuote::class)->handle($contact, BusinessLine::Interior, test()->owner, $lead, null, 'Pipeline work');
    $quote->forceFill(['status' => $status, 'total_minor' => $totalMinor])->save();

    return [$contact, $quote->fresh()];
}

it('shows only quotations still in negotiation on the proposals page', function () {
    [$sentClient] = pipelineQuote(QuoteStatus::Sent->value, 300_000 * 100, 'Sent Client');
    [$draftClient] = pipelineQuote(QuoteStatus::Draft->value, 100_000 * 100, 'Draft Client');
    [$wonClient] = pipelineQuote(QuoteStatus::Accepted->value, 900_000 * 100, 'Won Client');
    [$lostClient] = pipelineQuote(QuoteStatus::Declined->value, 400_000 * 100, 'Lost Client');

    Livewire::actingAs($this->owner)
        ->test(ProposalsIndex::class)
        ->assertSee('Sent Client')
        ->assertSee('Draft Client')
        ->assertDontSee('Won Client')
        ->assertDontSee('Lost Client')
        ->assertViewHas('openValue', 400_000 * 100)
        ->assertViewHas('notSent', 1)
        ->assertViewHas('awaitingApproval', 0);
});

it('surfaces approvals the client is waiting on and searches by client', function () {
    pipelineQuote(QuoteStatus::PendingApproval->value, 250_000 * 100, 'Waiting Client');
    pipelineQuote(QuoteStatus::Sent->value, 100_000 * 100, 'Other Client');

    $component = Livewire::actingAs($this->owner)->test(ProposalsIndex::class)
        ->assertViewHas('awaitingApproval', 1);

    $component->set('search', 'Waiting')
        ->assertSee('Waiting Client')
        ->assertDontSee('Other Client')
        ->assertViewHas('openValue', 250_000 * 100);
});

it('puts every quotation in exactly one pipeline column and sums the columns', function () {
    pipelineQuote(QuoteStatus::Draft->value, 100_000 * 100, 'A');
    pipelineQuote(QuoteStatus::Sent->value, 200_000 * 100, 'B');
    pipelineQuote(QuoteStatus::Accepted->value, 500_000 * 100, 'C');

    $component = Livewire::actingAs($this->owner)->test(QuotePipeline::class);

    $component->assertViewHas('columns', function (array $columns): bool {
        $total = collect($columns)->sum(fn (array $column): int => count($column['quotes']));

        return $total === 3
            && $columns['draft']['value'] === 100_000 * 100
            && $columns['sent']['value'] === 200_000 * 100
            && $columns['accepted']['value'] === 500_000 * 100;
    });

    // Weighted forecast = 100k × 10% (draft) + 200k × 60% (sent); accepted is won, not forecast.
    $component->assertViewHas('forecast', 130_000 * 100)
        ->assertViewHas('openValue', 300_000 * 100)
        ->assertViewHas('wonValue', 500_000 * 100)
        ->assertSee('Rs 130,000')
        ->assertSee('Rs 500,000');
});

it('is honest when there is no quotation yet', function () {
    Livewire::actingAs($this->owner)->test(QuotePipeline::class)
        ->assertViewHas('totalQuotes', 0)
        ->assertViewHas('forecast', 0)
        ->assertSee('the pipeline is honestly empty');
});

it('answers the reference product proposal URLs with the quotation tabs', function () {
    $this->actingAs($this->owner)->get('/admin/proposals')->assertRedirect('/admin/quotes?tab=proposals');
    $this->actingAs($this->owner)->get('/admin/sales-pipeline')->assertRedirect('/admin/quotes?tab=pipeline');
    $this->actingAs($this->owner)->get('/admin/quotes?tab=proposals')->assertOk();
    $this->actingAs($this->owner)->get('/admin/quotes?tab=pipeline')->assertOk();
});
