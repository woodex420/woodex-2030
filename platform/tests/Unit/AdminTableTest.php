<?php

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ViewException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/*
| One table for every list (master list T-041; the owner's document 05 §6).
|
| resources/views/components/admin/table.blade.php and the parts beside it are the table of the admin: headings
| that sort, a place for filters, pages, the choosing of rows with what can be done to all chosen, and five states
| in the owner's words: loading, empty, error, no-permission, success.
|
| These tests draw the component and read it as a screen reader does: by the names and roles in the page, not by
| the classes. The last one counts the screens that still write a table by hand. That list may only get shorter.
*/

uses(TestCase::class);

/** Draws a table and hands back the page it makes, to be asked with CSS selectors. */
function drawnTable(string $inside, array $data = [], string $attributes = ':rows="$rows"'): HTMLDocument
{
    View::share('errors', new ViewErrorBag);

    $html = Blade::render(<<<BLADE
        <x-admin.table label="Quotations" {$attributes}>
            <x-slot:head>
                <x-admin.table.heading>Quotation</x-admin.table.heading>
                <x-admin.table.heading class="hidden md:table-cell">Client</x-admin.table.heading>
                <x-admin.table.heading align="right">Value</x-admin.table.heading>
            </x-slot:head>
            {$inside}
        </x-admin.table>
        BLADE, $data + ['rows' => [['ref' => 'Q-0001', 'client' => 'Ahsan Traders', 'value' => 'Rs 4,50,000']]]);

    return HTMLDocument::createFromString('<!DOCTYPE html><body>'.$html.'</body>', LIBXML_NOERROR);
}

const TABLE_ROWS = <<<'BLADE'
    @foreach ($rows as $row)
        <x-admin.table.row>
            <x-admin.table.cell header>{{ $row['ref'] }}</x-admin.table.cell>
            <x-admin.table.cell class="hidden md:table-cell">{{ $row['client'] }}</x-admin.table.cell>
            <x-admin.table.cell align="right" numeric>{{ $row['value'] }}</x-admin.table.cell>
        </x-admin.table.row>
    @endforeach
    BLADE;

/** What a person is told inside the rows: the words of the cell that stands for a state. */
function saidInRows(HTMLDocument $page): string
{
    return trim((string) preg_replace('/\s+/', ' ', $page->querySelector('tbody')->textContent));
}

it('is a named table whose headings name their columns and whose first cell names its row', function () {
    $page = drawnTable(TABLE_ROWS);

    expect($page->querySelectorAll('table')->length)->toBe(1)
        ->and(trim($page->querySelector('table > caption')->textContent))->toBe('Quotations')
        ->and($page->querySelector('[data-table-state]')->getAttribute('data-table-state'))->toBe('success');

    $headings = $page->querySelectorAll('thead th');
    expect($headings->length)->toBe(3);
    foreach ($headings as $heading) {
        expect($heading->getAttribute('scope'))->toBe('col');
    }

    expect($page->querySelectorAll('tbody tr')->length)->toBe(1)
        ->and($page->querySelector('tbody th')->getAttribute('scope'))->toBe('row')
        ->and(trim($page->querySelector('tbody th')->textContent))->toBe('Q-0001')
        ->and(saidInRows($page))->toBe('Q-0001 Ahsan Traders Rs 4,50,000')
        // Nothing stands in for the rows, and nobody is told that something went wrong.
        ->and($page->querySelector('tbody [role="status"], tbody [role="alert"]'))->toBeNull();
});

