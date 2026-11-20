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
use Tests\Support\Card;
use Tests\TestCase;

/*
| One table for every list (master list T-041; the owner's document 05 §6).
|
| resources/views/components/admin/table.blade.php and the parts beside it are the table of the admin: headings
| that sort, a place for filters, pages, the choosing of rows with what can be done to all chosen, and five states
| in the owner's words: loading, empty, error, no-permission, success.
|
| The rows and cells inside it are plain <tr>, <th scope="row"> and <td>: a component for every cell nearly doubled
| the time to draw a list (the table's own note has the figures). The last test here holds that too.
|
| These tests draw the component and read it as a screen reader does: by the names and roles in the page, not by
| the classes. The last one counts the screens that still write a table by hand. That list may only get shorter.
|
| On a narrow screen (master list T-042) a list that says cards is drawn as cards, by the stylesheet alone. What a
| test can read of that without a browser is here: which cells a card holds and under which word, and that the
| rules for cards stand inside one media query, so that the table from 640 px up is the table it was. How a card
| looks, and that nothing scrolls sideways, a browser measures (scripts/os-journeys.mjs, the last step).
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
        <tr>
            <th scope="row" class="px-5 py-3 font-normal">{{ $row['ref'] }}</th>
            <td class="hidden px-5 py-3 md:table-cell">{{ $row['client'] }}</td>
            <td class="px-5 py-3 text-right tabular-nums">{{ $row['value'] }}</td>
        </tr>
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
                <tr>
                    <x-admin.table.select :value="$row['id']" label="Select {{ $row['ref'] }}" />
                    <th scope="row" class="px-5 py-3 font-normal">{{ $row['ref'] }}</th>
                </tr>
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
        ->and($boxes[0]->getAttribute('wire:model.live'))->toBe('selected')
        // What a pointer hits is the label around the box, not the 16 px of the box (a browser measures it:
        // scripts/os-screens.mjs counts controls smaller than 24 px).
        ->and($boxes[0]->closest('label'))->not->toBeNull()
        ->and($none->querySelector('thead input[type="checkbox"]')->closest('label'))->not->toBeNull();

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

it('draws a row as a card on a narrow screen: what names it, each fact under its word, and nothing the list did not mark', function () {
    $rows = <<<'BLADE'
        @foreach ($rows as $row)
            <tr>
                <th scope="row" class="px-5 py-3 font-normal"><a href="/quotes/{{ $row['ref'] }}">{{ $row['ref'] }}</a></th>
                <td data-label="Client" class="hidden px-5 py-3 md:table-cell">{{ $row['client'] }}</td>
                <td data-label="Value" class="px-5 py-3 text-right tabular-nums">{{ $row['value'] }}</td>
            </tr>
        @endforeach
        BLADE;

    $cards = drawnTable($rows, attributes: ':rows="$rows" cards');
    $frame = $cards->querySelector('[data-table-state]');

    // The list says it with one word; the stylesheet does the rest, and only for a table that shows rows.
    expect($frame->classList->contains('wx-table-cards'))->toBeTrue()
        ->and($frame->classList->contains('wx-table-choose'))->toBeFalse()
        ->and($frame->getAttribute('data-table-state'))->toBe('success')
        ->and(drawnTable($rows)->querySelector('[data-table-state]')->classList->contains('wx-table-cards'))->toBeFalse();

    // It is one table and one row for each record: a card is the row itself, not a second drawing of it.
    expect($cards->querySelectorAll('table')->length)->toBe(1)
        ->and($cards->querySelectorAll('tbody > tr')->length)->toBe(1);

    $row = $cards->querySelector('tbody > tr');
    expect(Card::of($row))->toBe(['name' => 'Q-0001', 'facts' => ['Client: Ahsan Traders', 'Value: Rs 4,50,000'], 'leads' => '/quotes/Q-0001'])
        // The word on a card is the heading of the cell's column, word for word: the two cannot drift apart.
        ->and(Card::wordsThatDiffer($row))->toBe([]);

    // A list that calls a fact by another word than its column is named by the same reading.
    $drifted = drawnTable(str_replace('data-label="Client"', 'data-label="Customer"', $rows), attributes: ':rows="$rows" cards');
    expect(Card::wordsThatDiffer($drifted->querySelector('tbody > tr')))->toBe(['Customer under "Client"']);

    // A state that is not rows keeps its one wide cell: the frame says which state it is, and the rules that lay a
    // row out as a card name "success" only. (The headings are not drawn in any state, below 640 px.)
    $empty = drawnTable($rows, ['rows' => []], ':rows="$rows" cards')->querySelector('[data-table-state]');
    expect($empty->getAttribute('data-table-state'))->toBe('empty')
        ->and($empty->classList->contains('wx-table-cards'))->toBeTrue();
});

it('keeps the choosing of rows within reach on a card: the box of each row, and one for the page with its words', function () {
    $page = HTMLDocument::createFromString('<!DOCTYPE html><body>'.Blade::render(<<<'BLADE'
        <x-admin.table label="Leads" :rows="$rows" :selected="[]" cards>
            <x-slot:head>
                <x-admin.table.select-all :ids="collect($rows)->pluck('id')" :selected="[]" />
                <x-admin.table.heading>Lead</x-admin.table.heading>
                <x-admin.table.heading>Stage</x-admin.table.heading>
            </x-slot:head>
            @foreach ($rows as $row)
                <tr>
                    <x-admin.table.select :value="$row['id']" label="Select {{ $row['ref'] }}" />
                    <th scope="row" class="px-5 py-3 font-normal">{{ $row['ref'] }}</th>
                    <td data-label="Stage" class="px-5 py-3">{{ $row['stage'] }}</td>
                </tr>
            @endforeach
        </x-admin.table>
        BLADE, ['rows' => [['id' => 41, 'ref' => 'L-0041', 'stage' => 'Contacted']]]).'</body>', LIBXML_NOERROR);

    $frame = $page->querySelector('[data-table-state]');
    $all = $page->querySelector('thead th.wx-select-all');
    $words = $all->querySelector('label > .wx-card-only');

    expect($frame->classList->contains('wx-table-cards'))->toBeTrue()
        ->and($frame->classList->contains('wx-table-choose'))->toBeTrue()
        // The box of a row is found by the stylesheet, to stand left of its card.
        ->and($page->querySelector('tbody > tr > td.wx-select input[type="checkbox"]')->getAttribute('aria-label'))->toBe('Select L-0041')
        // The headings are not drawn on cards. "Select all on this page" stays, with its words beside the box: they
        // are inside the label, so a press on them ticks the box, and hidden from a screen reader, which already
        // has them as the name of the box.
        ->and(trim($words->textContent))->toBe('Select all on this page')
        ->and($words->getAttribute('aria-hidden'))->toBe('true')
        ->and($all->querySelector('input[type="checkbox"]')->getAttribute('aria-label'))->toBe('Select all on this page')
        ->and($all->querySelector('label')->contains($all->querySelector('input')))->toBeTrue()
        ->and(Card::of($page->querySelector('tbody > tr')))->toBe(['name' => 'L-0041', 'facts' => ['Stage: Contacted'], 'leads' => null])
        // The box of the row and the cell that names it stand before the fact: the word is still the word of its column.
        ->and(Card::wordsThatDiffer($page->querySelector('tbody > tr')))->toBe([]);

    // Where the list does not say cards, the same parts are drawn and nothing is marked for the stylesheet.
    $table = HTMLDocument::createFromString('<!DOCTYPE html><body>'.Blade::render('<x-admin.table label="Leads" :rows="[1]" :selected="[]"><x-slot:head><x-admin.table.select-all :ids="[1]" :selected="[]" /></x-slot:head><tr><th scope="row">L</th></tr></x-admin.table>').'</body>', LIBXML_NOERROR);
    expect($table->querySelector('[data-table-state]')->classList->contains('wx-table-choose'))->toBeFalse();
});

it('changes nothing from 640 px up: every rule for cards stands inside one media query, in the source and in the built stylesheet', function () {
    $source = (string) file_get_contents(resource_path('css/admin.css'));

    // The one block, by its opening words, and where it ends (the braces are counted).
    $opens = strpos($source, '@media (width < 40rem) {');
    expect($opens)->not->toBeFalse()
        ->and(substr_count($source, '@media (width < 40rem) {'))->toBe(1);

    $depth = 0;
    $closes = null;
    for ($at = strpos($source, '{', $opens); $at < strlen($source); $at++) {
        $depth += $source[$at] === '{' ? 1 : ($source[$at] === '}' ? -1 : 0);
        if ($depth === 0) {
            $closes = $at;
            break;
        }
    }
    $block = substr($source, $opens, $closes - $opens);

    // Every rule that names the cards is inside it …
    preg_match_all('/\.wx-table-cards/', $source, $everywhere, PREG_OFFSET_CAPTURE);
    $outside = array_filter($everywhere[0], function (array $found) use ($source, $opens, $closes) {
        // The note above the block names the class too; a note is not a rule.
        $inNote = strrpos(substr($source, 0, $found[1]), '/*') > strrpos(substr($source, 0, $found[1]), '*/');

        return ! $inNote && ($found[1] < $opens || $found[1] > $closes);
    });
    expect($everywhere[0])->not->toBeEmpty()
        ->and($outside)->toBe([]);

    // … and the block holds nothing else: each of its rules begins with the cards' own class.
    $rules = array_filter(array_map('trim', preg_split('/\}\s*/', (string) preg_replace('#/\*.*?\*/#s', '', substr($block, strpos($block, '{') + 1)))));
    foreach ($rules as $rule) {
        expect($rule)->toMatch('/^(\.dark )?\.wx-table-cards[.\[ ]/');
    }
    expect(count($rules))->toBeGreaterThan(10);

    // The words beside "Select all" are not drawn at all unless a rule for cards shows them.
    expect($source)->toContain('.wx-card-only { display:none; }');

    // The stylesheet the browser gets is the built one (public/build is committed: AGENTS.md §6). A rule written
    // in the source and not built does nothing; this fails then.
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    $built = (string) file_get_contents(public_path('build/'.$manifest['resources/css/admin.css']['file']));
    $from = strpos($built, '@media not all and (width>=40rem){.wx-table-cards');
    expect($from)->not->toBeFalse();

    $depth = 0;
    $to = null;
    for ($at = strpos($built, '{', $from); $at < strlen($built); $at++) {
        $depth += $built[$at] === '{' ? 1 : ($built[$at] === '}' ? -1 : 0);
        if ($depth === 0) {
            $to = $at;
            break;
        }
    }
    preg_match_all('/\.wx-table-cards/', $built, $inBuilt, PREG_OFFSET_CAPTURE);
    expect(array_filter($inBuilt[0], fn (array $found) => $found[1] < $from || $found[1] > $to))->toBe([])
        ->and($built)->toContain('.wx-card-only{display:none}')
        ->and($built)->toContain('content:attr(data-label)');
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
        'livewire/admin/marketing/attribution-index.blade.php',
        'livewire/admin/marketing/social-planner.blade.php',
        'livewire/admin/projects/project-costs.blade.php',
        'livewire/admin/reports/revenue-collections.blade.php',
        'livewire/admin/reports/sales-funnel.blade.php',
        'livewire/admin/sales/bank-accounts.blade.php',
        'livewire/admin/sales/due-payments.blade.php',
        'livewire/admin/sales/estimations/enquiries.blade.php',
        'livewire/admin/sales/estimations/estimates.blade.php',
        'livewire/admin/sales/invoice-show.blade.php',
        'livewire/admin/sales/invoice-tracking-board.blade.php',
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

    // The parts of the table are the frame, a heading and the two tick boxes. A part for each row or each cell was
    // built and measured, and taken out again: it is drawn a thousand times on a page.
    $parts = collect((new Finder)->files()->in(resource_path('views/components/admin/table'))->name('*.blade.php')->sortByName())
        ->map(fn ($file) => $file->getBasename('.blade.php'))->values()->all();

    expect($parts)->toBe(['heading', 'select-all', 'select'])
        ->and($found)->toBe($written)
        // 51 on 19 November 2026, before the first list moved; 45 once the first six had; 44 with Invoices
        // (T-042). It may only fall.
        ->and(count($found))->toBeLessThanOrEqual(44)
        // A screen is on one side or the other: it does not draw the shared table and one of its own.
        ->and(array_values(array_intersect($using, $found)))->toBe([]);
});
