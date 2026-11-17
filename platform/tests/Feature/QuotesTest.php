<?php

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\DeleteQuote;
use App\Domain\Sales\Actions\DuplicateQuote;
use App\Domain\Sales\Actions\SaveQuoteAsTemplate;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteLocked;
use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Domain\Sales\Services\QuoteEditor;
use App\Settings\SalesSettings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(11, 0));
    $this->owner = $this->userWithRole('owner');
    $this->lead = Lead::factory()->create(['owner_id' => $this->owner->id, 'business_line' => 'interior']);
    $this->renovation = QuoteTemplate::query()->where('slug', 'renovation')->firstOrFail();
});

function quoteFromTemplate(string $slug, ?Lead $lead = null): Quote
{
    $template = QuoteTemplate::query()->where('slug', $slug)->firstOrFail();
    $lead ??= test()->lead;

    return app(CreateQuote::class)->handle($lead->contact, $template->business_line, test()->owner, $lead, $template);
}

it('creates a numbered draft from the Renovation template with every line and exact totals', function () {
    $quote = app(CreateQuote::class)->handle($this->lead->contact, BusinessLine::Interior, $this->owner, $this->lead, $this->renovation);

    expect($quote->number)->toBe('WI-10100')
        ->and($quote->status)->toBe(QuoteStatus::Draft)
        ->and($quote->title)->toBe('Renovation')
        ->and($quote->valid_until->toDateString())->toBe('2026-12-09') // 15 days
        ->and($quote->sections)->toHaveCount(10)
        ->and($quote->items)->toHaveCount(42)
        ->and($quote->milestonePlan())->toBe(MilestonePlanKey::Execution)
        ->and($quote->terms)->toBe($this->renovation->terms);

    // Independent reference: Σ round_half_up(qty × rate) over the template, in paisa (QUO-004 acceptance).
    $expected = BigDecimal::zero();
    foreach ($this->renovation->blueprint['sections'] as $section) {
        foreach ($section['items'] as $item) {
            $expected = $expected->plus(BigDecimal::of((string) $item['qty'])->multipliedBy($item['rate_minor'])->toScale(0, RoundingMode::HalfUp));
        }
    }

    expect($quote->subtotal_minor)->toBe($expected->toInt())
        ->and($quote->total_minor)->toBe($expected->toInt())
        ->and($quote->sections->sum('subtotal_minor'))->toBe($quote->subtotal_minor)
        ->and($quote->items->sum('line_total_minor'))->toBe($quote->subtotal_minor);

    $milestones = $quote->milestones;
    expect($milestones->pluck('percent_bp')->all())->toBe([5000, 4000, 1000])
        ->and($milestones->sum('amount_minor'))->toBe($quote->total_minor);

    $activity = Activity::query()->where('lead_id', $this->lead->id)->where('type', ActivityType::QuoteEvent->value)->sole();
    expect($activity->body)->toContain('WI-10100')->toContain('Renovation')
        ->and($activity->subject_id)->toBe($quote->id)
        ->and($quote->events()->where('type', 'created')->exists())->toBeTrue();
});

it('numbers furniture quotes in the WF series and starts blank quotes with one empty line', function () {
    $furniture = quoteFromTemplate('furniture');
    $blank = app(CreateQuote::class)->handle($this->lead->contact, BusinessLine::Interior, $this->owner, $this->lead, null, 'Kitchen only');
    $second = quoteFromTemplate('interior');

    expect($furniture->number)->toBe('WF-10050')
        ->and($blank->number)->toBe('WI-10100')
        ->and($second->number)->toBe('WI-10101')
        ->and($blank->title)->toBe('Kitchen only')
        ->and($blank->items)->toHaveCount(1)
        ->and($blank->total_minor)->toBe(0)
        ->and($blank->terms)->toBe(app(SalesSettings::class)->execution_terms);
});

it('uses the design plan and terms for design templates and zeroes blank template rates', function () {
    $design = quoteFromTemplate('interior-designing');
    expect($design->milestones->pluck('percent_bp')->all())->toBe([7500, 2500])
        ->and($design->milestonePlan())->toBe(MilestonePlanKey::Design);

    $scope = quoteFromTemplate('turnkey-solution');
    expect($scope->items->every(fn ($item) => $item->rate_minor === 0))->toBeTrue()
        ->and($scope->items->where('qty', '0.000'))->not->toBeEmpty()
        ->and($scope->total_minor)->toBe(0);
});

it('refuses an enquiry that belongs to another client', function () {
    $other = Lead::factory()->create();

    app(CreateQuote::class)->handle($this->lead->contact, BusinessLine::Interior, $this->owner, $other, $this->renovation);
})->throws(InvalidArgumentException::class);

