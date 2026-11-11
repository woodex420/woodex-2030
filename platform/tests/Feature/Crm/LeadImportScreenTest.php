<?php

declare(strict_types=1);

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Livewire\Admin\Crm\LeadImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Slice V: the historical lead-sheet import gets a screen — upload, match the sheet's own
| headings to the five the importer reads, dry-run a privacy-safe report, then apply.
| Owner-only, exactly like the importer itself.
*/

function leadImportSheetCsv(array $headers, array $rows): string
{
    $lines = [implode(',', $headers)];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(static fn ($value): string => '"'.str_replace('"', '""', (string) $value).'"', $row));
    }

    return implode("\n", $lines)."\n";
}

it('serves the import screen at its own route, ahead of the lead page, to the owner only', function () {
    $this->actingAs($this->userWithRole('owner'));
    $this->get(route('admin.leads.import'))->assertOk()->assertSee('Import a lead sheet');

    $this->actingAs($this->userWithRole('sales_manager'));
    $this->get(route('admin.leads.import'))->assertForbidden();

    Livewire::test(LeadImport::class)->assertForbidden();
});

it('auto-matches alias headings, dry-runs, then imports only the new people', function () {
    $this->actingAs($this->userWithRole('owner'));
    Contact::factory()->create(['name' => 'Existing Client', 'phone' => '+923001112233']);

    $csv = leadImportSheetCsv(
        ['S.No', 'Lead Date', 'Customer', 'Mobile', 'Stage', 'Area'],
        [
            [1, '05/01/2025', 'Nadia Tariq', '0321 5550101', 'New', 'DHA Phase 6'],
            [2, '07/01/2025', 'Omar Farooq', '0321 5550102', 'Contacted', 'Gulberg'],
            [3, '09/01/2025', 'Existing Client', '0300 1112233', 'New', 'Model Town'],
        ],
    );

    $component = Livewire::test(LeadImport::class)
        ->set('file', UploadedFile::fake()->createWithContent('jan-leads.csv', $csv))
        ->assertHasNoErrors()
        ->assertSet('year', 2025)
        ->assertSet('batch', 'jan-2025-leads')
        ->assertSet('rowCount', 3);

    expect($component->get('map'))->toMatchArray([
        'Sr' => 'S.No',
        'Date' => 'Lead Date',
        'Name' => 'Customer',
        'Contact' => 'Mobile',
        'Status' => 'Stage',
    ]);

    $component->call('dryRun')
        ->assertHasNoErrors()
        ->assertSet('applied', false)
        ->assertDontSee('Nadia Tariq')
        ->assertDontSee('5550101');

    expect($component->get('report'))->toMatchArray([
        'source_rows' => 3,
        'candidates' => 2,
        'existing' => 1,
        'repeated' => 0,
        'review' => 0,
    ])->and(Lead::query()->count())->toBe(0);

    $component->call('apply')
        ->assertHasNoErrors()
        ->assertSet('applied', true);

    expect($component->get('report')['imported'])->toBe(2);

    $leads = Lead::query()->with('contact')->orderBy('id')->get();

    expect($leads)->toHaveCount(2)
        ->and($leads->every(fn ($lead) => $lead->source->value === 'import'))->toBeTrue()
        ->and($leads[0]->contact->name)->toBe('Nadia Tariq')
        ->and($leads[0]->message)->toContain('Status: New')
        ->and($leads[0]->message)->toContain('Area: DHA Phase 6')
        ->and($leads[0]->created_at->format('Y-m'))->toBe('2025-01');
});

it('re-running the same sheet and batch imports nothing twice', function () {
    $this->actingAs($this->userWithRole('owner'));

    $csv = leadImportSheetCsv(
        ['Sr', 'Date', 'Name', 'Contact', 'Status'],
        [
            [1, '05/01/2025', 'Nadia Tariq', '0321 5550101', 'New'],
            [2, '07/01/2025', 'Omar Farooq', '0321 5550102', 'New'],
        ],
    );

    Livewire::test(LeadImport::class)
        ->set('file', UploadedFile::fake()->createWithContent('jan-leads.csv', $csv))
        ->call('dryRun')
        ->call('apply')
        ->assertSet('applied', true);

    expect(Lead::query()->count())->toBe(2);

    $again = Livewire::test(LeadImport::class)
        ->set('file', UploadedFile::fake()->createWithContent('jan-leads.csv', $csv))
        ->call('dryRun')
        ->assertHasNoErrors();

    expect($again->get('report'))->toMatchArray(['candidates' => 0, 'repeated' => 2]);
});

it('honours a manual remap instead of guessing — swapped columns land in review', function () {
    $this->actingAs($this->userWithRole('owner'));

    $csv = leadImportSheetCsv(
        ['Sr', 'Date', 'Name', 'Contact', 'Status'],
        [
            [1, '05/01/2025', 'Nadia Tariq', '0321 5550101', 'New'],
        ],
    );

    $component = Livewire::test(LeadImport::class)
        ->set('file', UploadedFile::fake()->createWithContent('jan-leads.csv', $csv))
        ->set('map.Name', 'Contact')
        ->set('map.Contact', 'Name')
        ->call('dryRun')
        ->assertHasNoErrors();

    expect($component->get('report'))->toMatchArray(['candidates' => 0, 'review' => 1])
        ->and(Lead::query()->count())->toBe(0);
});

