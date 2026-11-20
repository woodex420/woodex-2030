<?php

declare(strict_types=1);

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\Task;
use App\Domain\System\Import\OldSystem\Convert;
use App\Domain\System\Import\OldSystem\Refs;
use App\Domain\System\Import\OldSystem\Refused;
use App\Domain\System\Import\OldSystem\Rules;
use App\Domain\System\Import\OldSystem\Shape;
use App\Domain\System\Import\OldSystem\State;
use App\Domain\System\Models\LegacyRef;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;
use Tests\Support\DatabaseServer;

/*
| Master list T-177: one rule for the numbers of records.
|
| The table `legacy_refs` keeps the old number of a record beside its new one. For the old system (the React shop
| and dashboard with their Node API) one class writes it: App\Domain\System\Import\OldSystem\Refs. These tests
| hold the rule that class follows, on SQLite in a sandbox and on MySQL 8.4, MariaDB 10.11 and MariaDB 11.4 in CI:
| the name a table is entered under, the key as one text, what the checksum is made of, how one old row that
| becomes two records is entered, and that nothing else writes the table.
*/

/**
 * Every file of the admin that names `legacy_refs` or its model, with what it does there. A file that names
 * either and is not listed here fails the last test of this file.
 *
 * "The six that came with the admin" write or delete entries of other old systems (the studio's older CRM, a
 * list of leads from a file, a website's pages and pictures), under names of their own.
 */
const WHO_NAMES_THE_TABLE = [
    'app/Domain/System/Models/LegacyRef.php' => 'the model',
    'database/migrations/2026_10_15_000100_create_system_tables.php' => 'creates the table',
    'app/Domain/System/Import/OldSystem/Refs.php' => 'writes: the one class of the old system',
    // The six that came with the admin.
    'app/Domain/System/Import/V21Importer.php' => 'writes: came with the admin',
    'app/Domain/System/Import/LeadCsvImporter.php' => 'writes: came with the admin',
    'app/Console/Commands/ImportWebsite.php' => 'writes: came with the admin',
    'app/Domain/Website/Import/ImportLegacyPage.php' => 'writes: came with the admin',
    'app/Domain/Website/Import/ImportLegacyMedia.php' => 'writes: came with the admin',
    'app/Console/Commands/ResetWebsite.php' => 'writes: came with the admin',
    // Found by this round beside the six: a command that only asks, and a migration that is kept out of use.
    'app/Console/Commands/ImportLegacy.php' => 'reads',
    'database/quarantine/interior-content/2026_10_17_000200_reload_the_complete_website.php' => 'writes: a migration kept out of use',
];

/**
 * Every name the importers that came with the admin enter rows under. `lead_csv_*` is `lead_csv_` and a year.
 * None begins with `node.`, the beginning of every name of the old system.
 */
const NAMES_OF_THE_OTHER_IMPORTERS = [
    'lead_csv_*', 'legacy-website', 'website-bundle', 'website-image', 'website-page', 'website-reload',
    'wx_bookings', 'wx_clients', 'wx_lead_notes', 'wx_leads', 'wx_users',
];

/** An enquiry as the old system holds it: the values as a PostgreSQL driver hands them over. */
function oldLead(array $with = []): array
{
    return [...[
        'id' => 17,
        'ref' => 'L-0017',
        'name' => 'Ayesha Khan',
        'phone' => '+92 300 1234567',
        'email' => 'ayesha@furniture.example',
        'status' => 'New',
        'value' => 148000.5,
        'notes' => null,
        'created_at' => '2026-11-08T20:54:28.341Z',
    ], ...$with];
}

/** One run of an importer over one enquiry: the person becomes a contact, the enquiry a lead of that contact. */
function bringLeadOver(Refs $refs, array $row): array
{
    $made = 0;

    $contact = $refs->bring('leads', $row, 'contacts', function () use (&$made, $row): Contact {
        $made++;

        return Contact::factory()->create(['name' => $row['name'], 'email' => $row['email']]);
    });

    $lead = $refs->bring('leads', $row, 'leads', function () use (&$made, $refs, $row): Lead {
        $made++;

        return Lead::factory()->create(['contact_id' => $refs->recordOf('leads', $row['id'], 'contacts')]);
    });

    return ['contact' => $contact, 'lead' => $lead, 'made' => $made];
}

