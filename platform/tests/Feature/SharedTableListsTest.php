<?php

use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\Vendor;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Models\Quote;
use App\Livewire\Admin\Accounts\PayablesAging;
use App\Livewire\Admin\Crm\LeadsIndex;
use App\Livewire\Admin\Projects\ProjectsIndex;
use App\Livewire\Admin\Reports\Receivables;
use App\Livewire\Admin\Sales\QuotesIndex;
use App\Livewire\Admin\System\UsersIndex;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Livewire\Livewire;

/*
| The first six lists on the shared table (master list T-041): Leads, Quotations, Projects, Payables by age, Users
| and the Receivables report. Each list's own tests were left as they were and still pass. These add what moving
| could have broken and no list's test asked: that the table has a name, that a heading sorts by a word the list
| knows and the rows come in that order, that the pages and the chosen rows are the ones they were.
|
| The component itself is drawn state by state in tests/Unit/AdminTableTest.php.
*/

function drawnScreen(string $html): HTMLDocument
{
    return HTMLDocument::createFromString('<!DOCTYPE html><body>'.$html.'</body>', LIBXML_NOERROR);
}

function saidBy(?Node $node): string
{
    return trim((string) preg_replace('/\s+/', ' ', $node?->textContent ?? ''));
}

/** The heading of the table that carries these words, as a screen reader meets it. */
function headingNamed(HTMLDocument $page, string $words): ?Element
{
    foreach ($page->querySelectorAll('thead th') as $heading) {
        if (saidBy($heading->querySelector('button > span:first-child') ?? $heading) === $words) {
            return $heading;
        }
    }

    return null;
}

it('draws each of the six lists as one named table, with headings that name their columns', function (string $screen, string $role, array $names, ?string $empty, ?Closure $before) {
    $before?->call($this);

    $page = drawnScreen(Livewire::actingAs($this->userWithRole($role))->test($screen)->html());
    $tables = $page->querySelectorAll('table');

    expect($tables->length)->toBe(count($names));

    foreach ($tables as $index => $table) {
        expect(saidBy($table->querySelector('caption')))->toBe($names[$index])
            ->and($table->querySelectorAll('thead th')->length)->toBeGreaterThan(2)
            ->and($table->querySelectorAll('thead th:not([scope="col"])')->length)->toBe(0)
            // Every heading has words, shown or for a screen reader only.
            ->and(collect([...$table->querySelectorAll('thead th')])->filter(fn ($heading) => saidBy($heading) === '' && $heading->querySelector('input[aria-label]') === null)->count())->toBe(0);
    }

    $state = $page->querySelector('[data-table-state]')->getAttribute('data-table-state');

    if ($empty === null) {
        // Rows: each is named by the cell that says what it is.
        expect($state)->toBe('success')
            ->and($page->querySelectorAll('tbody tr')->length)->toBeGreaterThan(0)
            ->and($page->querySelectorAll('tbody tr > th[scope="row"]')->length)->toBe($page->querySelectorAll('tbody tr')->length);
    } else {
        expect($state)->toBe('empty')
            ->and(saidBy($page->querySelector('tbody [role="status"]')))->toContain($empty)
            ->and((int) $page->querySelector('tbody td')->getAttribute('colspan'))->toBe($page->querySelectorAll('thead th')->length);
    }
})->with([
    'Leads, with none yet' => [LeadsIndex::class, 'sales_manager', ['Leads'], 'No leads yet', null],
    'Leads' => [LeadsIndex::class, 'sales_manager', ['Leads'], null, fn () => Lead::factory()->count(3)->create()],
    'Quotations, with none yet' => [QuotesIndex::class, 'owner', ['Quotations'], 'No quotations yet', null],
    'Projects, with none yet' => [ProjectsIndex::class, 'owner', ['Projects'], 'No projects yet', null],
    'Users' => [UsersIndex::class, 'owner', ['Users'], null, null],
    'Receivables, with nothing owed' => [Receivables::class, 'owner', ['Receivables by client'], 'Nothing outstanding', null],
    'Payables by age: a table for each age that has a bill' => [PayablesAging::class, 'owner', ['Received invoices: not due yet', 'Received invoices: 31–60 days late'], null, function () {
        $vendor = Vendor::query()->create(['name' => 'Chughtai Timber', 'phone' => '0300 1234567', 'payment_terms_days' => 15, 'is_active' => true]);

        foreach (['VB-7001' => today()->addDays(10), 'VB-7002' => today()->subDays(40)] as $number => $due) {
            Bill::query()->create([
                'number' => $number, 'vendor_id' => $vendor->id, 'bill_date' => $due->copy()->subDays(15)->toDateString(), 'due_date' => $due->toDateString(),
                'status' => BillStatus::Received, 'subtotal_minor' => 5_000_000, 'tax_minor' => 0, 'total_minor' => 5_000_000, 'created_by' => $this->userWithRole('owner')->id,
            ]);
        }
    }],
]);

