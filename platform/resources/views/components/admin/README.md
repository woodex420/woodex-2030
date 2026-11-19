# The shared parts of the admin's screens

What a screen of the admin is built from, and the rules each part follows. The owner's document 05 §6 asks for
shared standards "for tables with sort, filters, pagination, selection and bulk actions", for "dialogs,
confirmations, toasts" and for "empty, loading, error, no-permission … states". This page is those standards for
the table, the dialog, the confirmation, the toast and the page links (master list T-041).

A rule that a test holds names its test. A rule the code does not follow yet says so, with the line of the master
list that closes it.

## 1. The table: `<x-admin.table>`

**A list does not write `<table>`.** It draws `<x-admin.table>`. `tests/Unit/AdminTableTest.php` lists the views
that still write one by hand (51 on 19 November 2026, 45 once the first six lists had moved); the list may only get
shorter, and the screen pass (T-045) takes them area by area. PDF and mail templates keep their own tables.

```blade
<x-admin.table label="Quotations" :rows="$quotes">
    <x-slot:filters class="grid grid-cols-1 gap-3 md:grid-cols-4"> …search and choices… </x-slot:filters>
    <x-slot:head>
        <x-admin.table.heading :sort="['number' => 'descending']" :current="$sort">Quotation</x-admin.table.heading>
        <x-admin.table.heading align="right">Value</x-admin.table.heading>
        <x-admin.table.heading label="Actions" />
    </x-slot:head>
    @foreach ($quotes as $quote)
        <x-admin.table.row wire:key="quote-{{ $quote->id }}">
            <x-admin.table.cell header>…what names the row…</x-admin.table.cell>
            <x-admin.table.cell align="right" numeric>…</x-admin.table.cell>
        </x-admin.table.row>
    @endforeach
    <x-slot:empty> <x-admin.empty title="No quotations yet" description="…the way to make one…" /> </x-slot:empty>
</x-admin.table>
```

| Part | Rule |
|---|---|
| Name | `label` is required. It is the table's caption (read out, not shown) and is used in the words of each state |
| Headings | Every column has one. A column with nothing written above it (tick boxes, actions) has `label="…"` for a screen reader |
| The row's name | The cell that says what the row is has `header`: it is read out before the other cells of its row |
| Sorting | `:sort` takes the list's own words with the way each goes: `['newest' => 'descending', 'oldest' => 'ascending']`. The first is what one press gives; a second press takes the next. The list keeps its property (`$sort`) and its query; the component knows no word of any list. A sort that has no column stays in a choice above the table |
| Filters | The `filters` slot, above the table: sent as they change (`wire:model.live`), never behind a button |
| Pages | Hand the paginator to `:rows`. The page links are the shared ones (§5) |
| Choosing rows | `<x-admin.table.select-all>` in the headings, `<x-admin.table.select>` in each row, `:selected` on the table. What can be done with every chosen row goes in the `bulk` slot; it is drawn only while something is chosen, with the number chosen and "Clear selection". An action a person may not take is not offered |
| Narrow screens | Which columns a narrow screen drops is the screen's choice (`class="hidden md:table-cell"`). The page never scrolls sideways; what does not fit scrolls inside the table's own frame. Cards in place of a table on a phone are T-042 |
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