it('draws each of its five states with words and a role a screen reader reads', function (string $attributes, string $state, string $role, string $says, array $rows) {
    $page = drawnTable(TABLE_ROWS, ['rows' => $rows], $attributes);
    $frame = $page->querySelector('[data-table-state]');

    expect($frame->getAttribute('data-table-state'))->toBe($state);

    if ($state === 'success') {
        expect(saidInRows($page))->toBe('Q-0001 Ahsan Traders Rs 4,50,000');

        return;
    }

    // One cell stands for the state. It spans every column, and the rows of the list are not drawn beside it.
    $cell = $page->querySelector('tbody tr:not([aria-hidden]) > td');
    expect((int) $cell->getAttribute('colspan'))->toBe(3)
        ->and($page->querySelectorAll('tbody tr:not([aria-hidden])')->length)->toBe(1)
        ->and(saidInRows($page))->not->toContain('Q-0001')
        ->and($cell->querySelector('[role="'.$role.'"]'))->not->toBeNull()
        ->and(trim((string) preg_replace('/\s+/', ' ', $cell->querySelector('[role="'.$role.'"]')->textContent)))->toContain($says);

    // The table is still a table with its name and its headings: a person knows what did not come.
    expect(trim($page->querySelector('table > caption')->textContent))->toBe('Quotations')
        ->and($page->querySelectorAll('thead th')->length)->toBe(3)
        ->and($page->querySelector('nav[aria-label="Pagination"]'))->toBeNull();
})->with([
    'loading' => [':rows="$rows" state="loading"', 'loading', 'status', 'Loading quotations…', []],
    'empty, by itself when there are no rows' => [':rows="$rows"', 'empty', 'status', 'Nothing here yet', []],
    'error' => [':rows="$rows" state="error"', 'error', 'alert', 'Quotations could not be loaded', []],
    'no-permission' => [':rows="$rows" state="no-permission"', 'no-permission', 'status', 'You do not have access to this list', [['ref' => 'Q-0001', 'client' => 'Ahsan Traders', 'value' => 'Rs 4,50,000']]],
    'success, by itself when there are rows' => [':rows="$rows"', 'success', '', '', [['ref' => 'Q-0001', 'client' => 'Ahsan Traders', 'value' => 'Rs 4,50,000']]],
]);

it('says more in each state than its name: what to do, and that nothing was lost', function () {
    $loading = drawnTable(TABLE_ROWS, ['rows' => []], ':rows="$rows" state="loading"');
    expect($loading->querySelector('[aria-busy="true"]'))->not->toBeNull()
        // The grey lines are for the eye; a screen reader is given the sentence only.
        ->and($loading->querySelectorAll('tbody tr[aria-hidden="true"]')->length)->toBe(3);

    $error = drawnTable(TABLE_ROWS, ['rows' => []], ':rows="$rows" state="error"');
    expect(saidInRows($error))->toContain('Nothing was changed. Try again in a moment.')
        ->and($error->querySelector('tbody button')->getAttribute('wire:click'))->toBe('$refresh')
        ->and(trim($error->querySelector('tbody button')->textContent))->toBe('Try again');

    $closed = drawnTable(TABLE_ROWS, [], ':rows="$rows" state="no-permission"');
    expect(saidInRows($closed))->toContain('ask the owner to change your role')
        ->and($closed->querySelector('tbody button'))->toBeNull();

    // A screen says its own words where it knows more: why the list is empty, and the way out of it.
    $own = drawnTable(TABLE_ROWS."\n".'<x-slot:empty><x-admin.empty icon="quote" title="No quotations match" description="Try another search."><x-admin.button>Clear filters</x-admin.button></x-admin.empty></x-slot:empty>', ['rows' => []]);
    expect(saidInRows($own))->toBe('No quotations match Try another search. Clear filters')
        ->and($own->querySelector('tbody [role="status"] button'))->not->toBeNull();
});

it('refuses a state that is not one of the five', function () {
    expect(fn () => drawnTable(TABLE_ROWS, [], ':rows="$rows" state="busy"'))
        ->toThrow(ViewException::class, 'loading, empty, error, no-permission, success');
});