it('sorts the leads by a heading exactly as by the list\'s own choice, and the heading says which way', function () {
    $manager = $this->userWithRole('sales_manager');

    foreach ([['Zainab Qureshi', 40, 3, 5], ['Ahmed Raza', 90, 2, 1], ['Mariam Iqbal', 65, 1, 3]] as [$name, $score, $daysAgo, $dueInDays]) {
        Lead::factory()->for(Contact::factory()->create(['name' => $name]))->create([
            'score' => $score, 'created_at' => now()->subDays($daysAgo), 'next_action_at' => now()->addDays($dueInDays),
        ]);
    }

    $screen = Livewire::actingAs($manager)->test(LeadsIndex::class)->call('toggleColumn', 'score');
    $names = fn () => collect($screen->viewData('leads')->items())->map(fn (Lead $lead) => $lead->contact->name)->all();
    $drawn = fn () => collect([...drawnScreen($screen->html())->querySelectorAll('tbody tr')])->map(fn ($row) => saidBy($row->querySelector('td:nth-of-type(2) button')))->all();

    // Every word a heading sends is a word the list knows; the choice above the table still offers them all.
    $page = drawnScreen($screen->html());
    $sent = collect([...$page->querySelectorAll('thead th button[data-sort]')])->mapWithKeys(fn ($button) => [saidBy($button->querySelector('span')) => $button->getAttribute('data-sort')]);

    expect($sent->all())->toBe(['Lead' => 'oldest', 'Contact' => 'name', 'Score' => 'score', 'Next action' => 'next_action'])
        ->and(array_diff($sent->values()->all(), array_keys(LeadsIndex::SORTS)))->toBe([])
        ->and(collect([...$page->querySelectorAll('select[aria-label="Sort"] option')])->map(fn ($option) => $option->getAttribute('value'))->all())->toBe(array_keys(LeadsIndex::SORTS))
        // As the screen opens: newest first, and the heading says so.
        ->and(headingNamed($page, 'Lead')->getAttribute('aria-sort'))->toBe('descending')
        ->and($drawn())->toBe(['Mariam Iqbal', 'Ahmed Raza', 'Zainab Qureshi']);

    foreach ([
        ['oldest', 'Lead', 'ascending', ['Zainab Qureshi', 'Ahmed Raza', 'Mariam Iqbal']],
        ['name', 'Contact', 'ascending', ['Ahmed Raza', 'Mariam Iqbal', 'Zainab Qureshi']],
        ['score', 'Score', 'descending', ['Ahmed Raza', 'Mariam Iqbal', 'Zainab Qureshi']],
        ['next_action', 'Next action', 'ascending', ['Ahmed Raza', 'Mariam Iqbal', 'Zainab Qureshi']],
    ] as [$word, $heading, $way, $order]) {
        $screen->set('sort', $word);
        $page = drawnScreen($screen->html());

        expect($names())->toBe($order)
            ->and($drawn())->toBe($order)
            ->and(headingNamed($page, $heading)->getAttribute('aria-sort'))->toBe($way)
            // One column is sorted at a time: the others say they are not.
            ->and($page->querySelectorAll('thead th[aria-sort="ascending"], thead th[aria-sort="descending"]')->length)->toBe(1);
    }

    // A second press on "Lead" turns it round: the heading offers the other of its two words.
    $screen->set('sort', 'oldest');
    expect(headingNamed(drawnScreen($screen->html()), 'Lead')->querySelector('button')->getAttribute('data-sort'))->toBe('newest');

    // A sort that has no column of its own ("Expected value") leaves every heading unsorted.
    $screen->set('sort', 'value');
    expect(drawnScreen($screen->html())->querySelectorAll('thead th[aria-sort="none"]')->length)->toBe(4);
});

