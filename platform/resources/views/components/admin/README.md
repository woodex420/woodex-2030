# The shared parts of the admin's screens

What a screen of the admin is built from, and the rules each part follows. The owner's document 05 §6 asks for
shared standards "for tables with sort, filters, pagination, selection and bulk actions", for "dialogs,
confirmations, toasts" and for "empty, loading, error, no-permission … states". This page is those standards for
the table, the dialog, the confirmation, the toast and the page links (master list T-041), for the table on
a phone (T-042; document 05 §9: "table-to-card treatment for key workflows"), and for the dashboard's widgets,
their figures and the lists the figures open (T-044; document 05 §7 and document 07 §1).

A rule that a test holds names its test. A rule the code does not follow yet says so, with the line of the master
list that closes it.

## 1. The table: `<x-admin.table>`

**A list does not write `<table>`.** It draws `<x-admin.table>`. `tests/Unit/AdminTableTest.php` lists the views
that still write one by hand (51 on 19 November 2026, 45 once the first six lists had moved, 44 with Invoices);
the list may only get shorter, and the screen pass (T-045) takes them area by area. PDF and mail templates keep
their own tables.

```blade
<x-admin.table label="Quotations" :rows="$quotes">
    <x-slot:filters class="grid grid-cols-1 gap-3 md:grid-cols-4"> …search and choices… </x-slot:filters>
    <x-slot:head>
        <x-admin.table.heading :sort="['number' => 'descending']" :current="$sort">Quotation</x-admin.table.heading>
        <x-admin.table.heading align="right">Value</x-admin.table.heading>
        <x-admin.table.heading label="Actions" />
    </x-slot:head>
    @foreach ($quotes as $quote)
        <tr wire:key="quote-{{ $quote->id }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
            <th scope="row" class="px-5 py-3 font-normal">…what names the row…</th>
            <td class="px-5 py-3 text-right tabular-nums">…</td>
        </tr>
    @endforeach
    <x-slot:empty> <x-admin.empty title="No quotations yet" description="…the way to make one…" /> </x-slot:empty>
</x-admin.table>
```

| Part | Rule |
|---|---|
| Name | `label` is required. It is the table's caption (read out, not shown) and is used in the words of each state |
| Headings | Every column has one. A column with nothing written above it (tick boxes, actions) has `label="…"` for a screen reader |
| Rows and cells | Plain `<tr>` and `<td class="px-5 py-3">` (`py-2.5` in a `dense` table). **Not a component each**: that was built and measured, and the leads list with 100 rows took 198 ms to draw where the table written by hand took 107; with plain rows and cells inside the shared frame it takes 125. What is drawn a thousand times on a page is written plainly |
| The row's name | The cell that says what the row is, is `<th scope="row" class="px-5 py-3 font-normal">`: it is read out before the other cells of its row |
| Sorting | `:sort` takes the list's own words with the way each goes: `['newest' => 'descending', 'oldest' => 'ascending']`. The first is what one press gives; a second press takes the next. The list keeps its property (`$sort`) and its query; the component knows no word of any list. A sort that has no column stays in a choice above the table |
| Filters | The `filters` slot, above the table: sent as they change (`wire:model.live`), never behind a button |
| Pages | Hand the paginator to `:rows`. The page links are the shared ones (§5) |
| Choosing rows | `<x-admin.table.select-all>` in the headings, `<x-admin.table.select>` in each row, `:selected` on the table. What can be done with every chosen row goes in the `bulk` slot; it is drawn only while something is chosen, with the number chosen and "Clear selection". An action a person may not take is not offered |
| Narrow screens | Which columns a narrow screen drops is the screen's choice (`class="hidden md:table-cell"`). The page never scrolls sideways; what does not fit scrolls inside the table's own frame, unless the list is drawn as cards on a phone (below) |
| Script | Nothing is printed into an attribute that is run. The property, the word and the keys travel in `data-` attributes (`tests/Feature/Security/StoredScriptTest.php`) |

**The five states**, in the owner's words. A screen that hands over `:rows` gets the second and the fifth by itself.