it('has headings that sort, and each says how it sorts', function () {
    $draw = fn (string $current) => HTMLDocument::createFromString('<!DOCTYPE html><body><table><thead><tr>'.Blade::render(<<<'BLADE'
        <x-admin.table.heading :sort="['newest' => 'descending', 'oldest' => 'ascending']" :current="$current">Lead</x-admin.table.heading>
        <x-admin.table.heading :sort="['score' => 'descending']" :current="$current" align="right">Score</x-admin.table.heading>
        <x-admin.table.heading>Stage</x-admin.table.heading>
        <x-admin.table.heading label="Actions" />
        BLADE, ['current' => $current]).'</tr></thead></table></body>', LIBXML_NOERROR);
    $said = fn (Element $heading) => trim((string) preg_replace('/\s+/', ' ', $heading->textContent));

    [$lead, $score, $stage, $actions] = [...$draw('newest')->querySelectorAll('th')];

    // Sorted by this column: which way, and what a press does next.
    expect($lead->getAttribute('aria-sort'))->toBe('descending')
        ->and($said($lead))->toBe('Lead Sorted descending. Press to sort ascending.')
        ->and($lead->querySelector('button')->getAttribute('type'))->toBe('button')
        ->and($lead->querySelector('button')->getAttribute('data-sort'))->toBe('oldest')
        ->and($lead->querySelector('button')->getAttribute('data-model'))->toBe('sort');

    // Another column the list can sort by: not sorted now, and a press sorts by it.
    expect($score->getAttribute('aria-sort'))->toBe('none')
        ->and($said($score))->toBe('Score Not sorted by this column. Press to sort descending.')
        ->and($score->querySelector('button')->getAttribute('data-sort'))->toBe('score');

    // A column the list cannot sort by is words, not a button, and claims nothing about sorting.
    expect($stage->hasAttribute('aria-sort'))->toBeFalse()
        ->and($stage->querySelector('button'))->toBeNull()
        ->and($said($stage))->toBe('Stage');

    // A column with nothing written above it still has a name.
    expect($said($actions))->toBe('Actions')
        ->and($actions->querySelector('.sr-only'))->not->toBeNull();

    [$lead, $score] = [...$draw('oldest')->querySelectorAll('th')];
    expect($lead->getAttribute('aria-sort'))->toBe('ascending')
        ->and($lead->querySelector('button')->getAttribute('data-sort'))->toBe('newest');

    // A list that sorts this column one way only does not offer to turn it round.
    [, $score] = [...$draw('score')->querySelectorAll('th')];
    expect($score->getAttribute('aria-sort'))->toBe('descending')
        ->and($said($score))->toBe('Score Sorted descending.');
});

it('prints nothing into a script: the property and the word travel as data', function () {
    $html = Blade::render(<<<'BLADE'
        <x-admin.table.heading :sort="[$word => 'ascending']" current="" :model="$model">Name</x-admin.table.heading>
        <x-admin.table.select-all :ids="$ids" :selected="[]" :model="$model" />
        BLADE, ['word' => "name'); alert(1); ('", 'model' => "sort'); alert(2); ('", 'ids' => ["7'); alert(3); ('", 8]]);
    $page = HTMLDocument::createFromString('<!DOCTYPE html><body><table><tr>'.$html.'</tr></table></body>', LIBXML_NOERROR);

    $button = $page->querySelector('th button');
    $box = $page->querySelector('th input[type="checkbox"]');

    // What is run is the same fixed sentence whatever the screen handed in …
    expect($button->getAttribute('x-on:click'))->toBe('$wire.set($el.dataset.model, $el.dataset.sort)')
        ->and($box->getAttribute('x-on:change'))->toBe('$wire.set($el.dataset.model, $event.target.checked ? JSON.parse($el.dataset.ids) : [])')
        // … and what was handed in is data, character for character.
        ->and($button->getAttribute('data-sort'))->toBe("name'); alert(1); ('")
        ->and($button->getAttribute('data-model'))->toBe("sort'); alert(2); ('")
        ->and(json_decode($box->getAttribute('data-ids'), flags: JSON_THROW_ON_ERROR))->toBe(["7'); alert(3); ('", '8']);
});