it('calls a lead newest by the day of the enquiry, not by the day it was typed in', function () {
    // Leads brought in from a sheet keep their own dates. Entered last, they are not the newest: the list shows
    // the date of the enquiry, and "newest first" has to agree with what it shows.
    $manager = $this->userWithRole('sales_manager');
    $today = Lead::factory()->create(['created_at' => now()->subHour()]);
    $lastWeek = Lead::factory()->create(['created_at' => now()->subDays(7)]);
    $lastYear = Lead::factory()->create(['created_at' => now()->subYear()]);

    // The same moment twice: the one entered later is the newer.
    $twinA = Lead::factory()->create(['created_at' => now()->subDays(3)->startOfMinute()]);
    $twinB = Lead::factory()->create(['created_at' => now()->subDays(3)->startOfMinute()]);

    $screen = Livewire::actingAs($manager)->test(LeadsIndex::class);
    $order = fn () => collect($screen->viewData('leads')->items())->map(fn (Lead $lead) => $lead->id)->all();

    expect($order())->toBe([$today->id, $twinB->id, $twinA->id, $lastWeek->id, $lastYear->id]);

    $screen->set('sort', 'oldest');
    expect($order())->toBe([$lastYear->id, $lastWeek->id, $twinA->id, $twinB->id, $today->id])
        ->and(headingNamed(drawnScreen($screen->html()), 'Lead')->getAttribute('aria-sort'))->toBe('ascending');

    // What is drawn under the heading is the date the order goes by.
    $dates = collect([...drawnScreen($screen->html())->querySelectorAll('tbody tr > th[scope="row"] span')])->map(fn ($date) => saidBy($date))->all();
    expect($dates[0])->toBe($lastYear->created_at->timezone('Asia/Karachi')->format('j M, g:i a'))
        ->and($dates[4])->toBe($today->created_at->timezone('Asia/Karachi')->format('j M, g:i a'));
});

it('turns the pages of the leads and chooses rows as before', function () {
    $manager = $this->userWithRole('sales_manager');
    Lead::factory()->count(30)->create();

    $screen = Livewire::actingAs($manager)->test(LeadsIndex::class);
    $page = drawnScreen($screen->html());
    $onPage = collect($screen->viewData('leads')->items())->map(fn (Lead $lead) => (string) $lead->id)->all();

    expect($page->querySelectorAll('tbody tr')->length)->toBe(25)
        ->and(saidBy($page->querySelector('nav[aria-label="Pagination"] p')))->toBe('Showing 1–25 of 30')
        // "Select all on this page" chooses the rows of this page and no others.
        ->and(json_decode($page->querySelector('thead input[aria-label="Select all on this page"]')->getAttribute('data-ids')))->toBe($onPage)
        ->and(collect([...$page->querySelectorAll('tbody input[type="checkbox"]')])->map(fn ($box) => $box->getAttribute('value'))->all())->toBe($onPage)
        ->and($page->querySelector('[role="group"]'))->toBeNull();

    $screen->set('selected', [$onPage[0], $onPage[1]]);
    $bar = drawnScreen($screen->html())->querySelector('[role="group"][aria-label="Actions on the chosen rows"]');

    expect(saidBy($bar->querySelector('[role="status"]')))->toBe('2 selected')
        ->and($bar->querySelector('select[aria-label="Assign selected leads"]'))->not->toBeNull()
        ->and($bar->querySelector('select[aria-label="Move selected leads"]'))->not->toBeNull()
        // What a person may not do is not offered: a sales manager does not delete leads, an owner does.
        ->and(saidBy($bar))->not->toContain('Delete')->toEndWith('Clear selection');

    $owners = Livewire::actingAs($this->userWithRole('owner'))->test(LeadsIndex::class)->set('selected', [$onPage[0]]);
    expect(saidBy(drawnScreen($owners->html())->querySelector('[role="group"] button[wire\:click="bulkDelete"]')))->toBe('Delete');

    // What is done is done to the chosen rows, and the bar goes with the choice.
    $screen->call('bulkMove', 'contacted');
    expect(Lead::query()->whereHas('stage', fn ($stage) => $stage->where('key', 'contacted'))->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all())->toBe(collect([$onPage[0], $onPage[1]])->sort()->values()->all())
        ->and(drawnScreen($screen->html())->querySelector('[role="group"]'))->toBeNull();

    $screen->call('nextPage');
    $page = drawnScreen($screen->html());
    expect($page->querySelectorAll('tbody tr')->length)->toBe(5)
        ->and(saidBy($page->querySelector('nav[aria-label="Pagination"] p')))->toBe('Showing 26–30 of 30');

    // A search that finds nothing: the words say so, and the way back is inside them.
    $screen->set('search', 'nobody-by-this-name');
    $empty = drawnScreen($screen->html())->querySelector('tbody [role="status"]');
    expect(saidBy($empty))->toContain('No leads match these filters')
        ->and(saidBy($empty->querySelector('button')))->toBe('Clear filters');
});

