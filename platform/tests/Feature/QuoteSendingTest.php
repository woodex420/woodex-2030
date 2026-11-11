<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\DecideQuoteApproval;
use App\Domain\Sales\Actions\ReopenQuote;
use App\Domain\Sales\Actions\RequestQuoteApproval;
use App\Domain\Sales\Actions\ReviseQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Mail\QuoteSentMail;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteVersion;
use App\Domain\Sales\Notifications\QuoteNotification;
use App\Domain\Sales\Services\QuoteApprovalRules;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\System\Enums\ApprovalStatus;
use App\Domain\System\Models\Approval;
use App\Livewire\Admin\Sales\Approvals;
use App\Livewire\Admin\Sales\QuoteBuilder;
use App\Models\User;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| P2 part 2: approvals (QUO-007), send + immutable versions (QUO-008), revisions (QUO-013) and the PDF (QUO-014).
*/

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(11, 0));
    Storage::fake('local');
    $this->owner = $this->userWithRole('owner');
    $this->manager = $this->userWithRole('sales_manager');
    $this->sales = $this->userWithRole('sales');
    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior']);
});

/** A small, complete quotation: 2 lines, Rs 4,00,000, execution milestones. */
function sendableQuote(?User $by = null, int $discountBp = 0): Quote
{
    $by ??= test()->sales;
    $quote = app(CreateQuote::class)->handle(test()->lead->contact, BusinessLine::Interior, $by, test()->lead, null, 'Kitchen and wardrobes');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Kitchen');
    $editor->addLine($section, ['description' => 'Kitchen cabinets, lacquer finish', 'unit' => 'rft', 'qty' => '10', 'rate_minor' => 2_500_000]);
    $editor->addLine($section, ['description' => 'Wardrobe, laminate finish', 'unit' => 'sqft', 'qty' => '50', 'rate_minor' => 300_000]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));

    if ($discountBp > 0) {
        $editor->updateDetails($quote->refresh(), ['discount_type' => DiscountType::Percent, 'discount_value' => $discountBp]);
    }

    return $quote->refresh();
}

it('measures the discount against the sender\'s role limit and the minimum margin', function () {
    $quote = sendableQuote(discountBp: 800);
    $rules = app(QuoteApprovalRules::class);

    expect($quote->total_minor)->toBe(36_800_000)
        ->and($rules->assess($quote, $this->sales)->discountBp)->toBe(800)
        ->and($rules->assess($quote, $this->sales)->needed)->toBeTrue()      // sales limit 5%
        ->and($rules->assess($quote, $this->manager)->needed)->toBeFalse()   // manager limit 10%
        ->and($rules->assess($quote, $this->owner)->needed)->toBeFalse()     // owner 100%
        ->and($rules->assess($quote, $this->sales)->reasons[0])->toBe('Discount 8% is above the 5% limit for your role.');

    $settings = app(SalesSettings::class);
    $settings->min_margin_bp = 3000;
    $settings->save();
    $quote->items()->each(fn ($item) => app(QuoteEditor::class)->updateLine($item, ['internal_cost_minor' => (int) ($item->line_total_minor * 0.8)]));
    $quote->refresh();

    expect($rules->assess($quote, $this->manager)->needed)->toBeTrue()
        ->and($rules->assess($quote, $this->manager)->reasons)->toContain('Margin '.QuoteApprovalRules::percent((int) $quote->margin_bp).' is below the 30% minimum.');
});

it('lists what is missing before a quotation can be sent', function () {
    $quote = app(CreateQuote::class)->handle($this->lead->contact, BusinessLine::Interior, $this->sales, $this->lead, null, 'Empty');
    $quote->update(['valid_until' => now()->subDay()->toDateString()]);

    $problems = app(SendQuote::class)->problems($quote);

    expect($problems)->toContain('The total must be more than zero.')
        ->toContain('Set a "valid until" date from today onwards.')
        ->and(implode(' ', $problems))->toMatch('/line/');

    $quote->items()->each(fn ($item) => app(QuoteEditor::class)->deleteLine($item));
    expect(app(SendQuote::class)->problems($quote->refresh()))->toContain('Add at least one line that is not optional.');

    expect(fn () => app(SendQuote::class)->handle($quote, $this->sales))->toThrow(ValidationException::class);
});

