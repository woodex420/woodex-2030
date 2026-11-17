<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateFurnitureInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\AcceptanceChannel;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Services\QuoteEditor;
use App\Livewire\Admin\Crm\CompanyShow;
use App\Livewire\Admin\Crm\ContactShow;
use App\Livewire\Admin\Crm\LeadShow;
use App\Livewire\Admin\Projects\ProjectShow;
use App\Livewire\Admin\Sales\InvoiceShow;
use App\Livewire\Admin\Sales\QuoteBuilder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

/*
| Mass assignment (document 10 §4; master list T-160): what a sender adds to a form must not reach the record.
|
| Almost every model here guards only its id, so every other column can be filled from an array. That is safe
| only while each write is given named, checked fields and never the request as it came. Three things are held:
|
|   1. the public enquiry form takes its own fields and nothing else;
|   2. a page that shows one record cannot be handed another record's number by the browser;
|   3. no code writes a record from the whole request.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    config(['modules.enabled' => 'lead_api', 'woodex.business_lines' => ['furniture']]);

    $this->owner = $this->userWithRole('owner');
});

/** A sale as far as the invoice: lead, quotation sent and accepted, project, draft invoice. */
function saleForTheLock(string $client): array
{
    $lead = Lead::factory()->create([
        'owner_id' => test()->owner->id, 'business_line' => 'furniture', 'service' => 'office-furniture',
        'message' => 'Twelve workstations.', 'created_at' => now()->subDay(),
    ]);
    $lead->contact->update(['name' => $client]);
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Furniture, test()->owner, $lead, null, 'Office furniture');
    app(QuoteEditor::class)->updateLine($quote->items()->firstOrFail(), [
        'product_name' => 'Executive workstation', 'description' => 'Laminate top.', 'unit' => 'nos', 'qty' => '12', 'rate_minor' => 4_500_000,
    ]);
    app(SendQuote::class)->handle($quote->refresh(), test()->owner, 'link', false);
    app(AcceptQuote::class)->handle($quote->refresh(), $client, null, null, null, test()->owner, null, AcceptanceChannel::Phone);

    return [
        'lead' => $lead->refresh(),
        'contact' => $lead->contact,
        'quote' => $quote->refresh(),
        'project' => Project::query()->where('quote_id', $quote->id)->sole(),
        'invoice' => app(CreateFurnitureInvoice::class)->handle($quote->refresh(), test()->owner),
    ];
}

it('takes its own fields from the public enquiry form and nothing else', function () {
    $stranger = $this->userWithRole('sales');

    $this->postJson('/api/public/leads', [
        'name' => 'Ayesha Khan', 'phone' => '0322-4000768', 'service' => 'office-furniture', 'city' => 'Lahore',
        'message' => 'Twelve workstations.', 'consent' => true,
        // What a sender might add: columns of the lead and of the contact, under their own names.
        'id' => 9999, 'owner_id' => $stranger->id, 'stage_id' => 9999, 'priority' => 'urgent', 'score' => 100,
        'expected_value_minor' => 99_999_900, 'lost_reason' => 'spam', 'reference' => 'L-0000-000001',
        'created_at' => '2001-01-01 00:00:00', 'updated_at' => '2001-01-01 00:00:00', 'deleted_at' => '2001-01-01 00:00:00',
        'is_client' => true, 'client_type' => 'vip', 'opted_out_at' => '2001-01-01 00:00:00', 'contact_id' => 9999,
        'lead' => ['owner_id' => $stranger->id], 'contact' => ['is_client' => true],
    ])->assertCreated();

    $lead = Lead::query()->with('stage')->sole();
    $contact = Contact::query()->sole();

    expect($lead->id)->not->toBe(9999)
        ->and($lead->reference)->not->toBe('L-0000-000001')
        ->and($lead->stage->key)->toBe('new')
        ->and($lead->expected_value_minor)->toBeNull()
        ->and($lead->lost_reason)->toBeNull()
        ->and($lead->created_at->isToday())->toBeTrue()
        ->and($lead->deleted_at)->toBeNull()
        ->and($lead->contact_id)->toBe($contact->id)
        ->and($contact->is_client)->toBeFalse()
        ->and($contact->opted_out_at)->toBeNull()
        ->and((string) $lead->priority?->value)->not->toBe('urgent');
});

