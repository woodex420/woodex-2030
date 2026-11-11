<?php

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Livewire\Admin\Sales\QuoteBuilder;
use App\Settings\SalesSettings;
use App\Support\Money;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(11, 0));
    $this->owner = $this->userWithRole('owner');
    $this->lead = Lead::factory()->create(['owner_id' => $this->owner->id, 'business_line' => 'interior']);
    $template = QuoteTemplate::query()->where('slug', 'interior')->firstOrFail();
    $this->quote = app(CreateQuote::class)->handle($this->lead->contact, $template->business_line, $this->owner, $this->lead, $template);
    $this->actingAs($this->owner);
});

function builder(): Testable
{
    return Livewire::test(QuoteBuilder::class, ['quote' => test()->quote]);
}

it('renders the builder with sections, lines and the server total', function () {
    $this->get(route('admin.quotes.show', $this->quote))
        ->assertOk()
        ->assertSee('WI-10100')
        ->assertSee($this->quote->sections->first()->name)
        ->assertSee(Money::ofMinor($this->quote->total_minor)->format(app(SalesSettings::class)->lakh_grouping));
});

it('saves a quantity on blur and recalculates the totals', function () {
    $item = $this->quote->items->first();

    builder()->set("lines.{$item->id}.qty", '10')
        ->set("lines.{$item->id}.rate", '1,500')
        ->assertHasNoErrors();

    expect($item->refresh()->qty)->toBe('10.000')
        ->and($item->rate_minor)->toBe(150_000)
        ->and($item->line_total_minor)->toBe(1_500_000)
        ->and($this->quote->refresh()->total_minor)->toBe((int) $this->quote->items()->where('is_optional', false)->sum('line_total_minor'));
});

it('keeps an invalid value in the field with a message and does not save it', function () {
    $item = $this->quote->items->first();
    $before = $item->qty;

    builder()->set("lines.{$item->id}.qty", '12.3456')
        ->assertHasErrors("lines.{$item->id}.qty")
        ->assertSet("lines.{$item->id}.qty", '12.3456')
        ->assertSee('up to 3 decimals');

    expect($item->refresh()->qty)->toBe($before);
});

it('applies a line discount, then clears it when the type is removed', function () {
    $item = $this->quote->items->first();
    $gross = $item->line_total_minor;

    $component = builder()->set("lines.{$item->id}.discount_type", 'percent')
        ->set("lines.{$item->id}.discount_value", '10');
    expect($item->refresh()->line_total_minor)->toBe($gross - intdiv($gross, 10));

    $component->set("lines.{$item->id}.discount_type", '')->assertSet("lines.{$item->id}.discount_value", '');
    expect($item->refresh()->line_total_minor)->toBe($gross)
        ->and($item->discount_value)->toBe(0);
});

it('adds a line after the current one and asks the browser to focus it', function () {
    $section = $this->quote->sections->first();
    $first = $section->items()->first();
    $count = $section->items()->count();

    builder()->call('addLine', $section->id, $first->id)->assertDispatched('quote-focus');

    $ids = $section->items()->pluck('id');
    expect($ids->get(1))->toBeGreaterThan($first->id)
        ->and($section->items()->count())->toBe($count + 1);
});

it('adds lines from the rate book and sections at the end', function () {
    $section = $this->quote->sections->first();
    $catalog = CatalogItem::query()->whereNull('business_line')->where('rate_minor', '>', 0)->firstOrFail();

    builder()->call('openCatalog', $section->id)
        ->assertSet('showCatalog', true)
        ->set('catalogSearch', mb_substr($catalog->description, 0, 12))
        ->assertSee($catalog->description)
        ->call('addFromCatalog', $catalog->id)
        ->call('addSection');

    expect($section->items()->where('catalog_item_id', $catalog->id)->value('rate_minor'))->toBe($catalog->rate_minor)
        ->and($this->quote->sections()->get()->last()->name)->toBe('New section');
});

