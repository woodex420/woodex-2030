<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\DeclineQuote;
use App\Domain\Sales\Actions\ReviseQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\AcceptanceChannel;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\QuoteEditor;
use App\Livewire\Admin\Crm\PipelineBoard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| A lead's expected value follows its quotation (master list T-092; defect D-024).
|
| The value was a figure somebody typed. A sale won for 540,000 counted as nothing on the pipeline board unless
| the figure had been typed. The rule now (App\Domain\Sales\Actions\SetLeadValueFromQuote):
|   - a sent quotation fills an empty value, and moves a value that came from an earlier sending;
|   - a figure typed by hand is kept until the client accepts;
|   - an accepted quotation sets the value.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    config(['modules.enabled' => 'lead_api', 'woodex.business_lines' => ['furniture']]);

    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
});

/** A lead with a draft quotation of twelve workstations at the given price each (in rupees). */
function quotedLead(int $each = 45_000, ?int $typedValue = null): array
{
    $lead = Lead::factory()->create([
        'owner_id' => test()->owner->id, 'business_line' => 'furniture', 'service' => 'office-furniture',
        'message' => 'Twelve workstations.', 'created_at' => now()->subDay(),
        'expected_value_minor' => $typedValue === null ? null : $typedValue * 100,
    ]);
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Furniture, test()->owner, $lead, null, 'Office furniture');
    priceEach($quote, $each);

    return [$lead, $quote->refresh()];
}

function priceEach(Quote $quote, int $each): void
{
    app(QuoteEditor::class)->updateLine($quote->items()->firstOrFail(), [
        'product_name' => 'Executive workstation', 'description' => 'Laminate top.', 'unit' => 'nos', 'qty' => '12', 'rate_minor' => $each * 100,
    ]);
}

function send(Quote $quote): Quote
{
    app(SendQuote::class)->handle($quote->refresh(), test()->owner, 'link', false);

    return $quote->refresh();
}

function accept(Quote $quote): Quote
{
    return app(AcceptQuote::class)->handle($quote->refresh(), 'Ayesha Khan', null, null, null, test()->owner, null, AcceptanceChannel::Phone);
}

it('gives the lead the total of the quotation that is sent, when the lead has no value', function () {
    [$lead, $quote] = quotedLead();

    expect($lead->expected_value_minor)->toBeNull();

    $quote = send($quote);

    expect($quote->total_minor)->toBe(54_000_000)
        ->and($lead->refresh()->expected_value_minor)->toBe(54_000_000);
});

it('keeps a figure that was typed by hand when a quotation is sent', function () {
    [$lead, $quote] = quotedLead(typedValue: 700_000);

    send($quote);

    expect($lead->refresh()->expected_value_minor)->toBe(70_000_000);
});

it('moves the value with a revision when the value came from the earlier sending', function () {
    [$lead, $quote] = quotedLead();
    $quote = send($quote);

    $draft = app(ReviseQuote::class)->handle($quote, $this->owner);
    priceEach($draft, 40_000);
    $again = send($draft);

    expect($again->total_minor)->toBe(48_000_000)
        ->and($lead->refresh()->expected_value_minor)->toBe(48_000_000);
});

it('does not move a figure typed after the first sending when a revision is sent', function () {
    [$lead, $quote] = quotedLead();
    $quote = send($quote);
    $lead->refresh()->update(['expected_value_minor' => 60_000_000]); // somebody's own estimate

    $draft = app(ReviseQuote::class)->handle($quote, $this->owner);
    priceEach($draft, 40_000);
    send($draft);

    expect($lead->refresh()->expected_value_minor)->toBe(60_000_000);
});

it('sets the value to what the client accepted, also over a figure typed by hand', function () {
    [$lead, $quote] = quotedLead(typedValue: 700_000);

    accept(send($quote));

    expect($lead->refresh()->expected_value_minor)->toBe(54_000_000)
        ->and($lead->won_at)->not->toBeNull();
});

it('changes nothing when the client declines', function () {
    [$lead, $quote] = quotedLead(typedValue: 700_000);
    $quote = send($quote);

    app(DeclineQuote::class)->handle($quote, $this->owner, 'Chose another supplier.');

    expect($lead->refresh()->expected_value_minor)->toBe(70_000_000);
});

it('adds up the "Won" column of the pipeline to the quotations that were accepted', function () {
    // Three sales: nothing typed, a figure typed too high, a figure typed too low. And one lead still open.
    [$first, $a] = quotedLead(45_000);
    [$second, $b] = quotedLead(30_000, typedValue: 900_000);
    [$third, $c] = quotedLead(20_000, typedValue: 1_000);
    [$open, $d] = quotedLead(10_000);
    foreach ([$a, $b, $c] as $quote) {
        accept(send($quote));
    }
    send($d);

    $won = PipelineStage::query()->where('type', StageType::Won)->firstOrFail();
    $accepted = (int) Quote::query()->where('status', 'accepted')->sum('total_minor');
    $wonValue = (int) Lead::query()->where('stage_id', $won->id)->sum('expected_value_minor');

    expect($accepted)->toBe((12 * 45_000 + 12 * 30_000 + 12 * 20_000) * 100)
        ->and($wonValue)->toBe($accepted)
        ->and($open->refresh()->expected_value_minor)->toBe(12 * 10_000 * 100);

    // The board shows a sum for the column, where it showed nothing for a sale whose value nobody had typed.
    expect(Livewire::test(PipelineBoard::class)->viewData('totals')[$won->id])->not->toBeNull();
});

it('writes the change into the lead\'s list of changes, with who caused it', function () {
    [$lead, $quote] = quotedLead();

    send($quote);

    $change = DB::table('activity_log')->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id)->where('event', 'updated')->orderByDesc('id')->first();

    expect($change)->not->toBeNull()
        ->and((int) $change->causer_id)->toBe($this->owner->id)
        ->and(json_decode((string) $change->attribute_changes, true))->toMatchArray(['attributes' => ['expected_value_minor' => 54_000_000], 'old' => ['expected_value_minor' => null]]);
});

it('leaves a lead that is already won alone when another quotation is sent to the same client', function () {
    [$lead, $quote] = quotedLead();
    accept(send($quote));

    $second = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Furniture, $this->owner, $lead->refresh(), null, 'More chairs');
    priceEach($second, 5_000);
    $second = send($second);

    expect($lead->refresh()->expected_value_minor)->toBe(54_000_000);

    // …until that one is accepted too: then the lead is worth both.
    accept($second);

    expect($lead->refresh()->expected_value_minor)->toBe(54_000_000 + 6_000_000);
});

it('sends and accepts a quotation that belongs to no lead', function () {
    [$lead, $quote] = quotedLead();
    DB::table('quotes')->where('id', $quote->id)->update(['lead_id' => null]);

    accept(send($quote->refresh()));

    expect($quote->refresh()->status->value)->toBe('accepted')
        ->and($lead->refresh()->expected_value_minor)->toBeNull();
});