it('recalculates line, quote discount, other charges, tax and milestones after every edit', function () {
    $quote = quoteFromTemplate('civil-work');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections->first();

    // Replace the template lines with known ones.
    $quote->items->each(fn ($item) => $editor->deleteLine($item));
    $a = $editor->addLine($section, ['description' => 'Brick work', 'unit' => 'sft', 'qty' => '256', 'rate_minor' => 200_000]);
    $b = $editor->addLine($section, ['description' => 'Wood work', 'unit' => 'sft', 'qty' => '121.125', 'rate_minor' => 120_000]);
    $editor->addLine($section, ['description' => 'Optional chandelier', 'unit' => 'nos', 'qty' => '1', 'rate_minor' => 9_999_900, 'is_optional' => true]);

    $editor->updateLine($a, ['discount_type' => DiscountType::Percent, 'discount_value' => 1000]); // −10%
    $editor->updateDetails($quote, [
        'discount_type' => DiscountType::Percent, 'discount_value' => 250,          // 2.5%
        'other_charges_label' => 'Transport', 'other_charges_minor' => 2_000_000,  // Rs 20,000
        'tax_bp' => 1600,                                                            // 16%
    ]);

    $quote->refresh();
    // 512,000 − 51,200 = 460,800; 145,350; subtotal 606,150; discount 15,153.75 → 15,154 (half up)
    // taxable 606,150 − 15,153.75 + 20,000 = 610,996.25; tax 16% = 97,759.40; total 708,755.65 → Rs 708,756
    expect($quote->subtotal_minor)->toBe(60_615_000)
        ->and($quote->discount_minor)->toBe(1_515_375)
        ->and($quote->tax_minor)->toBe(9_775_940)
        ->and($quote->total_minor)->toBe(70_875_600)
        ->and($quote->rounding_minor)->toBe(35)
        ->and($a->refresh()->line_total_minor)->toBe(46_080_000)
        ->and($b->refresh()->line_total_minor)->toBe(14_535_000)
        ->and($quote->milestones->sum('amount_minor'))->toBe($quote->total_minor);
});

it('keeps positions in order when lines and sections move or are deleted', function () {
    $quote = quoteFromTemplate('interior');
    $editor = app(QuoteEditor::class);
    [$first, $second] = $quote->sections->take(2)->values()->all();
    $line = $first->items()->first();

    $editor->moveLine($line, $second, 1);
    expect($line->refresh()->quote_section_id)->toBe($second->id)
        ->and($second->items()->pluck('position')->all())->toBe(range(0, $second->items()->count() - 1))
        ->and($first->items()->pluck('position')->all())->toBe(range(0, $first->items()->count() - 1))
        ->and($second->items()->pluck('id')->get(1))->toBe($line->id);

    $after = $editor->addLine($second, ['description' => 'Inserted'], $line);
    expect($second->items()->pluck('id')->get(2))->toBe($after->id);

    $editor->moveSection($second, 0);
    expect($quote->sections()->pluck('id')->first())->toBe($second->id);

    $editor->deleteSection($first);
    expect($quote->sections()->pluck('position')->all())->toBe(range(0, $quote->sections()->count() - 1))
        ->and($quote->refresh()->subtotal_minor)->toBe((int) $quote->items()->where('is_optional', false)->sum('line_total_minor'));
});

it('edits milestones as a custom plan and splits the total so it always adds up', function () {
    $quote = quoteFromTemplate('interior');
    $editor = app(QuoteEditor::class);
    $milestones = $quote->milestones;

    $editor->updateMilestone($milestones[0], ['percent_bp' => 3333]);
    expect($quote->refresh()->milestonePlan())->toBe(MilestonePlanKey::Custom)
        ->and((int) $quote->milestones()->sum('percent_bp'))->toBe(8333);

    $editor->addMilestone($quote); // takes the remaining 16.67 %
    $quote->refresh();
    expect((int) $quote->milestones->sum('percent_bp'))->toBe(10000)
        ->and($quote->milestones->sum('amount_minor'))->toBe($quote->total_minor);

    $editor->setMilestonePlan($quote, MilestonePlanKey::Design);
    expect($quote->milestones()->pluck('percent_bp')->all())->toBe([7500, 2500]);
});

