<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\DecideQuoteApproval;
use App\Domain\Sales\Actions\DeleteQuote;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\RecordQuoteView;
use App\Domain\Sales\Actions\ReopenQuote;
use App\Domain\Sales\Actions\RequestQuoteApproval;
use App\Domain\Sales\Actions\RequestQuoteChanges;
use App\Domain\Sales\Actions\ReviseQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Exceptions\QuoteLocked;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteEvent;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\System\Enums\ApprovalStatus;
use App\Domain\System\Models\Approval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(11, 0));
    Storage::fake('local');
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->sales = $this->userWithRole('sales');
    $this->manager = $this->userWithRole('sales_manager');
});

function approvalSafetyDraft(bool $discount = true): Quote
{
    $lead = Lead::factory()->create(['owner_id' => test()->sales->id, 'business_line' => 'interior']);
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, test()->sales, $lead, null, 'Approval safety fixture');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Work');
    $editor->addLine($section, ['description' => 'Test work', 'unit' => 'job', 'qty' => '1', 'rate_minor' => 20_000_000]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    if ($discount) {
        $editor->updateDetails($quote, ['discount_type' => DiscountType::Percent, 'discount_value' => 800]);
    }

    return $quote->refresh();
}

it('blocks editor and recalculation against the persisted noneditable state', function (QuoteStatus $status) {
    $quote = approvalSafetyDraft();
    $stale = $quote->fresh();
    $quote->update(['status' => $status]);
    $total = $quote->total_minor;
    expect(fn () => app(QuoteEditor::class)->updateDetails($stale, ['title' => 'Unreviewed edit']))->toThrow(QuoteLocked::class);
    expect(fn () => app(RecalculateQuote::class)->handle($stale))->toThrow(QuoteLocked::class);
    expect($quote->fresh()->title)->toBe('Approval safety fixture')->and($quote->fresh()->total_minor)->toBe($total);
})->with([QuoteStatus::PendingApproval, QuoteStatus::Approved, QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::Accepted, QuoteStatus::Expired, QuoteStatus::Declined]);

it('does not allow stale editor metadata to erase current quote metadata', function () {
    $quote = approvalSafetyDraft(false);
    $stale = $quote->fresh();
    $quote->update(['meta' => ['retained_marker' => 'keep']]);
    app(QuoteEditor::class)->updateDetails($stale, ['title' => 'Current draft']);
    expect($quote->fresh()->meta['retained_marker'])->toBe('keep');
});

it('refreshes line discounts before validating a stale partial edit', function () {
    $quote = approvalSafetyDraft(false);
    $line = $quote->items()->first();
    DB::table('quote_items')->where('id', $line->id)->update(['discount_type' => 'percent', 'discount_value' => 500]);
    app(QuoteEditor::class)->updateLine($line, ['description' => 'Current description']);
    expect($line->fresh()->discount_type)->toBe(DiscountType::Percent)->and($line->fresh()->discount_value)->toBe(500);
});

it('does not create or replace another pending approval through a stale draft', function () {
    $quote = approvalSafetyDraft();
    $stale = $quote->fresh();
    $first = app(RequestQuoteApproval::class)->handle($quote, $this->sales);
    expect(fn () => app(RequestQuoteApproval::class)->handle($stale, $this->sales))->toThrow(QuoteActionDenied::class);
    expect(Approval::count())->toBe(1)->and($first->fresh()->status)->toBe(ApprovalStatus::Pending);
});

it('cannot decide a stale approval twice with either decision', function (bool $approveAgain) {
    $quote = approvalSafetyDraft();
    $approval = app(RequestQuoteApproval::class)->handle($quote, $this->sales);
    $stale = $approval->fresh();
    app(DecideQuoteApproval::class)->handle($approval, $this->manager, true);
    expect(fn () => app(DecideQuoteApproval::class)->handle($stale, $this->manager, $approveAgain, 'Changed my mind'))->toThrow(QuoteActionDenied::class);
    expect($quote->fresh()->status)->toBe(QuoteStatus::Approved)
        ->and(QuoteEvent::where('quote_id', $quote->id)->where('type', 'approved')->count())->toBe(1);
})->with([true, false]);

it('refuses to approve a proposal whose original content or recipient changed', function (string $change) {
    $quote = approvalSafetyDraft();
    $approval = app(RequestQuoteApproval::class)->handle($quote, $this->sales);
    match ($change) {
        'recipient' => $quote->contact()->update(['email' => 'different@example.test']),
        'name' => $quote->contact()->update(['name' => 'Different client']),
        'title' => $quote->update(['title' => 'Different scope']),
        'terms' => $quote->update(['terms' => 'Different terms']),
        'amount' => $quote->update(['total_minor' => $quote->total_minor + 100]),
        'line' => $quote->items()->update(['description' => 'Changed materials']),
        'milestone' => $quote->milestones()->update(['name' => 'Changed payment terms']),
    };
    expect(fn () => app(DecideQuoteApproval::class)->handle($approval, $this->manager, true))->toThrow(QuoteActionDenied::class, 'changed');
    expect($approval->fresh()->status)->toBe(ApprovalStatus::Pending)->and($quote->fresh()->approved_at)->toBeNull();
})->with(['recipient', 'name', 'title', 'terms', 'amount', 'line', 'milestone']);