/** Does this source write to the table: through the model, the query builder, or SQL of its own? */
function writesToTheTable(string $source): bool
{
    $writing = 'create|createMany|createQuietly|forceCreate|firstOrCreate|firstOrNew|updateOrCreate|updateOrInsert|update|upsert|insert|insertOrIgnore|insertGetId|insertUsing|delete|forceDelete|truncate|save|saveQuietly|increment|decrement|restore|fill|forceFill';

    // Every statement that starts at the model or at the table's name, up to its semicolon.
    preg_match_all('/(?:LegacyRef::|new\s+LegacyRef\b|[\'"]legacy_refs[\'"])[^;]*;/s', $source, $statements);

    foreach ($statements[0] as $statement) {
        if (preg_match('/(?:::|->)\s*(?:'.$writing.')\s*\(|new\s+LegacyRef\b|Schema::(?:create|table|drop)/', $statement) === 1) {
            return true;
        }
    }

    return preg_match('/\b(?:insert\s+(?:ignore\s+)?into|update|delete\s+from|replace\s+into|truncate(?:\s+table)?)\s+`?legacy_refs\b/i', $source) === 1;
}

/* ---------- once ---------- */

it('brings a row over once: the second run makes no second record and writes nothing', function () {
    $refs = new Refs;
    $row = oldLead();
    $before = ['contacts' => Contact::query()->count(), 'leads' => Lead::query()->count(), 'entries' => LegacyRef::query()->count()];

    $this->travelTo('2026-11-10 09:00:00');
    $first = bringLeadOver($refs, $row);

    // One old row became two records, and each has its entry: the same key under two names.
    expect($first['made'])->toBe(2)
        ->and($first['contact']->state)->toBe(State::New)
        ->and($first['lead']->state)->toBe(State::New)
        ->and(Contact::query()->count())->toBe($before['contacts'] + 1)
        ->and(Lead::query()->count())->toBe($before['leads'] + 1)
        ->and(LegacyRef::query()->count())->toBe($before['entries'] + 2);

    $entries = fn () => LegacyRef::query()->where('legacy_table', 'like', 'node.%')->orderBy('legacy_table')
        ->get(['legacy_table', 'legacy_id', 'model_type', 'model_id', 'checksum', 'imported_at'])->toArray();
    $written = $entries();

    expect(array_column($written, 'legacy_table'))->toBe(['node.leads>contacts', 'node.leads>leads'])
        ->and(array_column($written, 'legacy_id'))->toBe(['17', '17'])
        ->and(array_column($written, 'model_type'))->toBe(['contacts', 'leads'])
        ->and(array_column($written, 'model_id'))->toBe([$first['contact']->record, $first['lead']->record])
        ->and(array_column($written, 'checksum'))->toBe([Refs::checksum($row), Refs::checksum($row)])
        ->and(Lead::query()->findOrFail($first['lead']->record)->contact_id)->toBe($first['contact']->record);

    // The second run, a day later. Every statement it sends is recorded.
    $this->travelTo('2026-11-11 09:00:00');
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower(ltrim($query->sql));
    });
    $second = bringLeadOver($refs, $row);
    $writes = array_values(array_filter($statements, fn (string $sql): bool => preg_match('/^(insert|update|delete|replace|truncate)\b/', $sql) === 1));

    expect($second['made'])->toBe(0)
        ->and($second['contact']->state)->toBe(State::Same)
        ->and($second['lead']->state)->toBe(State::Same)
        ->and($second['contact']->record)->toBe($first['contact']->record)
        ->and($second['lead']->record)->toBe($first['lead']->record)
        // It asked, and it wrote nothing: not a record, not an entry, not a time.
        ->and($statements)->not->toBeEmpty()
        ->and($writes)->toBe([])
        ->and(Contact::query()->count())->toBe($before['contacts'] + 1)
        ->and(Lead::query()->count())->toBe($before['leads'] + 1)
        ->and($entries())->toBe($written);
});

