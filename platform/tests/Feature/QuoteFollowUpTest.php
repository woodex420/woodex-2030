<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\ChaseQuotations;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteEvent;
use App\Domain\Sales\Notifications\QuoteNotification;
use App\Domain\Sales\Services\QuoteEditor;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| AUT, quotation step 3: a quotation that is not answered is not forgotten.
|
| The system never writes to the client by itself — it tells the studio to call, warns before the price lapses,
| and lets a stale quotation lapse so nobody quotes a price the studio no longer stands behind.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->sales = $this->userWithRole('sales');
    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior']);
    app(SalesSettings::class)->fill([
        'quote_follow_ups_enabled' => true,
        'quote_follow_up_days' => 3,
        'quote_expiry_warning_days' => 3,
    ])->save();
});

/** A complete quotation, sent now (the test sets the date), valid for a chosen number of days. */
function chasedQuote(int $validDays = 15): Quote
{
    $quote = app(CreateQuote::class)->handle(test()->lead->contact, BusinessLine::Interior, test()->sales, test()->lead, null, 'Kitchen');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Kitchen');
    $editor->addLine($section, ['description' => 'Kitchen cabinets', 'unit' => 'rft', 'qty' => '10', 'rate_minor' => 2_500_000]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));

    $quote->forceFill(['valid_until' => today()->addDays($validDays)])->save();
    $version = app(SendQuote::class)->handle($quote->refresh(), test()->sales, 'link');

    return $version->quote->refresh();
}

it('tells the team to call about a quotation nobody answered', function () {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(9, 0));
    $quote = chasedQuote();

    $this->travelTo(now()->addDays(4));
    $done = app(ChaseQuotations::class)->handle();

    expect($done['chased'])->toBe(1)
        ->and(QuoteEvent::query()->where('type', 'follow_up')->count())->toBe(1);

    Notification::assertSentTo($this->sales, QuoteNotification::class);
});

it('leaves a quotation alone until it has been quiet long enough', function () {
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(9, 0));
    $quote = chasedQuote();

    $this->travelTo(now()->addDays(2));
    expect(app(ChaseQuotations::class)->handle()['chased'])->toBe(0)
        ->and(QuoteEvent::query()->where('type', 'follow_up')->count())->toBe(0);
});

it('chases again after another quiet period, then stops — three unanswered chases are an answer', function () {
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(9, 0));
    $quote = chasedQuote();

    for ($week = 1; $week <= 5; $week++) {
        $this->travelTo(now()->addDays(4));
        app(ChaseQuotations::class)->handle();
    }

    expect(QuoteEvent::query()->where('type', 'follow_up')->count())->toBe(3);
});

it('does not chase a quotation the client already accepted', function () {
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(9, 0));
    $quote = chasedQuote();
    $quote->update(['status' => QuoteStatus::Accepted, 'accepted_at' => now()]);

    $this->travelTo(now()->addDays(4));

    expect(app(ChaseQuotations::class)->handle()['chased'])->toBe(0);
});

it('warns once before a quotation lapses', function () {
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(9, 0));
    $quote = chasedQuote(10);

    $this->travelTo(now()->addDays(8)); // two days left
    expect(app(ChaseQuotations::class)->handle()['expiring'])->toBe(1);

    $this->travelTo(now()->addDay());
    expect(app(ChaseQuotations::class)->handle()['expiring'])->toBe(0) // told once, not every day
        ->and(QuoteEvent::query()->where('type', 'expiry_warning')->count())->toBe(1);
});

it('lets a stale quotation lapse, so nobody quotes a price the studio no longer stands behind', function () {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(9, 0));
    $quote = chasedQuote(10);

    $this->travelTo(now()->addDays(11));
    expect(app(ChaseQuotations::class)->handle()['expired'])->toBe(1);

    $quote->refresh();

    expect($quote->status)->toBe(QuoteStatus::Expired)
        ->and($quote->expired_at)->not->toBeNull()
        ->and(QuoteEvent::query()->where('type', 'expired')->exists())->toBeTrue();

    Notification::assertSentTo($this->sales, QuoteNotification::class);

    // It is not chased any more: a lapsed price is not a live opportunity.
    $this->travelTo(now()->addDays(5));
    expect(app(ChaseQuotations::class)->handle())->toBe(['chased' => 0, 'expiring' => 0, 'expired' => 0]);
});

it('stays silent when the studio switches chasing off', function () {
    app(SalesSettings::class)->fill(['quote_follow_ups_enabled' => false])->save();
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(9, 0));
    $quote = chasedQuote();

    $this->travelTo(now()->addDays(10));

    expect(app(ChaseQuotations::class)->handle())->toBe(['chased' => 0, 'expiring' => 0, 'expired' => 0])
        ->and($quote->refresh()->status)->toBe(QuoteStatus::Sent);
});

it('runs as a scheduled command and reports what it did', function () {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(9, 0));
    $quote = chasedQuote();

    $this->travelTo(now()->addDays(4));
    $this->artisan('woodex:quote-follow-ups');

    expect(QuoteEvent::query()->where('type', 'follow_up')->exists())->toBeTrue();
});