it('refuses legacy or missing approval bindings until reviewed again', function () {
    $quote = approvalSafetyDraft();
    $approval = app(RequestQuoteApproval::class)->handle($quote, $this->sales);
    $approval->update(['payload' => ['fingerprint' => hash('sha256', 'legacy-v1')]]);
    expect(fn () => app(DecideQuoteApproval::class)->handle($approval, $this->manager, true))->toThrow(QuoteActionDenied::class, 'request approval again');
});

it('invalidates an approved send after the recipient changes', function () {
    $quote = approvalSafetyDraft();
    $approval = app(RequestQuoteApproval::class)->handle($quote, $this->sales);
    app(DecideQuoteApproval::class)->handle($approval, $this->manager, true);
    $quote->contact()->update(['email' => 'different@example.test']);
    expect(fn () => app(SendQuote::class)->handle($quote->refresh(), $this->sales))->toThrow(QuoteActionDenied::class, 'Approval');
    expect($quote->versions()->count())->toBe(0);
});

it('rechecks fresh approval actor activity and permission', function (bool $disabled) {
    $quote = approvalSafetyDraft();
    $approval = app(RequestQuoteApproval::class)->handle($quote, $this->sales);
    $stale = $this->manager->load('roles');
    if ($disabled) {
        $this->manager->update(['is_active' => false]);
    } else {
        $this->manager->syncRoles([]);
    }
    expect(fn () => app(DecideQuoteApproval::class)->handle($approval, $stale, true))->toThrow($disabled ? HttpException::class : QuoteActionDenied::class);
    expect($approval->fresh()->status)->toBe(ApprovalStatus::Pending);
})->with([true, false]);

it('cannot reopen an already sent approval through a stale approved quote', function () {
    $quote = approvalSafetyDraft();
    $approval = app(RequestQuoteApproval::class)->handle($quote, $this->sales);
    app(DecideQuoteApproval::class)->handle($approval, $this->manager, true);
    $stale = $quote->fresh();
    app(SendQuote::class)->handle($quote->refresh(), $this->sales);
    expect(fn () => app(ReopenQuote::class)->handle($stale, $this->sales))->toThrow(QuoteActionDenied::class);
    expect($quote->fresh()->status)->toBe(QuoteStatus::Sent);
});

it('cannot revise acceptances or request changes through stale sent models', function () {
    $quote = approvalSafetyDraft(false);
    app(SendQuote::class)->handle($quote, $this->sales);
    $stale = $quote->fresh();
    app(AcceptQuote::class)->handle($quote, 'Test client', null, null, null);
    expect(fn () => app(ReviseQuote::class)->handle($stale, $this->sales))->toThrow(QuoteActionDenied::class);
    expect(fn () => app(RequestQuoteChanges::class)->handle($stale, 'Please change it', null, null))->toThrow(QuoteActionDenied::class);
    expect($quote->fresh()->status)->toBe(QuoteStatus::Accepted);
});

it('does not downgrade acceptance or lose view counts when recording stale views', function () {
    $quote = approvalSafetyDraft(false);
    $version = app(SendQuote::class)->handle($quote, $this->sales);
    $stale = $quote->fresh();
    app(AcceptQuote::class)->handle($quote, 'Test client', null, null, null);
    app(RecordQuoteView::class)->handle($stale, $version, null, null);
    app(RecordQuoteView::class)->handle($stale, $version, null, null);
    expect($quote->fresh()->status)->toBe(QuoteStatus::Accepted)->and($quote->fresh()->view_count)->toBe(2);
});

it('cannot delete a sent quote through a stale draft even as owner', function () {
    $quote = approvalSafetyDraft(false);
    $stale = $quote->fresh();
    app(SendQuote::class)->handle($quote, $this->owner);
    expect(fn () => app(DeleteQuote::class)->handle($stale, $this->owner))->toThrow(QuoteLocked::class);
    expect($quote->fresh())->not->toBeNull()->and($quote->versions()->count())->toBe(1);
});

it('allocates the changed revision before requesting its approval and sends that exact revision', function () {
    $quote = approvalSafetyDraft();
    $firstApproval = app(RequestQuoteApproval::class)->handle($quote, $this->sales);
    app(DecideQuoteApproval::class)->handle($firstApproval, $this->manager, true);
    app(SendQuote::class)->handle($quote->refresh(), $this->sales);
    app(RequestQuoteChanges::class)->handle($quote, 'Change the materials', null, null);
    app(QuoteEditor::class)->updateDetails($quote, ['title' => 'Changed scope']);
    $secondApproval = app(RequestQuoteApproval::class)->handle($quote, $this->sales);
    expect($secondApproval->payload['revision'])->toBe(1);
    app(DecideQuoteApproval::class)->handle($secondApproval, $this->manager, true);
    $version = app(SendQuote::class)->handle($quote->refresh(), $this->sales);
    expect($version->revision)->toBe(1)->and($version->snapshot['title'])->toBe('Changed scope');
});

it('rejects change requests from a form displaying an older sent revision', function () {
    $quote = approvalSafetyDraft(false);
    app(SendQuote::class)->handle($quote, $this->sales);
    app(ReviseQuote::class)->handle($quote, $this->sales);
    app(SendQuote::class)->handle($quote, $this->sales);
    $this->post(QuoteLinks::signed('quotes.portal.changes', $quote), ['revision' => 0, 'comment' => 'Please change the scope'])
        ->assertSessionHasErrors('comment', errorBag: 'changes');
    expect($quote->fresh()->status)->toBe(QuoteStatus::Sent);
});