it('sees a row whose content changed as changed, until somebody has dealt with it', function () {
    $refs = new Refs;
    $row = oldLead();
    $first = bringLeadOver($refs, $row);
    $changed = oldLead(['status' => 'Contacted']);

    // Asking writes nothing and says: brought over, and changed since.
    $asked = $refs->ask('leads', $changed, 'leads');
    expect($asked->state)->toBe(State::Changed)
        ->and($asked->changed())->toBeTrue()
        ->and($asked->isNew())->toBeFalse()
        ->and($asked->record)->toBe($first['lead']->record)
        ->and($asked->name)->toBe('node.leads>leads')
        ->and($asked->key)->toBe('17')
        ->and($refs->ask('leads', $row, 'leads')->state)->toBe(State::Same)
        ->and($refs->ask('leads', oldLead(['id' => 18]), 'leads')->state)->toBe(State::New);

    // Every kind of change is seen: a value, a value that became nothing, nothing that became an empty text.
    foreach ([['value' => 148000.51], ['email' => null], ['notes' => ''], ['name' => 'Ayesha  Khan'], ['created_at' => '2026-11-08T20:54:28.342Z']] as $change) {
        expect($refs->ask('leads', oldLead($change), 'leads')->state)->toBe(State::Changed, json_encode($change));
    }

    // Brought again without saying what to do with a changed row: nothing is made, nothing is written, and it
    // is still seen as changed on the next run. A change is never passed over quietly.
    $made = 0;
    $again = $refs->bring('leads', $changed, 'leads', function () use (&$made): Lead {
        $made++;

        return Lead::factory()->create();
    });
    expect($again->state)->toBe(State::Changed)
        ->and($made)->toBe(0)
        ->and($refs->ask('leads', $changed, 'leads')->state)->toBe(State::Changed);

    // Brought again with what to do: it is called once, with the number of the record, and the entry takes the
    // new checksum. The next run finds it the same; the old content is now the one that differs.
    $this->travelTo('2026-11-12 10:00:00');
    $updated = [];
    $dealt = $refs->bring('leads', $changed, 'leads', fn () => throw new LogicException('a second record'), function (int $record) use (&$updated): void {
        $updated[] = $record;
        Lead::query()->whereKey($record)->update(['message' => 'Status in the old system: Contacted']);
    });

    expect($dealt->state)->toBe(State::Changed)
        ->and($updated)->toBe([$first['lead']->record])
        ->and(Lead::query()->findOrFail($first['lead']->record)->message)->toBe('Status in the old system: Contacted')
        ->and($refs->ask('leads', $changed, 'leads')->state)->toBe(State::Same)
        ->and($refs->ask('leads', $row, 'leads')->state)->toBe(State::Changed)
        ->and(LegacyRef::query()->where('legacy_table', 'node.leads>leads')->count())->toBe(1)
        ->and(LegacyRef::query()->where('legacy_table', 'node.leads>leads')->value('imported_at')->format('Y-m-d H:i'))->toBe('2026-11-12 10:00')
        // The contact's entry was not touched: each record of the row is dealt with on its own.
        ->and($refs->ask('leads', $changed, 'contacts')->state)->toBe(State::Changed);
});

it('makes the checksum of the row as it was read, whatever the order of its columns', function () {
    $row = oldLead();
    $shuffled = array_reverse($row, true);

    expect(Refs::checksum($shuffled))->toBe(Refs::checksum($row))
        ->and(strlen(Refs::checksum($row)))->toBe(64)
        // A whole number and its digits are the same value; a driver may hand over either.
        ->and(Refs::checksum(oldLead(['id' => '17'])))->toBe(Refs::checksum($row))
        // Nothing is not an empty text, and not the word.
        ->and(Refs::checksum(oldLead(['notes' => ''])))->not->toBe(Refs::checksum($row))
        ->and(Refs::checksum(oldLead(['notes' => 'null'])))->not->toBe(Refs::checksum($row))
        // A column more is a change.
        ->and(Refs::checksum([...$row, 'city' => null]))->not->toBe(Refs::checksum($row))
        // Urdu, an emoji and a line break are part of the content.
        ->and(Refs::checksum(oldLead(['name' => 'عائشہ خان 🪑'])))->not->toBe(Refs::checksum(oldLead(['name' => 'عائشہ خان'])))
        // Yes and no are not 1 and 0: a column that changed its type is a change.
        ->and(Refs::checksum(['id' => 1, 'active' => true]))->not->toBe(Refs::checksum(['id' => 1, 'active' => 1]));

    // A list is handed over as the text the old database holds, not as an array somebody already decoded.
    expect(fn () => Refs::checksum(['id' => 1, 'items' => ['desk']]))->toThrow(Refused::class, 'hand a list over as the text')
        ->and(fn () => Refs::checksum(['id' => 1, 'value' => NAN]))->toThrow(Refused::class);
});

it('never leaves a record without its entry, nor an entry without its record', function () {
    $refs = new Refs;
    $row = oldLead();
    $contacts = Contact::query()->count();

    // What made the record fails afterwards: the record is taken back, and no entry is written.
    expect(function () use ($refs, $row): void {
        $refs->bring('leads', $row, 'contacts', function (): Contact {
            Contact::factory()->create(['name' => 'Half made']);

            throw new RuntimeException('the importer failed after it made the record');
        });
    })->toThrow(RuntimeException::class, 'failed after it made the record');

    expect(Contact::query()->count())->toBe($contacts)
        ->and(LegacyRef::query()->where('legacy_table', 'like', 'node.%')->count())->toBe(0);

    // Another run brought the same row over at the same moment: the unique index refuses the second entry, what
    // this run made is taken back, and the refusal says so.
    try {
        $refs->bring('leads', $row, 'contacts', function (): Contact {
            LegacyRef::query()->create(['legacy_table' => 'node.leads>contacts', 'legacy_id' => '17', 'model_type' => 'contacts', 'model_id' => 1, 'imported_at' => now()]);

            return Contact::factory()->create(['name' => 'Made twice']);
        });
        $this->fail('A second entry for one row was written.');
    } catch (Refused $refused) {
        expect($refused->what)->toBe('key')->and($refused->why)->toContain('`node.leads>contacts` already holds this key');
    }

    expect(Contact::query()->where('name', 'Made twice')->count())->toBe(0)
        ->and(Contact::query()->count())->toBe($contacts);

    // A record of another table than the one that was named, and a record without a number, are refused.
    expect(fn () => $refs->bring('leads', oldLead(['id' => 30]), 'contacts', fn () => Lead::factory()->create()))
        ->toThrow(Refused::class, 'is in `leads`, not in `contacts`')
        ->and(fn () => $refs->bring('leads', oldLead(['id' => 31]), 'contacts', fn () => 0))
        ->toThrow(Refused::class, 'has no number');

    expect(LegacyRef::query()->where('legacy_table', 'like', 'node.%')->count())->toBe(0);
});