| State | When | What a person gets | Role |
|---|---|---|---|
| `loading` | The screen says so (`state="loading"`): the rows are not there yet | Three grey lines for the eye, "Loading quotations…" for a screen reader | `status`, and `aria-busy` on the frame |
| `empty` | No rows | The list's own words in the `empty` slot: what is missing, and the way to put something there. After a search that found nothing: that it found nothing, and the way back | `status` |
| `error` | The screen says so (`state="error"`): the rows could not be read | "Quotations could not be loaded", that nothing was changed, and "Try again" | `alert` |
| `no-permission` | The screen says so (`state="no-permission"`): this person may open the screen and not this list | "You do not have access to this list" and whom to ask. No row is drawn | `status` |
| `success` | Rows | The rows, the foot (a total) and the page links | — |

While Livewire asks for the rows again (a filter, a sort, a page) the rows dim and "Loading …" is said to a screen
reader. That is not a state: the old rows stay until the new ones are there.

**Not so yet.** No screen says `loading`, `error` or `no-permission` today: a Livewire screen arrives with its rows,
a failure is the admin's error page, and a person without the right is answered 403 before the screen is drawn.
Placeholders while a slow list loads, and a notice when the connection is lost, are T-051. The reference screen that
shows every state side by side is T-050.

### On a phone: a row is a card

A list that people work from a phone says `cards`, and below 640 px each of its rows is drawn as a card. Leads,
Quotations and Invoices do (T-042).

```blade
<x-admin.table label="Invoices" :rows="$invoices" cards>
    …
    <tr wire:key="invoice-{{ $invoice->id }}">
        <th scope="row" class="px-5 py-3 font-normal">…what names the row, and the link that opens it…</th>
        <td data-label="Client" class="hidden px-5 py-3 md:table-cell">…</td>
        <td data-label="Status" class="px-5 py-3">…</td>
        <td class="hidden px-5 py-3 lg:table-cell">…left off a card…</td>
    </tr>
</x-admin.table>
```

| Rule | Why |
|---|---|
| A card is the row itself. The stylesheet lays the same cells out one under the other (`resources/css/admin.css`, "The shared table on a narrow screen"); nothing is drawn a second time | A card cannot show another value, another link or another order than its row. And the list costs nothing more to draw |
| On top stands the cell that names the row, `<th scope="row">`, with the link that opens the record | It is what a person looks for, and what a screen reader says first |
| A fact is on a card when its cell has `data-label`, and the label is drawn before it | The headings are not drawn on cards, so the label is what says what a fact is: "Stage  Contacted", never a bare word. A browser hands the label to a screen reader with the fact |
| The label is the heading of the cell's column, word for word | What a card calls a fact is what the table calls its column (`Tests\Support\Card::wordsThatDiffer`) |
| A cell without `data-label` is left off the card | The list says what matters on a phone. What is left off is one press away, on the record's own page. Say in the view what is left off, and why |
| A cell that a wider table hides (`hidden md:table-cell`) can still be on the card | Below 640 px there is room for it under the name, where the table had none beside it |
| Where rows can be chosen, the tick box stands left of its card, "Select all on this page" in a line above the cards, and what can be done with the chosen rows in its bar above | Choosing works on a phone as on a desktop |
| Sorting on a phone is the list's own choice above the table | The headings that sort are not drawn. A list whose headings sort keeps a "Sort" choice for this |
| Only a table that shows rows is laid out as cards | "Nothing here yet", an error and the other states keep their one wide cell. The headings are not drawn in those states either: with nothing under them they made the list wider than a phone, and "No leads match these filters" stood half outside the window |
| Every rule for cards stands inside one media query, below 640 px | From 640 px up the table is the table it was. `tests/Unit/AdminTableTest.php` reads the stylesheet, and the built one, for it |

A browser measures what a test cannot read from the page's markup: that nothing of the list scrolls sideways at
390 px, that each fact has its label, that a cell left off is not drawn, and that a lead can be chosen, opened and
moved to another stage from its card (`scripts/os-journeys.mjs`, the last step).

**Not so yet.** The other lists on the shared table (Projects, Users, Payables by age, Receivables) and the 44 that
still write their own table scroll inside their frame on a phone; a list becomes cards when a person needs it on a
phone, in its area's turn (T-045). The rows of status chips and of tabs above some lists scroll sideways on a phone
on purpose, and are not part of the table. A foot under the rows (a total) follows the same rules as a row and was
not tried on a card: none of the three lists has one. Nobody has listened to the cards with a screen reader on a
phone (T-054).

