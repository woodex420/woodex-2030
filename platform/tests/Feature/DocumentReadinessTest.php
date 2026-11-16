<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\IssueRecurringInvoice;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\RecurringInvoice;
use App\Domain\Sales\Services\DocumentReadiness;
use App\Settings\BusinessSettings;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['documents.test_bypass' => false, 'documents.owner_confirmed' => false]);
    Storage::fake('local');
    Mail::fake();
    $this->owner = $this->userWithRole('owner');
    $this->contact = Contact::factory()->create();
});

function configureSyntheticDocumentIdentity(): void
{
    $business = app(BusinessSettings::class);
    foreach (['legal_name' => 'Synthetic Company', 'brand_name' => 'Synthetic Furniture', 'address' => 'Synthetic address', 'phone' => '+923001234567', 'email' => 'office@example.test'] as $key => $value) {
        $business->{$key} = $value;
    }
    $business->save();
    $sales = app(SalesSettings::class);
    $sales->execution_terms = $sales->design_terms = $sales->invoice_terms = 'Synthetic test terms only';
    $sales->signatories = [['name' => 'Synthetic Signer', 'title' => 'Owner']];
    $sales->save();
}

it('requires both confirmation and complete settings', function () {
    $gate = app(DocumentReadiness::class);
    expect($gate->problems())->not->toBeEmpty();
    config(['documents.owner_confirmed' => true]);
    expect($gate->problems())->not->toBeEmpty();
    configureSyntheticDocumentIdentity();
    expect($gate->problems())->toBe([]);
    config(['documents.owner_confirmed' => false]);
    expect($gate->problems())->toHaveCount(1);
});

it('never honors the test bypass in production', function () {
    config(['documents.test_bypass' => true]);
    $this->app->instance('env', 'production');
    expect(app(DocumentReadiness::class)->problems())->not->toBeEmpty();
});

it('keeps a quotation editable but blocks sending before any PDF version or mail is made', function () {
    $quote = app(CreateQuote::class)->handle($this->contact, BusinessLine::Furniture, $this->owner);
    expect(fn () => app(SendQuote::class)->handle($quote, $this->owner, email: true))->toThrow(ValidationException::class);
    expect($quote->refresh()->status->value)->toBe('draft')
        ->and($quote->versions()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Mail::assertNothingOutgoing();
});

it('rolls a manual invoice issuance back to draft when setup is incomplete', function () {
    $invoice = Invoice::query()->create([
        'number' => 'SYNTHETIC-READINESS', 'contact_id' => $this->contact->id,
        'business_line' => BusinessLine::Furniture, 'status' => InvoiceStatus::Draft,
        'subtotal_minor' => 10000, 'total_minor' => 10000, 'balance_minor' => 10000, 'currency' => 'PKR',
    ]);
    \Livewire\Livewire::actingAs($this->owner)
        ->test(\App\Livewire\Admin\Sales\InvoiceShow::class, ['invoice' => $invoice])
        ->call('issue')->assertHasErrors('issue')->assertSee('Review System');
    expect(fn () => app(IssueInvoice::class)->handle($invoice, $this->owner))->toThrow(ValidationException::class);
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->snapshot)->toBeNull()
        ->and($invoice->issue_date)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('blocks automatic recurring invoices without advancing the schedule or persisting an invoice', function () {
    $schedule = RecurringInvoice::query()->create([
        'contact_id' => $this->contact->id, 'title' => 'Synthetic recurring charge',
        'business_line' => BusinessLine::Furniture, 'frequency' => 'monthly',
        'lines' => [['description' => 'Synthetic service', 'unit' => 'service', 'qty' => 1, 'rate_minor' => 10000, 'discount_minor' => 0]],
        'tax_bp' => 0, 'due_days' => 7, 'starts_on' => today(), 'next_issue_on' => today(), 'is_active' => true,
    ]);
    $before = $schedule->next_issue_on->toDateString();
    expect(fn () => app(IssueRecurringInvoice::class)->handle($schedule, $this->owner))->toThrow(ValidationException::class);
    expect(Invoice::count())->toBe(0)
        ->and($schedule->refresh()->next_issue_on->toDateString())->toBe($before)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('blocks milestone invoice issuance but still permits a draft milestone invoice', function () {
    $quote = app(CreateQuote::class)->handle($this->contact, BusinessLine::Interior, $this->owner);
    $quote->forceFill(['status' => \App\Domain\Sales\Enums\QuoteStatus::Accepted])->save();
    $project = app(\App\Domain\Projects\Actions\OpenProjectFromQuote::class)->handle($quote);
    $milestone = $project->milestones()->firstOrFail();
    $action = app(\App\Domain\Sales\Actions\RaiseMilestoneInvoice::class);
    expect(fn () => $action->handle($quote, $milestone, $this->owner, true))->toThrow(ValidationException::class);
    expect(Invoice::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    expect($action->handle($quote, $milestone, $this->owner, false)->status)->toBe(InvoiceStatus::Draft);
});

it('issues after confirmation and leaves existing frozen documents accessible after confirmation is revoked', function () {
    configureSyntheticDocumentIdentity();
    config(['documents.owner_confirmed' => true]);
    $invoice = Invoice::query()->create([
        'number' => 'SYNTHETIC-CONFIRMED', 'contact_id' => $this->contact->id,
        'business_line' => BusinessLine::Furniture, 'status' => InvoiceStatus::Draft,
        'subtotal_minor' => 10000, 'total_minor' => 10000, 'balance_minor' => 10000, 'currency' => 'PKR',
    ]);
    $issued = app(IssueInvoice::class)->handle($invoice, $this->owner);
    expect($issued->status)->toBe(InvoiceStatus::Issued)->and($issued->snapshot)->not->toBeNull();
    $hash = $issued->pdf_sha256;
    config(['documents.owner_confirmed' => false]);
    expect(app(\App\Domain\Sales\Services\InvoiceFreezer::class)->freeze($issued)->pdf_sha256)->toBe($hash);
});
