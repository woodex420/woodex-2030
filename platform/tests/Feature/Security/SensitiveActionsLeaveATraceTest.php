<?php

declare(strict_types=1);

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CollectPayment;
use App\Domain\Sales\Actions\CreateFurnitureInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\DecideQuoteApproval;
use App\Domain\Sales\Actions\DeclineQuote;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\RejectPayment;
use App\Domain\Sales\Actions\RequestQuoteApproval;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Actions\SubmitPaymentProof;
use App\Domain\Sales\Actions\VerifyPayment;
use App\Domain\Sales\Actions\VoidInvoice;
use App\Domain\Sales\Enums\AcceptanceChannel;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\System\Actions\ManageStaff;
use App\Livewire\Admin\System\SettingsPage;
use App\Models\User;
use App\Settings\BusinessSettings;
use App\Settings\IntegrationSettings;
use App\Settings\SalesSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Every sensitive action leaves a row in the list of changes (document 08 §7; master list T-067).
|
| "Audit events for approvals, publishing, financial posting" — and, by the list, payments and role changes. The
| list of changes is the table behind System → Audit log. A row there says who did it, to what, and what changed.
|
| Each action below is done by the person who may do it, and must leave such a row, caused by that person. The
| list is the test: an action that goes silent fails it, and a new sensitive action is added here.
|
| When this was written, six of them were silent: an invitation, a change of role, a deactivation and a
| reactivation, and every change of a setting — who may give which discount, what a document says, who signs it.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    config(['modules.enabled' => 'lead_api', 'woodex.business_lines' => ['furniture']]);

    $this->owner = $this->userWithRole('owner');
    $this->manager = $this->userWithRole('sales_manager');
    $this->sales = $this->userWithRole('sales');
    $this->accounts = $this->userWithRole('accountant');
});

/** A draft quotation of twelve workstations, with 8 % off when asked: more than a sales person may give. */
function tracedDraft(User $by, bool $discount = false): Quote
{
    $lead = Lead::factory()->create([
        'owner_id' => $by->id, 'business_line' => 'furniture', 'service' => 'office-furniture',
        'message' => 'Twelve workstations.', 'created_at' => now()->subDay(),
    ]);
    $lead->contact->update(['name' => 'Ayesha Khan', 'phone' => '0322 4000768', 'whatsapp' => '0322 4000768', 'email' => null]);
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Furniture, $by, $lead, null, 'Office furniture');
    $editor = app(QuoteEditor::class);
    $editor->updateLine($quote->items()->firstOrFail(), [
        'product_name' => 'Executive workstation', 'description' => 'Laminate top.', 'unit' => 'nos', 'qty' => '12', 'rate_minor' => 4_500_000,
    ]);
    if ($discount) {
        $editor->updateDetails($quote->refresh(), ['discount_type' => DiscountType::Percent, 'discount_value' => 800]);
    }

    return $quote->refresh();
}

function tracedSent(): Quote
{
    $quote = tracedDraft(test()->owner);
    test()->actingAs(test()->owner);
    app(SendQuote::class)->handle($quote, test()->owner, 'link', false);

    return $quote->refresh();
}

function tracedInvoice(bool $issued = true): Invoice
{
    $quote = tracedSent();
    app(AcceptQuote::class)->handle($quote, 'Ayesha Khan', null, null, null, test()->owner, null, AcceptanceChannel::Phone);
    test()->actingAs(test()->accounts);
    $invoice = app(CreateFurnitureInvoice::class)->handle($quote->refresh(), test()->accounts);

    return $issued ? app(IssueInvoice::class)->handle($invoice, test()->accounts) : $invoice;
}

/** The rows a person caused in the list of changes, as one text each, since the mark was taken. */
function tracesOf(User $person, int $since): array
{
    return DB::table('activity_log')->where('id', '>', $since)->where('causer_id', $person->id)->orderBy('id')->get()
        ->map(fn (object $row): string => implode(' | ', [$row->log_name, $row->subject_type ?? '-', $row->event ?? '-', $row->description, (string) ($row->attribute_changes ?? ''), (string) $row->properties]))
        ->all();
}