it('chooses rows: a named box for each, one for the page, and what can be done only while something is chosen', function () {
    $draw = fn (array $selected) => HTMLDocument::createFromString('<!DOCTYPE html><body>'.Blade::render(<<<'BLADE'
        <x-admin.table label="Leads" :rows="$rows" :selected="$selected">
            <x-slot:bulk><button type="button">Assign to…</button></x-slot:bulk>
            <x-slot:head>
                <x-admin.table.select-all :ids="collect($rows)->pluck('id')" :selected="$selected" />
                <x-admin.table.heading>Lead</x-admin.table.heading>
            </x-slot:head>
            @foreach ($rows as $row)
                <x-admin.table.row :chosen="in_array((string) $row['id'], $selected, true)">
                    <x-admin.table.select :value="$row['id']" label="Select {{ $row['ref'] }}" />
                    <x-admin.table.cell>{{ $row['ref'] }}</x-admin.table.cell>
                </x-admin.table.row>
            @endforeach
        </x-admin.table>
        BLADE, ['rows' => [['id' => 41, 'ref' => 'L-0041'], ['id' => 42, 'ref' => 'L-0042']], 'selected' => $selected]).'</body>', LIBXML_NOERROR);

    $none = $draw([]);
    expect($none->querySelector('[role="group"]'))->toBeNull()
        ->and($none->querySelector('thead input[type="checkbox"]')->getAttribute('aria-label'))->toBe('Select all on this page')
        ->and($none->querySelector('thead input[type="checkbox"]')->hasAttribute('checked'))->toBeFalse()
        ->and(json_decode($none->querySelector('thead input')->getAttribute('data-ids')))->toBe(['41', '42'])
        ->and($none->querySelector('thead th:first-child')->getAttribute('scope'))->toBe('col');

    $boxes = $none->querySelectorAll('tbody input[type="checkbox"]');
    expect($boxes->length)->toBe(2)
        ->and($boxes[0]->getAttribute('aria-label'))->toBe('Select L-0041')
        ->and($boxes[0]->getAttribute('value'))->toBe('41')
        ->and($boxes[0]->getAttribute('wire:model.live'))->toBe('selected');

    $one = $draw(['42']);
    $bar = $one->querySelector('[role="group"][aria-label="Actions on the chosen rows"]');
    expect($bar)->not->toBeNull()
        ->and(trim($bar->querySelector('[role="status"]')->textContent))->toBe('1 selected')
        ->and(trim((string) preg_replace('/\s+/', ' ', $bar->textContent)))->toBe('1 selected Assign to… Clear selection')
        ->and($one->querySelector('thead input[type="checkbox"]')->hasAttribute('checked'))->toBeFalse();

    expect($draw(['41', '42'])->querySelector('thead input[type="checkbox"]')->hasAttribute('checked'))->toBeTrue();
});

