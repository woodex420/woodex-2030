<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateManualInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Services\DocumentStyles;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteSnapshot;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    $this->lead = Lead::factory()->create(['owner_id' => $this->owner->id]);
    $this->quote = app(CreateQuote::class)->handle($this->lead->contact, BusinessLine::Interior, $this->owner, $this->lead);
    // These tests pin the original v2.1 contract; current Interior styling has its own reference suite.
    $meta = $this->quote->meta;
    unset($meta['document_design']['interior_revision']);
    $this->quote->meta = $meta;
    $this->quote->save();
});

function seedBankAndWallet(): void
{
    BankAccount::query()->create([
        'business_line' => BusinessLine::Interior, 'kind' => 'bank', 'title' => 'WOODEX INTERIOR',
        'bank_name' => 'Bank Alfalah', 'account_number' => '0000-0000-0000', 'iban' => 'PK00ALFA0000000000000000',
        'branch' => 'Link Rd Model Town Br: Lahore', 'branch_code' => '0293', 'is_default' => true, 'is_active' => true,
    ]);
    BankAccount::query()->create([
        'business_line' => BusinessLine::Interior, 'kind' => 'wallet', 'title' => 'WOODEX INTERIOR',
        'bank_name' => 'JazzCash', 'wallet_provider' => 'JazzCash', 'account_number' => '+92 300 0000000',
        'is_default' => false, 'is_active' => true,
    ]);
}

it('slots Summary of cost into a pre-Slice-R design at its catalogue position', function () {
    $stored = collect(['scope', 'boq', 'totals', 'payment', 'terms', 'notes', 'payment_details', 'signatures'])
        ->map(fn (string $key): array => ['key' => $key, 'visible' => $key !== 'scope'])->all();

    $blocks = DocumentStyles::blocks('quote', $stored);

    expect($blocks)->toHaveCount(9)
        ->and($blocks[0])->toBe(['key' => 'scope', 'visible' => false])
        ->and($blocks[1])->toBe(['key' => 'summary', 'visible' => true])
        ->and($blocks[2]['key'])->toBe('boq')
        ->and(DocumentStyles::visibleBlocks('quote', null))
        ->toBe(['scope', 'summary', 'totals', 'boq', 'payment', 'terms', 'notes', 'payment_details', 'signatures']);
});

it('renders the Classic BOQ: summary with area and per-sft, per-section BOQ, words pill, acceptance', function () {
    $editor = app(QuoteEditor::class);
    $first = $this->quote->sections()->firstOrFail();
    $editor->updateSection($first, ['name' => 'Proposed Work']);
    $editor->updateLine($first->items()->firstOrFail(), ['description' => 'Brick work as per drawing', 'unit' => 'sft', 'qty' => 256, 'rate_minor' => 200000]);
    $second = $editor->addLine($first);
    $editor->updateLine($second, ['description' => 'Plaster finish', 'unit' => 'sft', 'qty' => 44, 'rate_minor' => 200000]);
    $demolition = $editor->addSection($this->quote, 'Demolition');
    $third = $editor->addLine($demolition);
    $editor->updateLine($third, ['description' => 'Removal of existing windows', 'unit' => 'lumpsum', 'qty' => 1, 'rate_minor' => 5000000]);
    $free = $editor->addLine($demolition);
    $editor->updateLine($free, ['description' => 'Site clearance', 'unit' => 'sft', 'qty' => 10, 'rate_minor' => 0]);

    $html = view('pdf.quote', ['s' => app(QuoteSnapshot::class)->build($this->quote->refresh()), 'draft' => true])->render();

    expect($html)->toContain('Summary of cost')
        ->and($html)->toContain('>300<')
        ->and($html)->toContain('2,000')
        ->and($html)->toContain('600,000')
        ->and($html)->toContain('Detailed bill of quantities')
        ->and($html)->toContain('1.1')
        ->and($html)->toContain('2.2')
        ->and($html)->toContain('Total · Proposed Work')
        ->and($html)->toContain('Amount in words')
        ->and($html)->toContain('Total (Rs)')
        ->and($html)->toContain('Project details')
        ->and($html)->toContain('Accepted by client')
        ->and($html)->toContain('Thank you for your business')
        ->and($html)->not->toContain('Rs 0')
        ->and(strpos($html, 'Summary of cost'))->toBeLessThan(strpos($html, 'Total (Rs)'))
        ->and(strpos($html, 'Total (Rs)'))->toBeLessThan(strpos($html, 'Detailed bill of quantities'));
});

it('prints bank branch code and the mobile-wallets box on quotations', function () {
    seedBankAndWallet();

    $snapshot = app(QuoteSnapshot::class)->build($this->quote->refresh());
    $html = view('pdf.quote', ['s' => $snapshot, 'draft' => true])->render();

    expect($snapshot['bank_accounts'])->toHaveCount(1)
        ->and($snapshot['bank_accounts'][0]['branch_code'])->toBe('0293')
        ->and($snapshot['wallets'])->toBe([['provider' => 'JazzCash', 'number' => '+92 300 0000000']])
        ->and($html)->toContain('Mobile wallets')
        ->and($html)->toContain('(0293)');
});

it('renders the invoice in the same design with wallets and branch code', function () {
    seedBankAndWallet();
    $contact = Contact::factory()->create();

    $invoice = app(CreateManualInvoice::class)->handle($this->owner, [
        'contact_id' => $contact->id,
        'business_line' => 'interior',
        'issue_date' => '2026-10-20',
        'due_date' => '2026-10-27',
        'discount_minor' => 0,
        'tax_bp' => 0,
        'items' => [['description' => 'Wardrobe door repair', 'unit' => 'job', 'qty' => '1', 'rate_minor' => 1500000]],
        'issue' => true,
    ]);

    $html = view('pdf.invoice', ['s' => $invoice->snapshot])->render();

    expect($invoice->snapshot['wallets'])->toHaveCount(1)
        ->and($html)->toContain('INVOICE')
        ->and($html)->toContain('Prepared for')
        ->and($html)->toContain('Invoice details')
        ->and($html)->toContain('Balance due')
        ->and($html)->toContain('Payment details')
        ->and($html)->toContain('Mobile wallets')
        ->and($html)->toContain('(0293)')
        ->and($html)->toContain('Thank you for your business');
});