/* ---------- names ---------- */

it('enters two old tables with the same key under two names, apart from every other importer', function () {
    $refs = new Refs;

    // The studio's older CRM has a lead 17 as well, entered the way its importer enters it.
    $studio = Lead::factory()->create();
    LegacyRef::query()->create(['legacy_table' => 'wx_leads', 'legacy_id' => '17', 'model_type' => 'lead', 'model_id' => $studio->id, 'imported_at' => now()]);

    // This old system: the enquiry 17 and the task 17.
    $lead = bringLeadOver($refs, oldLead())['lead'];
    $task = $refs->bring('tasks', ['id' => 17, 'title' => 'Call the architect', 'priority' => 'high'], 'tasks',
        fn () => Task::query()->create(['title' => 'Call the architect', 'priority' => Convert::word('tasks', 'priority', 'high')]));

    expect($lead->name)->toBe('node.leads>leads')
        ->and($task->name)->toBe('node.tasks>tasks')
        ->and($lead->key)->toBe('17')
        ->and($task->key)->toBe('17')
        // Four entries hold the key 17, and none of them met another.
        ->and(LegacyRef::query()->where('legacy_id', '17')->orderBy('legacy_table')->pluck('legacy_table')->all())
        ->toBe(['node.leads>contacts', 'node.leads>leads', 'node.tasks>tasks', 'wx_leads'])
        // Each question gets its own answer.
        ->and($refs->recordOf('leads', 17, 'leads'))->toBe($lead->record)
        ->and($refs->recordOf('leads', 17, 'leads'))->not->toBe($studio->id)
        ->and($refs->recordOf('tasks', 17, 'tasks'))->toBe($task->record)
        ->and($refs->recordOf('tasks', '17', 'tasks'))->toBe($task->record)
        ->and($refs->recordOf('tasks', 18, 'tasks'))->toBeNull()
        ->and($refs->recordOf('site_visits', 17, 'site_visits'))->toBeNull();
});