## 2. The dialog: `<x-admin.modal>`

| Rule | How it is held |
|---|---|
| A Livewire property opens it: `@if ($showInvite) <x-admin.modal name="showInvite" …> @endif` | The component |
| The title says what the dialog does ("Invite a team member"), not "Confirm" or "Are you sure?" | Read at review |
| With `submit="…"` it is a form. A form holds work: leaving it with typed text asks first | `tests/Feature/UnsavedChangesTest.php`; the journey in a browser |
| Escape and the close button close it. A click beside it closes it too, unless it holds text that is not saved | The journey "the Back button asks too, and a click beside a changed form does not close it" |
| The buttons stand in the `footer` slot: the action on the right, "Cancel" before it. On a phone the action is on top | The component |
| One dialog at a time. A dialog does not open a second one | Read at review |
| It is announced as a dialog and carries its title as its name (`role="dialog"`, `aria-modal`, `aria-labelledby`) | The component |

**Not so yet.** When a dialog opens, the focus is not moved into it; Tab can leave it; and when it closes the focus
does not return to the button that opened it. The keyboard tests of T-054 close this.

## 3. The confirmation: `wire:confirm`

For an action that cannot be taken back with one press: delete, deactivate, void, send.

- One sentence that names the record and says what will happen, ending in a question: "Deactivate Sana Tariq? They
  will be signed out immediately." Not "Are you sure?".
- The browser's own question is used (`wire:confirm`), so it works without script of ours and is read out.
- What only moves to the recycle bin says so ("Move 3 leads to the recycle bin?"): the person knows it can come back.
- No confirmation for what is undone by doing the opposite: a filter, a tick, opening and closing.

## 4. The toast

A short notice in the corner after something was done: `$this->dispatch('toast', message: '…', type: 'success')`.

| Rule | How it is held |
|---|---|
| Written with names, `message:` and `type:`. Written without, the browser cannot tell a refusal from a save | `tests/Unit/ToastsCanBeReadTest.php` |
| `type` is `success`, `warning` or `error`; left out, it is `success`. A refusal is never green | Read at review. That the browser shows each as what it is: the journey "a notice written without names says what it says" |
| It says what happened, in the past, and names the record: "A new link to set a password was sent to sana@…" | Read at review |
| It is never the only place for something a person must keep: a number, a link, a password. Those stand on the page | Read at review |
| An error that belongs to a field is shown at the field, not in a toast | `x-admin.field` draws the field's error under it |
| It leaves by itself after 4.5 seconds, 8 when it carries a button, and can be dismissed before | `resources/js/admin.js` |
| It is read out without interrupting (`aria-live="polite"`) | `components/admin/toasts.blade.php` |

**Not so yet.** An error leaves after 4.5 seconds like a success, and is read out as politely. An error should stay
until it is dismissed and be announced at once. T-054.

## 5. The page links: `components.admin.pagination`

"Showing 26–50 of 312", Previous, "Page 2 / 13", Next. Shown only when there is more than one page. A list on the
shared table gets them from `:rows`.

**Not so yet.** 14 views still use the framework's plain links (`->links()`); each gets the shared ones when its
list moves to the shared table (T-045).

## 6. The dashboard's widget: `<x-admin.widget>`

One box of the dashboard. A widget is a class under `app/Domain/System/Dashboard/Widgets/` (the contract is
`Widget`), a view under `resources/views/admin/dashboard/widgets/` named by its key, and a line in
`Board::WIDGETS`, which says in which section of the page it stands. The decision is DL-044.

```blade
<x-admin.widget :widget="$widget" :class="$span">
    …what it shows when it is ready…
    <x-slot:definition>1 Nov – 20 Nov 2026 · amounts in PKR · what is counted, in a sentence</x-slot:definition>
</x-admin.widget>
```

**Its states.** Which one is the `Board`'s decision, never the view's; the frame draws all of them, so a widget's
view writes only its ready content.