it('sends: frozen version with snapshot and PDF hash, status, event and optional email', function () {
    Mail::fake();
    $quote = sendableQuote();

    $version = app(SendQuote::class)->handle($quote, $this->sales, 'link', email: true);
    $quote->refresh();

    expect($quote->status)->toBe(QuoteStatus::Sent)
        ->and($quote->sent_at)->not->toBeNull()
        ->and($version->revision)->toBe(0)
        ->and($version->total_minor)->toBe(40_000_000)
        ->and($version->snapshot['label'])->toBe($quote->number)
        ->and($version->snapshot['totals']['total_words'])->toBe('Rupees Four Lakh Only')
        ->and($version->snapshot['client']['name'])->toBe($this->lead->contact->name)
        ->and($version->snapshot)->not->toHaveKey('internal_cost_minor')
        ->and(Storage::disk('local')->exists($version->pdf_path))->toBeTrue()
        ->and(hash('sha256', Storage::disk('local')->get($version->pdf_path)))->toBe($version->pdf_sha256)
        ->and(substr((string) Storage::disk('local')->get($version->pdf_path), 0, 5))->toBe('%PDF-');

    $this->assertDatabaseHas('quote_events', ['quote_id' => $quote->id, 'type' => 'sent', 'channel' => 'link', 'quote_version_id' => $version->id]);
    $this->lead->contact->email ? Mail::assertQueued(QuoteSentMail::class) : Mail::assertNothingQueued();

    // Versions are immutable.
    expect(fn () => $version->update(['total_minor' => 1]))->toThrow(LogicException::class);
});

it('blocks sending above the limit until a manager approves exactly those figures', function () {
    Notification::fake();
    $quote = sendableQuote(discountBp: 800);

    expect(fn () => app(SendQuote::class)->handle($quote, $this->sales))->toThrow(QuoteActionDenied::class, 'Approval is needed before sending');

    $approval = app(RequestQuoteApproval::class)->handle($quote, $this->sales, 'Repeat client');
    expect($quote->refresh()->status)->toBe(QuoteStatus::PendingApproval)
        ->and($quote->isEditable())->toBeFalse()
        ->and($approval->payload['discount_bp'])->toBe(800);
    Notification::assertSentTo([$this->owner, $this->manager], QuoteNotification::class, fn ($n) => $n->kind === 'approval_requested');
    Notification::assertNotSentTo($this->sales, QuoteNotification::class);

    // Nobody approves their own request (except the owner), and sales cannot approve at all.
    expect(fn () => app(DecideQuoteApproval::class)->handle($approval, $this->sales, true))->toThrow(QuoteActionDenied::class);

    app(DecideQuoteApproval::class)->handle($approval, $this->manager, true);
    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Approved)
        ->and($quote->approved_by)->toBe($this->manager->id)
        ->and($approval->refresh()->status)->toBe(ApprovalStatus::Approved)
        ->and(app(QuoteApprovalRules::class)->isApprovedAsIs($quote))->toBeTrue();
    Notification::assertSentTo($this->sales, QuoteNotification::class, fn ($n) => $n->kind === 'approved');

    app(SendQuote::class)->handle($quote, $this->sales);
    expect($quote->refresh()->status)->toBe(QuoteStatus::Sent);
});

it('sends a declined request back to draft with the reason, and withdrawing or editing again drops the approval', function () {
    Notification::fake();
    $quote = sendableQuote(discountBp: 800);
    $approval = app(RequestQuoteApproval::class)->handle($quote, $this->sales);

    expect(fn () => app(DecideQuoteApproval::class)->handle($approval, $this->manager, false))->toThrow(QuoteActionDenied::class, 'Please say why');
    app(DecideQuoteApproval::class)->handle($approval, $this->manager, false, 'Keep it at 5%');
    expect($quote->refresh()->status)->toBe(QuoteStatus::Draft);
    Notification::assertSentTo($this->sales, QuoteNotification::class, fn ($n) => $n->kind === 'rejected' && str_contains($n->detail, 'Keep it at 5%'));

    // Approve, then reopen: the approval no longer counts.
    $second = app(RequestQuoteApproval::class)->handle($quote->refresh(), $this->sales);
    app(DecideQuoteApproval::class)->handle($second, $this->owner, true);
    app(ReopenQuote::class)->handle($quote->refresh(), $this->sales);
    expect($quote->refresh()->status)->toBe(QuoteStatus::Draft)
        ->and($quote->approved_at)->toBeNull()
        ->and(fn () => app(SendQuote::class)->handle($quote, $this->sales))->toThrow(QuoteActionDenied::class);

    // Withdrawing a pending request cancels it.
    $third = app(RequestQuoteApproval::class)->handle($quote->refresh(), $this->sales);
    app(ReopenQuote::class)->handle($quote->refresh(), $this->sales);
    expect($third->refresh()->status)->toBe(ApprovalStatus::Cancelled);
});