it('moves lines between sections and reorders sections', function () {
    [$first, $second] = $this->quote->sections->take(2)->values()->all();
    $line = $first->items()->first();

    builder()->call('moveLine', $line->id, $second->id, 0)->call('shiftSection', $second->id, -1);

    expect($line->refresh()->quote_section_id)->toBe($second->id)
        ->and($second->items()->value('id'))->toBe($line->id)
        ->and($this->quote->sections()->value('id'))->toBe($second->id);
});

it('edits discount, other charges, tax, validity and scope from the side panel', function () {
    builder()->set('details.discount_type', 'amount')
        ->set('details.discount_value', '1,000')
        ->set('details.other_charges_label', 'Transport')
        ->set('details.other_charges', '5000')
        ->set('details.tax', '16')
        ->set('details.valid_until', '2026-12-31')
        ->set('details.scope', "Scope:\n\n- Civil work\n- Wood work")
        ->assertHasNoErrors()
        ->set('details.valid_until', '2026-01-01')
        ->assertHasErrors('details.valid_until');

    $quote = $this->quote->refresh();
    expect($quote->discount_minor)->toBe(100_000)
        ->and($quote->other_charges_minor)->toBe(500_000)
        ->and($quote->tax_bp)->toBe(1600)
        ->and($quote->valid_until->toDateString())->toBe('2026-12-31')
        ->and($quote->scope_html)->toBe("<p>Scope:</p>\n<ul><li>Civil work</li><li>Wood work</li></ul>")
        ->and($quote->total_minor)->toBe(Money::ofMinor($quote->subtotal_minor - 100_000 + 500_000)->percent(11600)->roundToRupee()->minor);
});

it('switches milestone plans and warns while a custom plan does not total 100%', function () {
    $milestone = $this->quote->milestones->first();

    builder()->set("milestones.{$milestone->id}.percent", '40')
        ->assertSee('Payment milestones add up to 90%, not 100%')
        ->set('plan', 'design_75_25')
        ->assertDontSee('not 100%');

    expect($this->quote->milestones()->pluck('percent_bp')->all())->toBe([7500, 2500]);
});

it('duplicates, saves as template and deletes from the builder', function () {
    builder()->call('duplicate')->assertRedirect(route('admin.quotes.show', Quote::query()->where('number', 'WI-10101')->firstOrFail()));

    builder()->call('openSaveTemplate')
        ->set('templateName', 'Interior (owner edit)')
        ->call('saveTemplate')
        ->assertHasNoErrors();
    expect(QuoteTemplate::query()->where('name', 'Interior (owner edit)')->exists())->toBeTrue();

    builder()->call('deleteQuote')->assertRedirect(route('admin.quotes.index'));
    expect(Quote::onlyTrashed()->whereKey($this->quote->id)->exists())->toBeTrue();
});

it('blocks people outside the quote and makes it read-only for viewers', function () {
    $sales = $this->userWithRole('sales');
    $this->actingAs($sales)->get(route('admin.quotes.show', $this->quote))->assertForbidden();

    $accountant = $this->userWithRole('accountant');
    $item = $this->quote->items->first();
    $this->actingAs($accountant);

    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->assertSee('You can view this quotation but not change it.')
        ->set("lines.{$item->id}.qty", '99')
        ->assertForbidden();

    expect($item->refresh()->qty)->not->toBe('99.000');
});

it('shows a sent quotation as read-only, even to the owner', function () {
    $this->quote->update(['status' => QuoteStatus::Sent, 'sent_at' => now()]);
    $sections = $this->quote->sections()->count();

    builder()->assertSee('can no longer be edited')
        ->assertDontSee('Add section')
        ->call('addSection')
        ->assertDispatched('toast');

    expect($this->quote->sections()->count())->toBe($sections);

    $this->actingAs($this->userWithRole('sales_manager'));
    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])->call('addSection')->assertForbidden();
});