it('leaves a row in the list of changes', function (Closure $prepare) {
    // Each entry prepares what it needs and hands back: who acts, the action, and words the row must carry.
    [$person, $action, $words] = $prepare->call($this);

    $this->actingAs($person);
    $mark = (int) DB::table('activity_log')->max('id');

    $action();

    $rows = tracesOf($person, $mark);
    $carrying = array_filter($rows, fn (string $row): bool => collect($words)->every(fn (string $word): bool => str_contains(strtolower($row), strtolower($word))));

    expect($rows)->not->toBeEmpty('the action left no row caused by the person who did it')
        ->and($carrying)->not->toBeEmpty('no row says what was done: looked for ['.implode(', ', $words)."] in:\n".implode("\n", $rows));
})->with([
    // ── approvals ──
    'approval: a discount above the limit is sent up' => [function (): array {
        $quote = tracedDraft($this->sales, discount: true);

        return [$this->sales, fn () => app(RequestQuoteApproval::class)->handle($quote, $this->sales, 'Large order.'), ['quote', 'pending_approval']];
    }],
    'approval: it is given' => [function (): array {
        $approval = app(RequestQuoteApproval::class)->handle(tracedDraft($this->sales, discount: true), $this->sales);

        return [$this->manager, fn () => app(DecideQuoteApproval::class)->handle($approval, $this->manager, true), ['quote', 'approved']];
    }],
    'approval: it is refused' => [function (): array {
        $approval = app(RequestQuoteApproval::class)->handle(tracedDraft($this->sales, discount: true), $this->sales);

        return [$this->manager, fn () => app(DecideQuoteApproval::class)->handle($approval, $this->manager, false, 'Too deep.'), ['quote', 'draft']];
    }],
    // ── the quotation becomes a commitment ──
    'quotation: it is sent' => [function (): array {
        $quote = tracedDraft($this->owner);

        return [$this->owner, fn () => app(SendQuote::class)->handle($quote, $this->owner, 'link', false), ['quote', 'sent']];
    }],
    'quotation: the client\'s yes is written down' => [function (): array {
        $quote = tracedSent();

        return [$this->owner, fn () => app(AcceptQuote::class)->handle($quote, 'Ayesha Khan', null, null, null, $this->owner, null, AcceptanceChannel::Phone), ['quote', 'accepted']];
    }],
    'quotation: the client\'s no is written down' => [function (): array {
        $quote = tracedSent();

        return [$this->owner, fn () => app(DeclineQuote::class)->handle($quote, $this->owner, 'Chose another supplier.'), ['quote', 'declined']];
    }],
    // ── financial posting ──
    'posting: an invoice is issued' => [function (): array {
        $draft = tracedInvoice(issued: false);

        return [$this->accounts, fn () => app(IssueInvoice::class)->handle($draft, $this->accounts), ['invoice', 'issued']];
    }],
    'posting: an invoice is voided' => [function (): array {
        $invoice = tracedInvoice();

        return [$this->owner, fn () => app(VoidInvoice::class)->handle($invoice, $this->owner, 'Raised for the wrong client.'), ['invoice', 'void']];
    }],
    // ── payments ──
    'payment: money is taken on an invoice' => [function (): array {
        $invoice = tracedInvoice();

        return [$this->accounts, fn () => app(CollectPayment::class)->handle($invoice, 37_260_000, PaymentMethod::BankTransfer, $this->accounts, 'TRX-1'), ['payment', 'verified']];
    }],
    'payment: a payment the client reported is confirmed' => [function (): array {
        $claim = app(SubmitPaymentProof::class)->handle(tracedInvoice(), [
            'amount_minor' => 10_000_000, 'received_on' => today()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'TRX-CLIENT-1',
        ], UploadedFile::fake()->image('slip.png'));

        return [$this->accounts, fn () => app(VerifyPayment::class)->handle($claim, $this->accounts), ['payment', 'verified']];
    }],
    'payment: a payment the client reported is sent back' => [function (): array {
        $claim = app(SubmitPaymentProof::class)->handle(tracedInvoice(), [
            'amount_minor' => 10_000_000, 'received_on' => today()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'TRX-CLIENT-2',
        ], UploadedFile::fake()->image('slip.png'));

        return [$this->accounts, fn () => app(RejectPayment::class)->handle($claim, 'No such transfer in the bank statement.', $this->accounts), ['payment', 'rejected']];
    }],
    // ── who may do what ──
    'people: somebody is invited' => [fn (): array => [
        $this->owner, fn () => app(ManageStaff::class)->invite('Bilal Ahmed', 'bilal@woodex.test', 'sales'), ['invited', 'bilal ahmed', 'sales'],
    ]],
    'people: a role is changed' => [fn (): array => [
        $this->owner, fn () => app(ManageStaff::class)->update($this->sales, $this->owner, $this->sales->name, 'sales_manager'), ['changed the role', 'sales_manager'],
    ]],
    'people: somebody is deactivated' => [fn (): array => [
        $this->owner, fn () => app(ManageStaff::class)->setActive($this->sales, $this->owner, false), ['deactivated', $this->sales->name],
    ]],
    'people: somebody is let in again' => [function (): array {
        $this->sales->forceFill(['is_active' => false])->save();

        return [$this->owner, fn () => app(ManageStaff::class)->setActive($this->sales, $this->owner, true), ['reactivated', $this->sales->name]];
    }],
    // ── the rules themselves ──
    'settings: a discount limit is raised' => [fn (): array => [
        $this->owner,
        fn () => Livewire::test(SettingsPage::class)->set('tab', 'sales')->set('sales.discount_limits.sales', '25')->call('saveSales')->assertHasNoErrors(),
        ['settings', 'discount_limits_bp', '2500'],
    ]],
    'settings: what the documents say is changed' => [fn (): array => [
        $this->owner,
        fn () => Livewire::test(SettingsPage::class)->set('tab', 'sales')->set('sales.invoice_terms', 'Payment within seven days of the invoice.')->call('saveSales')->assertHasNoErrors(),
        ['settings', 'invoice_terms', 'seven days'],
    ]],
    'settings: the business details are changed' => [function (): array {
        $business = app(BusinessSettings::class);
        $business->brand_name;

        return [$this->owner, function () use ($business): void {
            $business->brand_name = 'Woodex Furniture (Pvt) Ltd';
            $business->save();
        }, ['settings', 'brand_name', 'pvt']];
    }],
    // ── publishing ──
    'publishing: a product is taken off the shop' => [function (): array {
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin);
        $service = app(ProductCatalogue::class);
        $category = Category::query()->where('slug', 'chairs')->sole();
        $product = $service->save(null, array_replace($service->defaults(), [
            'name' => 'Traced chair', 'slug' => 'traced-chair', 'price' => '1200.00', 'category_ids' => [$category->id],
            'primary_category_id' => $category->id, 'description' => 'A chair.', 'reviewed' => true,
        ]), 0, $admin);

        return [$admin, fn () => $service->withdraw($product, $admin, true), ['commerce', 'product']];
    }],
]);