it('lists every name in use: those of the old system all begin with node., and no other importer uses that', function () {
    // This system's names, derived from the table's name and from the map of the tables.
    $names = Refs::names();
    $moving = array_filter(Shape::tables(), fn (array $shape): bool => $shape['fate'] === 'moves');
    $targets = array_sum(array_map(fn (array $shape): int => count($shape['to']), $moving));

    expect($names)->toHaveCount($targets - count(Rules::lines()))
        ->and(array_unique($names))->toHaveCount(count($names))
        ->and($names)->toContain('node.leads>leads', 'node.leads>contacts', 'node.clients>contacts', 'node.contacts>contacts',
            'node.meetings>tasks', 'node.meetings>activities', 'node.client_links>quotes', 'node.payments>payment_allocations',
            'node.suppliers>vendors', 'node.accounts>chart_of_accounts')
        ->and($names)->not->toContain('node.quotes>quote_items', 'node.invoices>invoice_items');

    foreach ($names as $name) {
        expect($name)->toStartWith(Refs::SYSTEM)
            ->and(strlen($name))->toBeLessThanOrEqual(Refs::NAME_LENGTH, $name)
            ->and(preg_match('/^node\.[a-z_]+>[a-z_]+$/', $name))->toBe(1, $name);
    }

    // The names of the importers that came with the admin, read out of their code.
    $found = [];
    $unread = [];

    foreach (WHO_NAMES_THE_TABLE as $path => $role) {
        if (! str_contains($role, 'came with the admin') && ! str_contains($role, 'kept out of use') && $role !== 'reads') {
            continue;
        }

        $source = (string) file_get_contents(base_path($path));

        // The beginning of this system's names stands in none of them.
        expect(str_contains($source, "'".Refs::SYSTEM) || str_contains($source, '"'.Refs::SYSTEM))->toBeFalse($path);

        // A name written out where the table's column is named, or kept in a constant, or handed to the private
        // helper of the studio's importer.
        preg_match_all('/\'legacy_table\'\s*(?:,|=>)\s*\'([^\']+)\'\s*(\.)?/', $source, $direct, PREG_SET_ORDER);
        preg_match_all('/const\s+(?:string\s+)?(?:TABLE|MARKER)\s*=\s*\'([^\']+)\'/', $source, $constants);
        preg_match_all('/const\s+(?:array\s+)?(TABLES|IMPORT_REFS)\s*=\s*\[(.*?)\];/s', $source, $lists, PREG_SET_ORDER);
        preg_match_all('/->(?:ref|remember)\(\s*\'([^\']+)\'/', $source, $calls);

        foreach ($direct as $match) {
            $found[] = $match[1].(($match[2] ?? '') === '.' ? '*' : '');
        }
        foreach ($lists as $match) {
            // In a list of `step => name` the name is the value.
            preg_match_all($match[1] === 'TABLES' ? '/=>\s*\'([^\']+)\'/' : '/\'([^\']+)\'/', $match[2], $values);
            $found = [...$found, ...$values[1]];
        }
        $found = [...$found, ...$constants[1], ...$calls[1]];

        // Every place that names the column is one this test could read: a name written out, or one of the
        // constants above, or the parameter of the studio's private helper.
        preg_match_all('/\'legacy_table\'\s*(?:,|=>)\s*([^,\]\)]+)/', $source, $places);
        foreach ($places[1] as $place) {
            if (preg_match('/^(\'[^\']+\'(\s*\.\s*\$year)?|self::(TABLE|MARKER|IMPORT_REFS)|\$table)$/', trim($place)) !== 1) {
                $unread[] = "{$path}: `legacy_table` is given `".trim($place).'`, which this test cannot read';
            }
        }
    }

    $found = array_values(array_unique($found));
    sort($found);

    expect($unread)->toBe([])
        ->and($found)->toBe(NAMES_OF_THE_OTHER_IMPORTERS);

    foreach (NAMES_OF_THE_OTHER_IMPORTERS as $other) {
        expect(str_starts_with($other, Refs::SYSTEM))->toBeFalse($other)
            ->and(in_array(rtrim($other, '*'), $names, true))->toBeFalse($other);
    }
});

it('refuses a name the map does not give, and says why', function () {
    expect(fn () => Refs::name('orders', 'orders'))->toThrow(Refused::class, 'it waits for T-106')
        ->and(fn () => Refs::name('users', 'users'))->toThrow(Refused::class, 'stay in the old system')
        ->and(fn () => Refs::name('wishlists', 'wishlists'))->toThrow(Refused::class, 'no table of this name')
        ->and(fn () => Refs::name('leads', 'quotes'))->toThrow(Refused::class, 'sends the rows of `leads` into leads, contacts')
        // The lines of a quotation get no entry of their own: the entry of the quotation answers for them.
        ->and(fn () => Refs::name('quotes', 'quote_items'))->toThrow(Refused::class, 'the entry of the quotation answers for them')
        ->and(fn () => Refs::name('invoices', 'invoice_items'))->toThrow(Refused::class, 'the entry of the invoice answers for them');

    // Asking and bringing go through the same names: a table that waits is not brought over by accident.
    $refs = new Refs;
    expect(fn () => $refs->ask('orders', ['id' => 1], 'orders'))->toThrow(Refused::class, 'it waits for T-106')
        ->and(fn () => $refs->bring('products', ['id' => 'executive-desk'], 'commerce_products', fn () => 1))->toThrow(Refused::class, 'stay in the old system')
        ->and(fn () => $refs->recordOf('users', 1, 'users'))->toThrow(Refused::class, 'stay in the old system');

    foreach (Rules::lines() as $rule) {
        // What the file calls the lines of a row is a table the map sends that row into.
        expect(Shape::table($rule['from'])['to'])->toContain($rule['into'])
            ->and(trim($rule['why']))->not->toBe('');
    }
});

/* ---------- keys ---------- */