| State | When | What the person reads |
|---|---|---|
| ready | there is something to show | the content, and under it the definition |
| empty | `isEmpty()`: nothing to count | the widget's own sentence (`emptyWords()`), which says what is missing and names the dates where it counts over dates. No row of noughts, no figure, no definition |
| error | working it out threw | "… could not be loaded", the request number, "Try again". Every other widget is drawn; the failure is in the log under that number |
| no access | the right was taken away while the page stood open | "You no longer have access to …". On the next opening of the page the widget is absent |
| loading | Livewire is asking again for the period, the business line or "Try again" | "Loading …" over the widget. The minute's refresh shows nothing |
| absent | the role may not see it, or its module is switched off | nothing: the widget is not on the page, and the others close the gap |

**The rules.**

| Rule | How it is held |
|---|---|
| A widget that fails does not take the page with it | `DashboardWidgetsTest`: "shows the error of a widget that fails in that widget alone", once for each widget |
| A role sees only its widgets, and the browser cannot ask for another | "shows each role only the widgets that role may see" (seven roles); "cannot be told by the browser which widgets were on the page" |
| A person with no widget is told so in one box, and is offered no period to choose | "tells a person with none of the widgets so" |
| Every chart says its dates, its currency (or that it counts no money) and what it counts, under the chart | "draws every widget ready …, and every chart says its dates, its currency and what it counts" |
| The definition speaks of the figures this person is shown, and of no other | "gives a figure no door where the person may not open the list" |
| The page asks the database a fixed number of questions, however many records there are (52 for the owner) | "asks the database no more than its limit" |
| A number two widgets show is read once in a drawing (`DashboardContext::count()`, `ControlPanel`) | the same test: the limit would be passed |
| Each widget is a region with its heading (`<section aria-labelledby>`, an `<h2>`) | "draws every widget ready …" |

A widget's width is `narrow` (one column of three) or `wide` (two); a widget alone in its row takes the row.
Below 1280 px every widget takes the whole width, in the page's order.

## 7. A figure and its list: `<x-admin.figure>`

**Every figure of the dashboard says how many records it counts and opens exactly those records** (document 05
§7: "Every KPI must link to filtered records"). A figure is a `Figure`: its name (`widget.figure`), its count, its
amount where it is money, and the address of the list.

```blade
<x-admin.figure :figure="$figure">Open the {{ $figure->counted() }}</x-admin.figure>
```

- **One definition.** The count and the list ask the same scope of the model: `Lead::createdBetween()` and
  `followUpDue()`, `Quote::sentBetween()` and `acceptedBetween()`, `Invoice::issuedBetween()`, `owing()`,
  `overdue()` and `aged()`, `Payment::collectedBetween()`, `Bill::owing()` and `due()`, `Project::health()` and
  `milestoneSoon()`, `Approval::quotesWaiting()`. The revenue and funnel reports ask the period scopes too. A new
  figure gets its scope first, then its widget and its filter.
- **The list says its number.** A list on the shared table prints `data-list-rows` (and "N rows" where it has one
  page); the figure prints `data-figure-count`. `DashboardWidgetsTest` opens every figure's address and compares
  the two, for the owner, for a person who sees only their own, and for another period and business line.
- **Periods are whole days on the studio's clock** (`Asia/Karachi`): from the first second of one day to the last
  second of another. A list takes them as `?from=2026-11-01&to=2026-11-20` (`FiltersByPeriod`).
- **No door where the list is not the person's.** A figure the person may see but whose list they may not open,
  or would see only in part, is drawn as text (`data-figure-access="none"`), not as a link to other rows.
- **A figure that is not a list of its rows says so**: `rows: false` (the Automation screen lists every task, not
  the failing ones). It is a link without `data-figure-rows`, and no test compares it.

## 8. "Filtered: …  Clear": `<x-admin.filtered>`

A list that a figure opened arrives cut to the figure's rows. The line above it says to what, in words ("Issued
1 Nov – 20 Nov 2026"), and "Clear" gives the whole list back and leaves the search and the other filters. A
short list without a reason looks like missing records.

```blade
<x-admin.filtered :chips="$filtered" />
```

The list's component needs a method `clearQuickFilters()`: "Clear" calls it by that name. The name is written in
the part and is not handed in, because what stands in `wire:click` is run as script, and only a fixed word may
stand there (`tests/Feature/Security/StoredScriptTest.php`).