it('copies rate book items into the quote so later rate changes do not alter it', function () {
    $quote = quoteFromTemplate('interior');
    $item = CatalogItem::query()->where('rate_minor', '>', 0)->firstOrFail();

    $rate = (int) $item->rate_minor;

    $line = app(QuoteEditor::class)->addCatalogLine($quote->sections->first(), $item);
    $item->update(['rate_minor' => $rate * 2]);

    expect($line->refresh()->rate_minor)->toBe($rate)
        ->and($line->catalog_item_id)->toBe($item->id)
        ->and($line->description)->toBe($item->description);
});

it('locks quotes that are no longer drafts', function () {
    $quote = quoteFromTemplate('interior');
    $quote->update(['status' => QuoteStatus::Sent, 'sent_at' => now()]);

    expect(fn () => app(QuoteEditor::class)->updateDetails($quote, ['title' => 'Changed']))->toThrow(QuoteLocked::class)
        ->and(fn () => app(QuoteEditor::class)->updateLine($quote->items->first(), ['qty' => '5']))->toThrow(QuoteLocked::class)
        ->and(fn () => app(DeleteQuote::class)->handle($quote, $this->owner))->toThrow(QuoteLocked::class);
});

it('duplicates a quote with a new number and the same content', function () {
    $source = quoteFromTemplate('renovation');
    app(QuoteEditor::class)->updateDetails($source, ['discount_type' => DiscountType::Amount, 'discount_value' => 5_000_000]);
    $sales = $this->userWithRole('sales');

    $copy = app(DuplicateQuote::class)->handle($source->refresh(), $sales);

    expect($copy->number)->toBe('WI-10101')
        ->and($copy->owner_id)->toBe($sales->id)
        ->and($copy->items)->toHaveCount(42)
        ->and($copy->total_minor)->toBe($source->total_minor)
        ->and($copy->discount_minor)->toBe(5_000_000)
        ->and($copy->meta['duplicated_from'])->toBe($source->id)
        ->and($copy->status)->toBe(QuoteStatus::Draft);
});

it('saves a quote as a new template or updates an existing one', function () {
    $quote = quoteFromTemplate('interior');
    $editor = app(QuoteEditor::class);
    $editor->addLine($quote->sections->first(), ['description' => 'Fluted panels', 'unit' => 'sft', 'qty' => '80', 'rate_minor' => 180_000, 'internal_cost_minor' => 110_000]);

    $template = app(SaveQuoteAsTemplate::class)->handle($quote->refresh(), 'Interior with panels');
    expect($template->slug)->toBe('interior-with-panels')
        ->and($template->itemCount())->toBe($quote->items()->count())
        ->and(collect($template->blueprint['sections'][0]['items'])->last())->toMatchArray(['description' => 'Fluted panels', 'qty' => '80', 'rate_minor' => 180_000, 'internal_cost_minor' => 110_000]);

    $again = app(SaveQuoteAsTemplate::class)->handle($quote, 'Interior with panels');
    expect($again->slug)->toBe('interior-with-panels-2');

    $interior = QuoteTemplate::query()->where('slug', 'interior')->firstOrFail();
    app(SaveQuoteAsTemplate::class)->handle($quote, 'Interior', $interior);
    expect($interior->refresh()->itemCount())->toBe($quote->items()->count());
});

it('moves an unsent draft to the recycle bin with a timeline entry', function () {
    $quote = quoteFromTemplate('interior');

    app(DeleteQuote::class)->handle($quote, $this->owner);

    expect(Quote::query()->find($quote->id))->toBeNull()
        ->and(Quote::onlyTrashed()->find($quote->id))->not->toBeNull()
        ->and(Activity::query()->where('lead_id', $this->lead->id)->where('body', 'like', '%recycle bin%')->exists())->toBeTrue();
});

it('shows sales people only their own quotes and those of their leads', function () {
    $sales = $this->userWithRole('sales');
    $accountant = $this->userWithRole('accountant');
    $mine = quoteFromTemplate('interior', Lead::factory()->create(['owner_id' => $sales->id]));
    $theirs = quoteFromTemplate('interior');
    $ownedByMe = quoteFromTemplate('interior');
    $ownedByMe->update(['owner_id' => $sales->id]);

    expect(Quote::query()->visibleTo($sales)->pluck('id')->sort()->values()->all())->toBe(collect([$mine->id, $ownedByMe->id])->sort()->values()->all())
        ->and($sales->can('view', $theirs))->toBeFalse()
        ->and($sales->can('update', $mine))->toBeTrue()
        ->and($accountant->can('view', $theirs))->toBeTrue()
        ->and($accountant->can('update', $theirs))->toBeFalse()
        ->and($sales->can('manageCatalog', Quote::class))->toBeFalse()
        ->and($this->userWithRole('sales_manager')->can('manageCatalog', Quote::class))->toBeTrue();
});