it('writes a key of two columns as one text, the same on every run', function () {
    // `client_links`: which record a client is linked to. Its key is the kind of record and the record's number.
    $link = ['client_id' => 4, 'entity' => 'lead', 'entity_id' => 17];

    expect(Refs::key('client_links', $link))->toBe('lead:17')
        ->and(Refs::key('client_links', $link))->toBe('lead:17')
        // The order in which the row's columns were read does not matter: the key's own order does.
        ->and(Refs::key('client_links', ['entity_id' => '17', 'entity' => 'lead', 'client_id' => 9]))->toBe('lead:17')
        ->and(Refs::keyOf('client_links', ['lead', 17]))->toBe('lead:17')
        // `stock_levels`: a product's code and a place. A text first, then a number.
        ->and(Refs::keyOf('stock_levels', ['executive-desk-walnut', 3]))->toBe('executive-desk-walnut:3')
        // A key of one column.
        ->and(Refs::key('leads', ['id' => 17, 'name' => 'Ayesha Khan']))->toBe('17')
        ->and(Refs::keyOf('leads', '9223372036854775807'))->toBe('9223372036854775807')
        ->and(Refs::keyOf('quote_templates', 'qt-1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed'))->toBe('qt-1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed');

    // The key of every table of the old system is one this rule can write.
    foreach (Shape::tables() as $table => $shape) {
        expect(count($shape['key']))->toBeIn([1, 2], $table);
        foreach ($shape['key'] as [$column, $kind]) {
            expect($kind)->toBeIn(['number', 'text'], "{$table}.{$column}");
        }
    }

    expect(fn () => Refs::key('client_links', ['client_id' => 4, 'entity' => 'lead']))->toThrow(Refused::class, 'without this column of its key')
        ->and(fn () => Refs::keyOf('client_links', 'lead'))->toThrow(Refused::class, 'its key has 2 column(s), and 1 value(s) were given')
        ->and(fn () => Refs::keyOf('leads', [17, 18]))->toThrow(Refused::class, 'its key has 1 column(s), and 2 value(s) were given')
        // One number has one writing: 17, never 017, +17, 17.0 or " 17".
        ->and(fn () => Refs::keyOf('leads', '017'))->toThrow(Refused::class, 'not a whole number')
        ->and(fn () => Refs::keyOf('leads', '17.0'))->toThrow(Refused::class, 'not a whole number')
        ->and(fn () => Refs::keyOf('leads', ' 17'))->toThrow(Refused::class, 'not a whole number')
        ->and(fn () => Refs::keyOf('leads', '+17'))->toThrow(Refused::class, 'not a whole number')
        ->and(fn () => Refs::key('leads', ['id' => 17.0]))->toThrow(Refused::class, 'not a whole number')
        ->and(fn () => Refs::key('leads', ['id' => null]))->toThrow(Refused::class, 'not a whole number')
        ->and(fn () => Refs::keyOf('leads', ''))->toThrow(Refused::class, 'not a whole number')
        ->and(fn () => Refs::keyOf('quote_templates', ''))->toThrow(Refused::class, 'an empty one')
        ->and(fn () => Refs::keyOf('quote_templates', 17))->toThrow(Refused::class, 'not a text');
});

it('never writes two different keys as the same text, also when a value holds the sign that joins them', function () {
    // Texts chosen to meet if anything could: the joining sign, the sign the rule escapes with, upper and lower
    // case, an accent, a space at either end, signs a database may pass over, Urdu, and a byte that is nothing.
    $texts = ['a', 'A', 'á', 'a ', ' a', 'a:1', 'a:', ':a', ':', 'a%3a1', 'a%3A1', '%', 'a%', 'ab', 'a-b', 'a_b', 'a.b', 'a b', 'ss', 'ß',
        "a\tb", "a\0", "a\n", 'ａ', 'desk', 'Desk', 'DESK', 'دفتر', 'دَفتر', '1', '12', '🪑'];
    $numbers = [0, 1, 2, 7, 12, 27, -1];
    $written = [];

    foreach ($texts as $text) {
        foreach ($numbers as $number) {
            $key = Refs::keyOf('stock_levels', [$text, $number]);

            // Only letters the rule allows: nothing in it that one of the admin's databases compares loosely.
            expect(preg_match('/^[a-z0-9._%-]+:-?[0-9]+$/D', $key))->toBe(1, $key)
                // The same text on every run.
                ->and(Refs::keyOf('stock_levels', [$text, $number]))->toBe($key);

            // And it reads back: the part before the last joining sign is the text, letter for letter.
            $at = strrpos($key, ':');
            expect(rawurldecode(substr($key, 0, $at)))->toBe($text)
                ->and((int) substr($key, $at + 1))->toBe($number);

            $written[] = $key;
        }
    }

    // No two of them are the same text…
    expect(array_unique($written))->toHaveCount(count($texts) * count($numbers))
        // …also not as the admin's database compares texts, by the rule this system uses to foresee it…
        ->and(array_unique(array_map(Convert::asCompared(...), $written)))->toHaveCount(count($written))
        // …and the two that a plain joining would make one are two.
        ->and(Refs::keyOf('stock_levels', ['1', 27]))->toBe('1:27')
        ->and(Refs::keyOf('stock_levels', ['12', 7]))->toBe('12:7')
        ->and(Refs::keyOf('stock_levels', ['a:1', 2]))->toBe('a%3a1:2')
        ->and(Refs::keyOf('stock_levels', ['a%3a1', 2]))->toBe('a%253a1:2')
        ->and(Refs::keyOf('stock_levels', ['Desk', 1]))->toBe('%44esk:1')
        ->and(Refs::keyOf('stock_levels', ['desk', 1]))->toBe('desk:1');

    // The database itself, whichever of the four it is, holds every one of them apart: all are written under one
    // name, the unique index refuses none, and it counts as many different keys as were written.
    foreach (array_chunk($written, 100) as $chunk) {
        LegacyRef::query()->insert(array_map(fn (string $key): array => ['legacy_table' => 'node.test>keys', 'legacy_id' => $key, 'model_type' => 'tasks', 'model_id' => 1], $chunk));
    }

    expect(LegacyRef::query()->where('legacy_table', 'node.test>keys')->count())->toBe(count($written))
        ->and((int) DB::table('legacy_refs')->where('legacy_table', 'node.test>keys')->distinct()->count('legacy_id'))->toBe(count($written));

    foreach (['a:1', '%41:1', 'a%20:1', '%c3%a1:1'] as $key) {
        expect(LegacyRef::query()->where('legacy_table', 'node.test>keys')->where('legacy_id', $key)->count())->toBe(1, $key);
    }
});