it('cannot be handed another record by the browser', function (string $component, string $parameter, string $property) {
    $shown = saleForTheLock('Ayesha Khan');
    $other = saleForTheLock('Bilal Ahmed');
    $shown['company'] = Company::create(['name' => 'Gulberg Heights', 'type' => 'developer']);
    $other['company'] = Company::create(['name' => 'Studio Arch', 'type' => 'architect']);

    $page = Livewire::actingAs($this->owner)->test($component, [$parameter => $shown[$parameter]])->assertOk();

    expect(fn () => $page->set($property, $other[$parameter]->id))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'a lead' => [LeadShow::class, 'lead', 'leadId'],
    'a contact' => [ContactShow::class, 'contact', 'contactId'],
    'a company' => [CompanyShow::class, 'company', 'companyId'],
    'a quotation' => [QuoteBuilder::class, 'quote', 'quoteId'],
    'an invoice' => [InvoiceShow::class, 'invoice', 'invoiceId'],
    'a project' => [ProjectShow::class, 'project', 'project'],
]);

it('keeps the number of a record out of reach on every page that is opened for one record', function () {
    // A page that takes its record in mount() and keeps the record, or its number, in a public property must lock
    // it: an unlocked one can be set by anybody who can open the page.
    $open = [];

    foreach ((new Finder)->files()->in(app_path('Livewire'))->name('*.php') as $file) {
        $source = $file->getContents();

        if (! preg_match('/public function mount\(([^)]*)\)\s*(?::\s*\w+\s*)?\{(.*?)\n    \}/s', $source, $mount)) {
            continue;
        }

        preg_match_all('/\$this->(\w+)\s*=\s*\$(\w+)(->id|->getKey\(\))?\s*;/', $mount[2], $assignments, PREG_SET_ORDER);

        foreach ($assignments as [, $property, $from]) {
            // Only what came in as the page's own record: a model, or a number, named in mount().
            if (! preg_match('/(?:\??[A-Z]\w*|\??int)\s+\$'.preg_quote($from, '/').'\b/', $mount[1])) {
                continue;
            }
            if (! preg_match('/((?:[ \t]*#\[[^\]]+\]\s*\n)*)[ \t]*public \??([\w\\\\]+) \$'.preg_quote($property, '/').'\b/', $source, $declared)) {
                continue;
            }
            $settings = ['BusinessSettings', 'SalesSettings', 'NotificationSettings', 'SiteSettings', 'IntegrationSettings'];
            if (in_array(class_basename($declared[2]), $settings, true)) {
                continue;
            }
            if (! str_contains($declared[1], 'Locked')) {
                $open[] = $file->getRelativePathname().': $'.$property;
            }
        }
    }

    expect($open)->toBe([]);
});

it('writes no record from the whole request', function () {
    // Each of these would let every column be filled from what a browser sends.
    $forbidden = [
        // (Validator::make($request->all(), …) is the opposite: it is how the fields are checked.)
        '/(?<!Validator::)\b(?:create|update|fill|forceFill|firstOrCreate|updateOrCreate|insert|upsert|make)\(\s*\$request->(?:all|input|post|query|json|except)\(/',
        '/(?<!Validator::)\b(?:create|update|fill|forceFill|firstOrCreate|updateOrCreate|insert|upsert|make)\(\s*request\(\)->(?:all|input|post|query|json|except)\(/',
        '/(?:create|update|fill|forceFill)\(\s*\$this->all\(\)/',
        '/Model::unguard\(|::unguard\(\)/',
    ];
    $found = [];

    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        foreach ($forbidden as $pattern) {
            if (preg_match($pattern, $file->getContents(), $match)) {
                $found[] = $file->getRelativePathname().': '.$match[0];
            }
        }
    }

    expect($found)->toBe([]);
});

it('knows every model that guards nothing', function () {
    // A model with an empty guard takes any column from an array. One exists: the single row that holds the
    // Google connection, written only by the sign-in exchange. A second one must be a decision, not an accident.
    $unguarded = [];

    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        if (preg_match('/protected\s+\$guarded\s*=\s*\[\s*\]\s*;/', $file->getContents())) {
            $unguarded[] = $file->getRelativePathname();
        }
    }

    expect($unguarded)->toBe(['Integrations/Google/GoogleConnection.php']);
});