it('never writes a secret setting into the list of changes: only that it changed', function () {
    $this->actingAs($this->owner);
    $settings = app(IntegrationSettings::class);
    $secret = 'whatsapp_token';

    $settings->{$secret}; // read once, as a screen does before it saves
    $settings->{$secret} = 'EAAG-a-secret-value-0123456789';
    $settings->whatsapp_api_version = 'v24.0';
    $settings->save();

    $row = DB::table('activity_log')->where('log_name', 'settings')->orderByDesc('id')->first();

    expect($row)->not->toBeNull()
        ->and((string) $row->description)->toContain($secret)
        ->and((string) $row->properties)->not->toContain('EAAG-a-secret-value')
        ->and(json_decode((string) $row->properties, true)['changed'][$secret])->toBe(['secret' => true])
        // A value that is no secret is written with what it was and what it became.
        ->and(json_decode((string) $row->properties, true)['changed']['whatsapp_api_version'])->toBe(['old' => 'v23.0', 'new' => 'v24.0']);
});

it('writes nothing, and changes nothing, when a settings screen is saved as it was opened', function () {
    // The first run of this test found a fault that had nothing to do with the list of changes: saving the sales
    // rules switched the follow-up of quotations off, every time, because the box had no rule (defect D-030).
    $this->actingAs($this->owner);
    $before = DB::table('activity_log')->where('log_name', 'settings')->count();
    $stored = DB::table('settings')->orderBy('id')->get(['group', 'name', 'payload'])->map(fn (object $row): string => "{$row->group}.{$row->name}={$row->payload}")->all();

    Livewire::test(SettingsPage::class)->set('tab', 'sales')->call('saveSales')->assertHasNoErrors();

    expect(DB::table('activity_log')->where('log_name', 'settings')->count())->toBe($before)
        ->and(DB::table('settings')->orderBy('id')->get(['group', 'name', 'payload'])->map(fn (object $row): string => "{$row->group}.{$row->name}={$row->payload}")->all())->toBe($stored);
});

it('saves the follow-up of quotations as the box says', function () {
    $this->actingAs($this->owner);
    $sales = fn (): SalesSettings => tap(app(SalesSettings::class))->refresh();

    expect($sales()->quote_follow_ups_enabled)->toBeTrue();

    Livewire::test(SettingsPage::class)->set('tab', 'sales')->set('sales.quote_follow_ups_enabled', false)->call('saveSales')->assertHasNoErrors();
    expect($sales()->quote_follow_ups_enabled)->toBeFalse();

    Livewire::test(SettingsPage::class)->set('tab', 'sales')->set('sales.quote_follow_ups_enabled', true)->call('saveSales')->assertHasNoErrors();
    expect($sales()->quote_follow_ups_enabled)->toBeTrue();
});

it('records in every publishing step of the catalogue who did it', function () {
    // The steps that put content on the shop, or take it off, each write to the list of changes. Their behaviour
    // is tested with the catalogue; this keeps the writing itself from being dropped.
    foreach ([
        'Domain/Commerce/Services/ProductCatalogue.php' => ['publish', 'withdraw'],
        'Domain/Commerce/Services/CategoryContent.php' => ['publish'],
        'Domain/Commerce/Services/SeriesContent.php' => ['publish'],
        'Domain/Commerce/Services/ShopContent.php' => ['publish'],
    ] as $file => $steps) {
        $source = file_get_contents(app_path($file));

        foreach ($steps as $step) {
            expect(preg_match('/public function '.$step.'\(.*?\n    \}/s', $source, $body))->toBe(1, "{$file} has no {$step}()");
            expect(str_contains($body[0], "activity('commerce')") && str_contains($body[0], 'causedBy('))
                ->toBeTrue("{$file}: {$step}() no longer writes to the list of changes, or no longer says who did it");
        }
    }
});