it('refuses to match one sheet column twice', function () {
    $this->actingAs($this->userWithRole('owner'));

    $csv = leadImportSheetCsv(
        ['Sr', 'Date', 'Name', 'Contact', 'Status'],
        [
            [1, '05/01/2025', 'Nadia Tariq', '0321 5550101', 'New'],
        ],
    );

    Livewire::test(LeadImport::class)
        ->set('file', UploadedFile::fake()->createWithContent('jan-leads.csv', $csv))
        ->set('map.Name', 'Contact')
        ->call('dryRun')
        ->assertHasErrors('map')
        ->assertSet('report', null);
});

it('preserves duplicate and blank heading positions and requires explicit mapping review', function () {
    $this->actingAs($this->userWithRole('owner'));
    $csv = leadImportSheetCsv(
        ['Sr', 'Date', 'Name', 'Name', 'Contact', '', 'Name [column 3]'],
        [[1, '05/01/2025', 'Unselected source name', 'Synthetic Chosen Person', '0321 5550199', 'Blank-heading value', 'Literal collision value']],
    );
    $screen = Livewire::test(LeadImport::class)
        ->set('file', UploadedFile::fake()->createWithContent('review.csv', $csv))->assertHasNoErrors()
        ->assertSet('map.Name', '')
        ->set('map.Name', 'Name [column 4]')
        ->set('headingsForReview', [])
        ->call('dryRun')->assertHasErrors('import')->assertSet('report', null);
    expect($screen->get('columns'))->toHaveCount(7)
        ->and(array_unique($screen->get('columns')))->toHaveCount(7)
        ->and(Lead::query()->count())->toBe(0);
    $screen->set('headingsReviewed', true)->call('dryRun')->assertHasNoErrors()
        ->assertDontSee('Synthetic Chosen Person')->call('apply')->assertHasNoErrors()->assertSet('applied', true);
    $lead = Lead::query()->with('contact')->sole();
    expect($lead->contact->name)->toBe('Synthetic Chosen Person')
        ->and($lead->message)->toContain('Unselected source name', 'Blank-heading value', 'Literal collision value')
        ->and($lead->created_at->format('Y-m-d'))->toBe('2025-01-05');
});

it('rejects a mapped heading that is absent from the stored source', function () {
    $this->actingAs($this->userWithRole('owner'));
    $csv = leadImportSheetCsv(['Sr', 'Date', 'Name', 'Contact'], [[1, '05/01/2025', 'Synthetic Person', '0321 5550198']]);
    Livewire::test(LeadImport::class)->set('file', UploadedFile::fake()->createWithContent('review.csv', $csv))
        ->set('map.Name', 'Not in source')->call('dryRun')->assertHasErrors('import')->assertSet('report', null);
    $this->assertDatabaseCount('leads', 0);
});

it('reviews source years without creating application records and invalidates stale counts', function () {
    $this->actingAs($this->userWithRole('owner'));
    $csv = leadImportSheetCsv(['Sr', 'Date', 'Name', 'Contact', 'Status'], [
        [1, '05/01/2025', 'Synthetic review one', '0321 5550181', 'New'],
        [2, '07/03/2026', 'Synthetic review two', '0321 5550182', 'New'],
        [3, '', 'Synthetic undated', '0321 5550183', 'New'],
    ]);
    $screen = Livewire::test(LeadImport::class)->set('file', UploadedFile::fake()->createWithContent('years.csv', $csv))
        ->call('reviewSourceDates')->assertHasNoErrors()->assertSet('report', null)
        ->assertSet('sourceDateReview.years', [2025 => 1, 2026 => 1])->assertSet('sourceDateReview.missing', 1)
        ->assertSet('sourceDateReview.selected', 1)->assertDontSee('Synthetic review one');
    $this->assertDatabaseCount('leads', 0);
    $this->assertDatabaseCount('contacts', 0);
    $this->assertDatabaseCount('legacy_refs', 0);
    $screen->set('year', 2026)->assertSet('sourceDateReview', null)
        ->call('reviewSourceDates')->assertSet('sourceDateReview.selected', 1)
        ->set('map.Date', '')->assertSet('sourceDateReview', null)
        ->call('reviewSourceDates')->assertSet('sourceDateReview.unmapped', 3);
});

it('reports source CSV record positions across blank records and removes only its own canonical file', function () {
    $this->actingAs($this->userWithRole('owner'));
    $csv = "\nSr,Date,Name,Contact,Status\n\n1,05/01/2025,Synthetic first,03215550184,New\n\n2,07/03/2026,Synthetic other year,03215550185,New\n";
    $disk = Storage::disk('local');
    $sentinel = 'lead-imports/canonical-another-review.csv';
    $disk->put($sentinel, 'Synthetic unrelated review');
    try {
        $screen = Livewire::test(LeadImport::class)->set('file', UploadedFile::fake()->createWithContent('blank-records.csv', $csv))
            ->call('dryRun')->assertHasNoErrors();
        $groups = collect($screen->get('report')['groups'])->keyBy('outcome');
        expect($groups['candidates']['rows'])->toBe([4])->and($groups['review']['rows'])->toBe([6]);
        expect($disk->files('lead-imports'))->toContain($sentinel);
        $screen->call('startOver');
        expect($disk->exists($sentinel))->toBeTrue();
        $this->assertDatabaseCount('leads', 0);
    } finally {
        $disk->delete($sentinel);
    }
});