it('pins why the key is written so: what each server does with two keys that differ only in case', function () {
    // The old database holds `Desk` and `desk` to be two keys. Written as they are, MySQL and MariaDB hold them to
    // be one and refuse the second; SQLite, on which a sandbox runs, takes both and would hide it.
    LegacyRef::query()->create(['legacy_table' => 'node.test>raw', 'legacy_id' => 'desk', 'model_type' => 'tasks', 'model_id' => 1]);
    $second = fn () => LegacyRef::query()->create(['legacy_table' => 'node.test>raw', 'legacy_id' => 'Desk', 'model_type' => 'tasks', 'model_id' => 2]);

    if (DatabaseServer::isMySqlFamily()) {
        expect($second)->toThrow(QueryException::class)
            ->and(LegacyRef::query()->where('legacy_table', 'node.test>raw')->where('legacy_id', 'DESK')->count())->toBe(1);
    } else {
        $second();
        expect(LegacyRef::query()->where('legacy_table', 'node.test>raw')->count())->toBe(2);
    }

    // As the rule writes them, they are two on every server.
    foreach (['desk', 'Desk'] as $number => $text) {
        LegacyRef::query()->create(['legacy_table' => 'node.test>ruled', 'legacy_id' => Refs::written($text, 'text'), 'model_type' => 'tasks', 'model_id' => $number + 1]);
    }

    expect(LegacyRef::query()->where('legacy_table', 'node.test>ruled')->orderBy('model_id')->pluck('legacy_id')->all())->toBe(['desk', '%44esk']);
});

it('keeps a text key of 64 characters whole and refuses a longer one by name before the database is asked', function () {
    $refs = new Refs;
    $task = Task::query()->create(['title' => 'A record to point at']);

    // `quote_templates` has a text for its key. The old API makes it of `qt-` and 36 characters; 64 is the most
    // the column holds.
    $whole = 'qt-'.str_repeat('0123456789abcdef-', 3).'0123456789';
    expect(strlen($whole))->toBe(64);

    $answer = $refs->bring('quote_templates', ['id' => $whole, 'name' => 'Office fit-out'], 'quote_templates', fn () => $task->id);
    $held = LegacyRef::query()->where('legacy_table', 'node.quote_templates>quote_templates')->sole();

    expect($answer->key)->toBe($whole)
        ->and($held->legacy_id)->toBe($whole)
        ->and(strlen($held->legacy_id))->toBe(64)
        ->and($refs->recordOf('quote_templates', $whole, 'quote_templates'))->toBe($task->id)
        ->and($refs->ask('quote_templates', ['id' => $whole, 'name' => 'Office fit-out'], 'quote_templates')->state)->toBe(State::Same);

    // One character more is refused by name, and nothing is written or made.
    $long = $whole.'a';
    $made = 0;

    try {
        $refs->bring('quote_templates', ['id' => $long, 'name' => 'Office fit-out'], 'quote_templates', function () use (&$made, $task): int {
            $made++;

            return $task->id;
        });
        $this->fail('A key of 65 characters was taken.');
    } catch (Refused $refused) {
        expect($refused->what)->toBe('key')
            ->and($refused->why)->toBe('the key of `quote_templates` is 65 characters as it is written, and `legacy_refs.legacy_id` holds 64');
    }

    expect($made)->toBe(0)
        ->and(LegacyRef::query()->where('legacy_table', 'node.quote_templates>quote_templates')->count())->toBe(1)
        ->and(fn () => $refs->ask('quote_templates', ['id' => $long], 'quote_templates'))->toThrow(Refused::class, '65 characters')
        ->and(fn () => $refs->recordOf('quote_templates', $long, 'quote_templates'))->toThrow(Refused::class, '65 characters')
        // What counts is the key as it is written: a capital letter takes three characters there.
        ->and(fn () => Refs::keyOf('quote_templates', 'Q'.substr($whole, 1)))->toThrow(Refused::class, 'is 66 characters as it is written')
        // A key of two columns is measured whole, with its joining sign.
        ->and(Refs::keyOf('stock_levels', [str_repeat('a', 62), 3]))->toBe(str_repeat('a', 62).':3')
        ->and(fn () => Refs::keyOf('stock_levels', [str_repeat('a', 63), 3]))->toThrow(Refused::class, 'is 65 characters as it is written');

    // Why the rule refuses it itself: what each server does with 65 characters is not the same. MySQL and MariaDB,
    // as the admin sets them up, refuse them with an error of their own; SQLite keeps all 65, and a server that
    // was not set up strictly would cut the key and say nothing.
    $write = fn () => LegacyRef::query()->create(['legacy_table' => 'node.test>long', 'legacy_id' => $long, 'model_type' => 'tasks', 'model_id' => 1]);

    if (DatabaseServer::isMySqlFamily()) {
        expect($write)->toThrow(QueryException::class)
            ->and(LegacyRef::query()->where('legacy_table', 'node.test>long')->count())->toBe(0);
    } else {
        expect(strlen($write()->fresh()->legacy_id))->toBe(65);
    }
});