it('sorts the quotations by a heading exactly as by the list\'s own choice', function () {
    $owner = $this->userWithRole('owner');
    $lead = Lead::factory()->create(['owner_id' => $owner->id, 'business_line' => 'furniture']);

    foreach ([['Reception desk', 900_000_00, 3], ['Boardroom', 2_400_000_00, 1], ['Workstations', 1_100_000_00, 2]] as [$title, $total, $hoursAgo]) {
        $quote = app(CreateQuote::class)->handle($lead->contact, $lead->business_line, $owner, $lead, null, $title);
        Quote::query()->whereKey($quote->id)->update(['total_minor' => $total, 'updated_at' => now()->subHours($hoursAgo)]);
    }

    $screen = Livewire::actingAs($owner)->test(QuotesIndex::class);
    $titles = fn () => collect([...drawnScreen($screen->html())->querySelectorAll('tbody tr > th[scope="row"] span:nth-of-type(2)')])->map(fn ($title) => saidBy($title))->all();

    $page = drawnScreen($screen->html());
    $sent = collect([...$page->querySelectorAll('thead th button[data-sort]')])->mapWithKeys(fn ($button) => [saidBy($button->querySelector('span')) => $button->getAttribute('data-sort')]);
    $known = collect([...$page->querySelectorAll('select[aria-label="Sort"] option')])->map(fn ($option) => $option->getAttribute('value'))->all();

    expect($sent->all())->toBe(['Quotation' => 'number', 'Value' => 'value', 'Updated' => 'updated'])
        ->and($known)->toBe(['updated', 'newest', 'value', 'number'])
        ->and(array_diff($sent->values()->all(), $known))->toBe([])
        // As the screen opens: the one touched last comes first, and "Updated" says so.
        ->and(headingNamed($page, 'Updated')->getAttribute('aria-sort'))->toBe('descending')
        ->and($titles())->toBe(['Boardroom', 'Workstations', 'Reception desk']);

    $screen->set('sort', 'value');
    expect($titles())->toBe(['Boardroom', 'Workstations', 'Reception desk'])
        ->and(headingNamed(drawnScreen($screen->html()), 'Value')->getAttribute('aria-sort'))->toBe('descending');

    $screen->set('sort', 'number');
    expect($titles())->toBe(['Workstations', 'Boardroom', 'Reception desk'])
        ->and(headingNamed(drawnScreen($screen->html()), 'Quotation')->getAttribute('aria-sort'))->toBe('descending');

    $screen->set('search', 'nothing-by-this-name');
    expect(saidBy(drawnScreen($screen->html())->querySelector('tbody [role="status"]')))->toContain('No quotations match');
});
