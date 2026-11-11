<?php

use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Actions\CheckLeadSla;
use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Events\LeadCaptured;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\System\Import\LeadCsvImporter;
use App\Domain\System\Models\LegacyRef;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Karachi'));
    $this->owner = $this->userWithRole('owner');
    $this->csvPaths = new ArrayObject;
    Event::fake([LeadCaptured::class]);
    Notification::fake();
    Queue::fake();
});

afterEach(function () {
    foreach ($this->csvPaths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

function historicalCsv(array $rows = [[]], string $prefix = "\xEF\xBB\xBF,,,,,,,,\n,,,,,,,,\n"): string
{
    $path = storage_path('framework/testing/historical-'.bin2hex(random_bytes(8)).'.csv');
    test()->csvPaths->append($path);
    $handle = fopen($path, 'w');
    fwrite($handle, $prefix);
    $header = ['Sr', 'Date', 'Name', 'Contact', 'Status', 'Company', 'Location ', 'Quotation ', 'Note'];
    fputcsv($handle, $header, escape: '');
    foreach ($rows as $i => $overrides) {
        $values = array_replace(['Sr' => (string) ($i + 1), 'Date' => '01/01/2025', 'Name' => 'Test client', 'Contact' => '0300 1234567', 'Status' => 'Needs follow-up', 'Company' => 'Test business', 'Location ' => 'Lahore', 'Quotation ' => 'Discussed a budget, not a payment', 'Note' => 'Original historical note'], $overrides);
        fputcsv($handle, array_map(fn ($key) => $values[$key], $header), escape: '');
    }
    fclose($handle);

    return $path;
}

it('defaults to a read-only report without consuming reference numbers', function () {
    $numbers = DB::table('number_sequences')->get()->toJson();
    $report = app(LeadCsvImporter::class)->handle(historicalCsv(), $this->owner);
    expect($report['apply'])->toBeFalse()->and($report['candidates'])->toBe(1)->and($report['imported'])->toBe(0)
        ->and(Contact::count())->toBe(0)->and(Lead::count())->toBe(0)->and(LegacyRef::count())->toBe(0)
        ->and(Activity::count())->toBe(0)->and(DB::table('number_sequences')->get()->toJson())->toBe($numbers);
});

it('imports historical contacts and leads through capture with original dates and safe defaults', function () {
    $report = app(LeadCsvImporter::class)->handle(historicalCsv(), $this->owner, apply: true);
    $lead = Lead::sole();
    $contact = Contact::sole();
    expect($report['imported'])->toBe(1)->and($lead->created_at->timezone('Asia/Karachi')->format('Y-m-d'))->toBe('2025-01-01')
        ->and($contact->created_at->timezone('Asia/Karachi')->format('Y-m-d'))->toBe('2025-01-01')
        ->and($lead->source)->toBe(LeadSource::Import)->and($lead->sla_due_at)->toBeNull()->and($lead->next_action_at)->toBeNull()
        ->and($lead->owner_id)->toBeNull()->and($contact->consent_whatsapp)->toBeFalse()->and($contact->consent_marketing)->toBeFalse()
        ->and($lead->meta['historical_import']['fields']['Status'])->toBe('Needs follow-up')
        ->and($lead->message)->toContain('Company: Test business')->toContain('Original historical note')
        ->and(Activity::sole()->occurred_at->timezone('Asia/Karachi')->format('Y-m-d'))->toBe('2025-01-01');
    Event::assertNotDispatched(LeadCaptured::class);
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
    expect(app(CheckLeadSla::class)->handle())->toBe(0);
});

it('never infers invoices payments or won deals from historical quotation notes', function () {
    app(LeadCsvImporter::class)->handle(historicalCsv([['Status' => 'Won and paid', 'Quotation ' => 'Rs 1,000,000 received']]), $this->owner, apply: true);
    expect(Invoice::count())->toBe(0)->and(Payment::count())->toBe(0)->and(Lead::sole()->won_at)->toBeNull()
        ->and(Lead::sole()->meta['historical_import']['fields']['Quotation'])->toBe('Rs 1,000,000 received');
});

it('skips matching active or archived contacts without updating any existing attributes', function (string $field, bool $archived) {
    $contact = Contact::factory()->create([$field => '+923001234567', 'consent_marketing' => false, 'consent_whatsapp' => false, 'opted_out_at' => now()->subYear()]);
    if ($archived) {
        $contact->delete();
    }
    $before = $contact->fresh()->getAttributes();
    $report = app(LeadCsvImporter::class)->handle(historicalCsv(), $this->owner, apply: true);
    expect($report['existing'])->toBe(1)->and($report['imported'])->toBe(0)
        ->and(Contact::withTrashed()->findOrFail($contact->id)->getAttributes())->toBe($before)
        ->and(Lead::count())->toBe(0)->and(Activity::count())->toBe(0)->and(LegacyRef::count())->toBe(0);
})->with([['phone', false], ['alt_phone', false], ['whatsapp', false], ['phone', true], ['alt_phone', true], ['whatsapp', true]]);

it('imports a repeated phone only once and makes identical batch reruns idempotent', function () {
    $path = historicalCsv([[], ['Name' => 'Second name, same phone']]);
    $first = app(LeadCsvImporter::class)->handle($path, $this->owner, apply: true);
    $second = app(LeadCsvImporter::class)->handle($path, $this->owner, apply: true);
    expect($first['imported'])->toBe(1)->and($first['existing'])->toBe(1)->and($second['imported'])->toBe(0)
        ->and($second['repeated'])->toBe(1)->and(Contact::count())->toBe(1)->and(Lead::count())->toBe(1)->and(LegacyRef::count())->toBe(1);
});

it('holds a changed previously imported source row for review without creating or modifying contacts', function () {
    app(LeadCsvImporter::class)->handle(historicalCsv(), $this->owner, apply: true);
    $before = Contact::sole()->getAttributes();
    $report = app(LeadCsvImporter::class)->handle(historicalCsv([['Name' => 'Changed source', 'Contact' => '0300 7654321']]), $this->owner, apply: true);
    expect($report['review'])->toBe(1)->and($report['rows'][0]['reason'])->toBe('source_row_changed_requires_review')
        ->and(Contact::count())->toBe(1)->and(Contact::sole()->getAttributes())->toBe($before);
});

it('holds untrustworthy rows without guessing', function (array $overrides) {
    $report = app(LeadCsvImporter::class)->handle(historicalCsv([$overrides]), $this->owner, apply: true);
    expect($report['review'])->toBe(1)->and(Contact::count())->toBe(0)->and(Lead::count())->toBe(0);
})->with([
    [['Contact' => '']], [['Contact' => '0300 1234567 / 0300 7654321']], [['Contact' => '=923001234567']],
    [['Date' => '']], [['Date' => '31/02/2025']], [['Date' => '01/01/2024']], [['Date' => '01/01/2027']],
    [['Name' => '']], [['Sr' => '']],
]);

it('rejects malformed sheets before applying otherwise valid rows', function () {
    $path = historicalCsv();
    file_put_contents($path, "\nwrong,column,count\n", FILE_APPEND);
    expect(fn () => app(LeadCsvImporter::class)->handle($path, $this->owner, apply: true))->toThrow(ValidationException::class);
    expect(Contact::count())->toBe(0)->and(Lead::count())->toBe(0);
});

it('rolls back the batch when its idempotency marker cannot be saved', function () {
    Event::listen('eloquent.creating: '.LegacyRef::class, function (): void {
        throw new RuntimeException('Test import failure');
    });
    expect(fn () => app(LeadCsvImporter::class)->handle(historicalCsv(), $this->owner, apply: true))->toThrow(RuntimeException::class, 'Test import failure');
    expect(Contact::count())->toBe(0)->and(Lead::count())->toBe(0)->and(Activity::count())->toBe(0);
});

it('requires a fresh active owner instead of a cached role or active flag', function (bool $disabled) {
    $actor = $this->owner->load('roles');
    if ($disabled) {
        $this->owner->update(['is_active' => false]);
    } else {
        $this->owner->syncRoles(['sales']);
    }
    expect(fn () => app(LeadCsvImporter::class)->handle(historicalCsv(), $actor, apply: true))->toThrow(HttpException::class);
    expect(Contact::count())->toBe(0);
})->with([true, false]);

it('does not expose imported names phones or notes in its reconciliation report', function () {
    $report = app(LeadCsvImporter::class)->handle(historicalCsv(), $this->owner, apply: true);
    expect(json_encode($report))->not->toContain('Test client')->not->toContain('1234567')->not->toContain('Original historical note');
});

it('refuses unsafe historical capture flags at the shared lead entry point', function (LeadSource $source, bool $consent, string $date) {
    $input = new LeadInput(name: 'Test client', phone: '0300 1234567', email: null, source: $source, consentWhatsapp: $consent, historicalAt: CarbonImmutable::parse($date));
    expect(fn () => app(CaptureLead::class)->handle($input))->toThrow(ValidationException::class);
    expect(Contact::count())->toBe(0);
})->with([[LeadSource::Manual, false, '2025-01-01'], [LeadSource::Import, true, '2025-01-01'], [LeadSource::Import, false, '2027-01-01']]);

it('refuses to enrich an existing contact even when historical capture bypasses the CSV precheck', function () {
    $contact = Contact::factory()->create(['phone' => '+923001234567', 'name' => 'Existing client']);
    $before = $contact->fresh()->getAttributes();
    expect(fn () => app(CaptureLead::class)->handle(new LeadInput(name: 'Old name', phone: '0300 1234567', email: null, source: LeadSource::Import, historicalAt: CarbonImmutable::parse('2025-01-01'))))->toThrow(ValidationException::class);
    expect($contact->fresh()->getAttributes())->toBe($before)->and(Lead::count())->toBe(0);
});