it('revises a sent quotation into R1, R2 and keeps every sent version frozen', function () {
    $quote = sendableQuote($this->owner);
    $r0 = app(SendQuote::class)->handle($quote, $this->owner);

    expect(fn () => app(SendQuote::class)->handle($quote->refresh(), $this->owner))->toThrow(QuoteActionDenied::class, 'Revise');

    app(ReviseQuote::class)->handle($quote->refresh(), $this->owner);
    expect($quote->refresh()->status)->toBe(QuoteStatus::Draft)
        ->and($quote->revision)->toBe(1)
        ->and($quote->label())->toBe($quote->number.' R1');

    $line = $quote->items()->first();
    app(QuoteEditor::class)->updateLine($line, ['qty' => '12']);
    $r1 = app(SendQuote::class)->handle($quote->refresh(), $this->owner);

    expect($r1->revision)->toBe(1)
        ->and($r1->snapshot['label'])->toBe($quote->number.' R1')
        ->and($r1->total_minor)->toBe(45_000_000)
        ->and($r0->refresh()->total_minor)->toBe(40_000_000)
        ->and(QuoteVersion::query()->where('quote_id', $quote->id)->count())->toBe(2);

    // Changes requested by the client: edit directly, the next send becomes R2.
    $quote->update(['status' => QuoteStatus::ChangesRequested]);
    app(QuoteEditor::class)->updateLine($quote->items()->first(), ['qty' => '11']);
    $r2 = app(SendQuote::class)->handle($quote->refresh(), $this->owner);
    expect($r2->revision)->toBe(2)->and($quote->refresh()->revision)->toBe(2);
});

it('shows staff what changed between revisions', function () {
    $quote = sendableQuote($this->owner);
    app(SendQuote::class)->handle($quote, $this->owner);
    app(ReviseQuote::class)->handle($quote->refresh(), $this->owner);
    app(QuoteEditor::class)->updateLine($quote->items()->first(), ['qty' => '12']);
    app(SendQuote::class)->handle($quote->refresh(), $this->owner);

    Livewire::actingAs($this->owner)->test(QuoteBuilder::class, ['quote' => $quote->refresh()])
        ->assertSee('Sent versions')
        ->assertSee($quote->number.' R1')
        ->assertSee('Total Rs 400,000 → Rs 450,000 (+Rs 50,000)') // Settings: lakh grouping off by default
        ->assertSee('Kitchen cabinets, lacquer finish: qty 10 → 12')
        ->assertSee('Client link');
});

it('runs send, approval and revise from the builder', function () {
    Notification::fake();
    $quote = sendableQuote(discountBp: 800);

    $builder = Livewire::actingAs($this->sales)->test(QuoteBuilder::class, ['quote' => $quote])
        ->call('openSend')
        ->assertSee('Approval needed before sending')
        ->set('approvalNote', 'Repeat client')
        ->call('requestApproval')
        ->assertSee('Waiting for approval');
    expect($quote->refresh()->status)->toBe(QuoteStatus::PendingApproval);

    Livewire::actingAs($this->manager)->test(QuoteBuilder::class, ['quote' => $quote])->call('approve');
    expect($quote->refresh()->status)->toBe(QuoteStatus::Approved);

    Livewire::actingAs($this->sales)->test(QuoteBuilder::class, ['quote' => $quote->refresh()])
        ->call('openSend')
        ->assertSee('Send now')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('Client link');
    expect($quote->refresh()->status)->toBe(QuoteStatus::Sent);

    Livewire::actingAs($this->sales)->test(QuoteBuilder::class, ['quote' => $quote])->call('revise');
    expect($quote->refresh()->revision)->toBe(1)->and($quote->status)->toBe(QuoteStatus::Draft);
});

it('lists pending approvals for managers and lets them decide', function () {
    Notification::fake();
    $quote = sendableQuote(discountBp: 800);
    app(RequestQuoteApproval::class)->handle($quote, $this->sales, 'Repeat client');

    $this->actingAs($this->sales)->get(route('admin.approvals'))->assertForbidden();
    $this->actingAs($this->manager)->get(route('admin.approvals'))->assertOk()->assertSee($quote->label())->assertSee('Repeat client');

    $approval = Approval::query()->firstOrFail();
    Livewire::actingAs($this->manager)->test(Approvals::class)
        ->call('openReject', $approval->id)
        ->set('rejectReason', 'Keep it at 5%')
        ->call('reject')
        ->assertHasNoErrors();
    expect($quote->refresh()->status)->toBe(QuoteStatus::Draft);
});

it('previews a draft PDF and serves the stored PDF of a sent revision', function () {
    $quote = sendableQuote($this->owner);

    $this->actingAs($this->owner)->get(route('admin.quotes.pdf', $quote))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $version = app(SendQuote::class)->handle($quote, $this->owner);
    $response = $this->actingAs($this->owner)->get(route('admin.quotes.pdf', ['quote' => $quote, 'revision' => 0]))->assertOk();
    expect(hash('sha256', $response->streamedContent()))->toBe($version->pdf_sha256);

    $stranger = $this->userWithRole('sales');
    $this->actingAs($stranger)->get(route('admin.quotes.pdf', $quote))->assertForbidden();
});