/* ---------- nothing else writes the table ---------- */

it('reads every file of the admin: none names the table of the old numbers but the listed ones', function () {
    $files = Finder::create()->files()->name('*.php')
        ->in(array_map(base_path(...), ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'tools', 'api']))
        ->exclude('cache');

    $found = [];
    $read = 0;

    foreach ($files as $file) {
        $read++;

        if (preg_match('/legacy_refs|LegacyRef\b/', $file->getContents()) === 1) {
            $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
        }
    }

    sort($found);
    $listed = array_keys(WHO_NAMES_THE_TABLE);
    sort($listed);

    // A file that names the table and is not listed is named here. An importer of the old system goes through
    // App\Domain\System\Import\OldSystem\Refs and has no reason to name the table.
    expect(array_values(array_diff($found, $listed)))->toBe([])
        // A listed file that no longer names it is taken off the list.
        ->and(array_values(array_diff($listed, $found)))->toBe([])
        ->and($read)->toBeGreaterThan(900);

    foreach (WHO_NAMES_THE_TABLE as $path => $role) {
        if ($role === 'the model' || $role === 'creates the table') {
            continue;
        }

        // What the list says a file does is what the file does: the one that only asks does not write.
        expect(writesToTheTable((string) file_get_contents(base_path($path))))->toBe(str_starts_with($role, 'writes'), "{$path}: listed as \"{$role}\"");
    }

    // The six that came with the admin are six, and this system has one class.
    expect(array_filter(WHO_NAMES_THE_TABLE, fn (string $role): bool => $role === 'writes: came with the admin'))->toHaveCount(6)
        ->and(array_filter(WHO_NAMES_THE_TABLE, fn (string $role): bool => str_contains($role, 'the one class')))->toHaveCount(1);
});

it('knows a file that writes the table when it sees one', function (string $source, bool $writes) {
    expect(writesToTheTable($source))->toBe($writes);
})->with([
    'the model, created' => ['LegacyRef::query()->create([\'legacy_table\' => \'x\']);', true],
    'the model, created in one call' => ['LegacyRef::create([\'legacy_table\' => \'x\']);', true],
    'the model, updated or created' => ["LegacyRef::query()\n    ->updateOrCreate(['legacy_table' => 'x'], []);", true],
    'the model, deleted' => ['LegacyRef::query()->where(\'legacy_table\', \'x\')->delete();', true],
    'the model, made and saved' => ['$ref = new LegacyRef; $ref->save();', true],
    'the query builder' => ['DB::table(\'legacy_refs\')->insert([\'legacy_table\' => \'x\']);', true],
    'the query builder, updated' => ['DB::table("legacy_refs")->where(\'id\', 1)->update([\'checksum\' => null]);', true],
    'SQL of its own' => ['DB::statement("INSERT INTO legacy_refs (legacy_table) VALUES (?)", [\'x\']);', true],
    'SQL that deletes' => ['DB::delete(\'delete from `legacy_refs` where id = 1\');', true],
    'a question' => ['$exists = LegacyRef::query()->where(\'legacy_table\', \'x\')->exists();', false],
    'a value' => ['$id = LegacyRef::query()->where(\'legacy_id\', $key)->value(\'model_id\');', false],
    'the first entry' => ['$ref = LegacyRef::query()->where(\'legacy_table\', self::TABLE)->first();', false],
    'a count through the query builder' => ['$count = DB::table(\'legacy_refs\')->count();', false],
    'a word in a comment' => ['// Idempotent through legacy_refs: a second run skips what the first brought over.', false],
]);