it('gives the filters a place and a name, and turns pages with the shared page links', function () {
    $rows = collect(range(1, 10))->map(fn (int $n) => ['ref' => 'Q-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'client' => 'Client '.$n, 'value' => 'Rs '.$n]);
    $page = drawnTable(
        '<x-slot:filters class="grid gap-3"><input type="search" aria-label="Search quotations"></x-slot:filters>'."\n".TABLE_ROWS,
        ['rows' => new LengthAwarePaginator($rows, 30, 10, 1)],
    );

    $filters = $page->querySelector('[role="search"]');
    expect($filters->getAttribute('aria-label'))->toBe('Filter quotations')
        ->and($filters->getAttribute('class'))->toContain('grid gap-3')
        ->and($filters->querySelector('input[type="search"]'))->not->toBeNull()
        // The filters stand before the table, where a person meets them first.
        ->and($filters->compareDocumentPosition($page->querySelector('table')) & Node::DOCUMENT_POSITION_FOLLOWING)->toBe(Node::DOCUMENT_POSITION_FOLLOWING);

    $pages = $page->querySelector('nav[aria-label="Pagination"]');
    expect($page->querySelectorAll('tbody tr')->length)->toBe(10)
        ->and(trim((string) preg_replace('/\s+/', ' ', $pages->textContent)))->toBe('Showing 1–10 of 30 Previous Page 1 / 3 Next')
        ->and($pages->querySelector('button[wire\:click="nextPage"]'))->not->toBeNull();

    // A list that fits one page shows no page links at all.
    expect(drawnTable(TABLE_ROWS, ['rows' => new LengthAwarePaginator($rows->take(3), 3, 10, 1)])->querySelector('nav'))->toBeNull();
});

it('keeps a total under the rows, and drops it when there are none', function () {
    $foot = "\n<x-slot:foot><tr><td>Total</td> <td></td> <td>Rs 4,50,000</td></tr></x-slot:foot>";

    expect(trim((string) preg_replace('/\s+/', ' ', drawnTable(TABLE_ROWS.$foot)->querySelector('tfoot')->textContent)))->toBe('Total Rs 4,50,000')
        ->and(drawnTable(TABLE_ROWS.$foot, ['rows' => []])->querySelector('tfoot'))->toBeNull();
});

it('counts the screens that still write a table by hand: the list may only get shorter', function () {
    // Documents and mails keep their own tables: a PDF and a mail client know nothing of this component.
    // A list moves to <x-admin.table> by losing its line here, in the same commit (master list T-045 takes them
    // area by area). A new line here is a new hand-written table, and wants a reason beside it.
    $written = [
        'commerce/product-preview.blade.php',                           // a product's specifications as the shop draws them: not a list
        'livewire/admin/accounts/bills-index.blade.php',
        'livewire/admin/accounts/chart-of-accounts.blade.php',
        'livewire/admin/accounts/expenses-index.blade.php',
        'livewire/admin/accounts/journals-index.blade.php',
        'livewire/admin/accounts/method-accounts.blade.php',
        'livewire/admin/accounts/periods-index.blade.php',
        'livewire/admin/accounts/recurring-expenses.blade.php',
        'livewire/admin/accounts/trial-balance.blade.php',
        'livewire/admin/accounts/vendors-index.blade.php',
        'livewire/admin/automation/sequences-index.blade.php',
        'livewire/admin/crm/companies-index.blade.php',
        'livewire/admin/crm/contact-book.blade.php',
        'livewire/admin/crm/contacts-index.blade.php',
        'livewire/admin/crm/lead-import.blade.php',
        'livewire/admin/crm/lead-tracking-board.blade.php',
        'livewire/admin/crm/leads-index.blade.php',
        'livewire/admin/marketing/attribution-index.blade.php',
        'livewire/admin/marketing/social-planner.blade.php',
        'livewire/admin/projects/project-costs.blade.php',
        'livewire/admin/reports/receivables.blade.php',
        'livewire/admin/reports/revenue-collections.blade.php',
        'livewire/admin/reports/sales-funnel.blade.php',
        'livewire/admin/sales/bank-accounts.blade.php',
        'livewire/admin/sales/due-payments.blade.php',
        'livewire/admin/sales/estimations/enquiries.blade.php',
        'livewire/admin/sales/estimations/estimates.blade.php',
        'livewire/admin/sales/invoice-show.blade.php',
        'livewire/admin/sales/invoice-tracking-board.blade.php',
        'livewire/admin/sales/invoices-index.blade.php',
        'livewire/admin/sales/payments-index.blade.php',
        'livewire/admin/sales/proposals-index.blade.php',
        'livewire/admin/sales/quote-templates.blade.php',
        'livewire/admin/sales/quote-tracking-board.blade.php',
        'livewire/admin/sales/rate-book.blade.php',
        'livewire/admin/sales/recurring-invoices.blade.php',
        'livewire/admin/system/integration-log.blade.php',
        'livewire/admin/system/integration-webhooks.blade.php',
        'livewire/admin/system/lead-rules-settings.blade.php',
        'livewire/admin/system/team-onboarding.blade.php',
        'livewire/admin/system/users-index.blade.php',
        'livewire/admin/system/whatsapp-templates.blade.php',
        'livewire/admin/website/find-replace-index.blade.php',
        'livewire/admin/website/pages-index.blade.php',
        'livewire/admin/website/redirects-index.blade.php',
        'livewire/admin/website/seo-agent.blade.php',
        'livewire/admin/website/seo-overview-index.blade.php',
        'livewire/admin/website/speed.blade.php',
    ];

    $found = [];
    $using = [];

    foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php')->notPath('#^(pdf|mail)/#')->sortByName() as $file) {
        $path = str_replace('\\', '/', $file->getRelativePathname());
        $source = $file->getContents();

        if ($path !== 'components/admin/table.blade.php' && str_contains($source, '<table')) {
            $found[] = $path;
        }
        if (str_contains($source, '<x-admin.table ') || str_contains($source, "<x-admin.table\n")) {
            $using[] = $path;
        }
    }
    sort($found);
    sort($written);

    expect($found)->toBe($written)
        // Written down on 19 November 2026, before the first list moved: 51. It may only fall.
        ->and(count($found))->toBeLessThanOrEqual(51)
        // A screen is on one side or the other: it does not draw the shared table and one of its own.
        ->and(array_values(array_intersect($using, $found)))->toBe([]);
});
