<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\LeadPriority;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\Task;
use App\Domain\System\Import\OldSystem\Convert;
use App\Domain\System\Import\OldSystem\Refused;
use App\Domain\System\Import\OldSystem\Rules;
use App\Domain\System\Import\OldSystem\Shape;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DatabaseServer;

/*
| Master list T-176: what differs between the old system's database and the admin's.
|
| The old system (the React shop and dashboard with their Node API) keeps its records in PostgreSQL and was written
| for JavaScript. The admin keeps them in MySQL or MariaDB. Each test here takes a value as the old system holds
| it, sends it through App\Domain\System\Import\OldSystem\Convert, writes it to a table of the admin and reads it
| back. It runs on SQLite in a sandbox and, in CI, on MySQL 8.4, MariaDB 10.11 and MariaDB 11.4.
|
| Where the servers themselves disagree, the test pins what each one does (Tests\Support\DatabaseServer says which
| one is answering) and then shows that the conversion gives one answer on all of them.
*/

/** `2026-11-08T20:54:28Z`, in seconds since 1970. */
const OLD_MOMENT = 1794171268;

/** The start of 8 November 2026 in Pakistan (7 November, 19:00 UTC), in seconds since 1970. */
const OLD_DAY = 1794078000;

/** The tables of the admin that receive rows of the old system by the map (docs/woodex-os/acceptance/tables.json). */
function oldSystemTargets(): array
{
    $targets = [];

    foreach (Shape::tables() as $shape) {
        if ($shape['fate'] === 'moves') {
            $targets = [...$targets, ...$shape['to']];
        }
    }

    $targets = array_values(array_unique($targets));
    sort($targets);

    return $targets;
}

/* ---------- times ---------- */

it('keeps a time of the old system as the same moment on every server and shows it in Pakistan time', function () {
    // As the old API writes a time into a text column, and as it writes a day that is due.
    $moment = Convert::time('2026-11-08T20:54:28.341Z');
    $day = Convert::time('2026-11-08');

    expect($moment->getTimestamp())->toBe(OLD_MOMENT)
        ->and($moment->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-08 20:54:28')
        // What comes back is the workspace's wall clock: that is what Eloquent and the query builder write.
        ->and($moment->timezoneName)->toBe('Asia/Karachi')
        ->and($moment->format('Y-m-d H:i:s'))->toBe('2026-11-09 01:54:28')
        // A bare day is the start of that day in Pakistan.
        ->and($day->getTimestamp())->toBe(OLD_DAY)
        ->and($day->format('Y-m-d H:i:s'))->toBe('2026-11-08 00:00:00')
        // PostgreSQL's own writing of a time, and another zone, are the same moment.
        ->and(Convert::time('2026-11-08 20:54:28.341+00')->getTimestamp())->toBe(OLD_MOMENT)
        ->and(Convert::time('2026-11-09T01:54:28+05:00')->getTimestamp())->toBe(OLD_MOMENT)
        ->and(Convert::time('2026-11-09T02:24:28+0530')->getTimestamp())->toBe(OLD_MOMENT)
        // The part of a second is dropped, never rounded up into the next second.
        ->and(Convert::time('2026-11-08T20:54:28.999Z')->getTimestamp())->toBe(OLD_MOMENT);

    // Written through a model, as an importer writes a record…
    $task = Task::query()->create(['title' => 'Confirm the veneer swatch', 'due_at' => $day]);
    $task->created_at = $moment;
    $task->save();
    // …and through the query builder.
    $second = DB::table('tasks')->insertGetId(['title' => 'Send the drawing', 'due_at' => $day, 'created_at' => $moment]);

    foreach ([$task->id, $second] as $id) {
        $read = Task::query()->findOrFail($id);

        expect($read->created_at->getTimestamp())->toBe(OLD_MOMENT)
            ->and($read->due_at->getTimestamp())->toBe(OLD_DAY)
            // Shown in Pakistan time on a screen…
            ->and($read->created_at->format('Y-m-d H:i'))->toBe('2026-11-09 01:54')
            ->and($read->due_at->format('Y-m-d H:i'))->toBe('2026-11-08 00:00')
            // …and sent as UTC to a program.
            ->and($read->toArray()['created_at'])->toBe('2026-11-08T20:54:28.000000Z');

        if (DatabaseServer::isMySqlFamily()) {
            // The server itself holds that moment: seconds since 1970 know no time zone.
            $held = DB::selectOne('select unix_timestamp(created_at) as created, unix_timestamp(due_at) as due from tasks where id = ?', [$id]);
            expect((int) $held->created)->toBe(OLD_MOMENT)->and((int) $held->due)->toBe(OLD_DAY);
        } else {
            // SQLite keeps the workspace's wall clock as text.
            expect(DB::table('tasks')->where('id', $id)->value('created_at'))->toBe('2026-11-09 01:54:28');
        }
    }
});

it('returns the workspace clock because a time handed over in UTC is kept five hours late', function () {
    // The mistake this conversion is shaped to prevent, pinned so that nobody "tidies" the answer into UTC:
    // the admin writes the wall clock of the object it is given, and the database takes it for Pakistan time.
    $utc = Convert::time('2026-11-08T20:54:28.341Z')->utc();
    $id = DB::table('tasks')->insertGetId(['title' => 'Handed over in UTC', 'created_at' => $utc]);

    expect($utc->getTimestamp())->toBe(OLD_MOMENT)
        ->and(Task::query()->findOrFail($id)->created_at->getTimestamp())->toBe(OLD_MOMENT - 5 * 3600);
});

it('brings an empty time over as nothing', function () {
    expect(Convert::time(null))->toBeNull()
        ->and(Convert::time(''))->toBeNull()
        ->and(Convert::time('   '))->toBeNull()
        ->and(Convert::day(null))->toBeNull()
        ->and(Convert::day(''))->toBeNull();

    $task = Task::query()->create(['title' => 'No day given', 'due_at' => Convert::time('')]);

    expect($task->fresh()->due_at)->toBeNull()
        ->and(DB::table('tasks')->where('id', $task->id)->value('due_at'))->toBeNull();
});

it('refuses a text that is no time, by name', function (string $text, string $reason) {
    $before = DB::table('tasks')->count();

    try {
        Convert::time($text);
        $this->fail("`{$text}` was taken for a time.");
    } catch (Refused $refused) {
        expect($refused->what)->toBe('time')
            ->and($refused->value)->toBe($text)
            ->and($refused->why)->toContain($reason)
            ->and($refused->getMessage())->toContain($text);
    }

    expect(DB::table('tasks')->count())->toBe($before);
})->with([
    'words' => ['next Tuesday', 'not a time'],
    'what JavaScript prints for a broken date' => ['Invalid Date', 'not a time'],
    'the stand-in the old API sorts empty days by' => ['9999', 'not a time'],
    'a day written the other way round' => ['08/11/2026', 'not a time'],
    'a day that does not exist' => ['2026-02-30', 'no such day'],
    'a thirteenth month' => ['2026-13-01', 'no such day'],
    'an hour that does not exist' => ['2026-11-08T24:00:00Z', 'no such day or time'],
    'a clock without a time zone' => ['2026-11-08 20:54:28', 'without a time zone'],
    'a time zone that does not exist' => ['2026-11-08T20:54:28+99:00', 'no such time zone'],
    'a year the admin cannot hold' => ['2040-01-01T00:00:00Z', 'holds 1970 to 19 January 2038'],
    'the second after the last one' => ['2038-01-19T03:14:08Z', 'holds 1970 to 19 January 2038'],
    'the very start of 1970' => ['1970-01-01T00:00:00Z', 'holds 1970 to 19 January 2038'],
]);

it('takes a clock without a time zone only when the importer says where it stood', function () {
    expect(Convert::time('2026-11-08 20:54:28', 'UTC')->getTimestamp())->toBe(OLD_MOMENT)
        ->and(Convert::time('2026-11-09 01:54:28', 'Asia/Karachi')->getTimestamp())->toBe(OLD_MOMENT)
        // The last second a time column holds is taken.
        ->and(Convert::time('2038-01-19T03:14:07Z')->getTimestamp())->toBe(Convert::LAST_SECOND);
});

it('pins what each server does with a part of a second and with a year past 2038', function () {
    // Why the conversion drops the part of a second itself: handed to the database, MySQL rounds it up into the
    // next second, MariaDB cuts it, and SQLite keeps the text. Three servers, three answers.
    $id = DB::table('tasks')->insertGetId(['title' => 'A part of a second', 'due_at' => '2026-11-08 20:54:28.641']);

    expect(DB::table('tasks')->where('id', $id)->value('due_at'))->toBe(match (DatabaseServer::kind()) {
        DatabaseServer::MYSQL => '2026-11-08 20:54:29',
        DatabaseServer::MARIADB => '2026-11-08 20:54:28',
        default => '2026-11-08 20:54:28.641',
    });

    // Why the conversion refuses a moment past 19 January 2038 itself: MySQL and MariaDB refuse it with an error
    // of their own, and SQLite, on which a sandbox runs, keeps it without a word.
    $write = fn () => DB::table('tasks')->insertGetId(['title' => 'Past 2038', 'due_at' => '2040-01-01 00:00:00']);

    if (DatabaseServer::isMySqlFamily()) {
        expect($write)->toThrow(QueryException::class);
    } else {
        expect(DB::table('tasks')->where('id', $write())->value('due_at'))->toBe('2040-01-01 00:00:00');
    }
});

it('keeps a day a day: no time zone moves it', function () {
    expect(Convert::day('2026-11-08'))->toBe('2026-11-08')
        // A moment is the day it falls on in Pakistan: 20:54 UTC is already the next day there.
        ->and(Convert::day('2026-11-08T20:54:28.341Z'))->toBe('2026-11-09')
        ->and(Convert::day('2026-11-08T18:59:59Z'))->toBe('2026-11-08')
        ->and(Convert::day('2026-11-08T19:00:00Z'))->toBe('2026-11-09')
        // A day column holds years a time column does not.
        ->and(Convert::day('2040-06-01'))->toBe('2040-06-01')
        // The mistake day() exists to prevent: the same day made a moment and read in UTC is the day before.
        ->and(Convert::time('2026-11-08')->utc()->toDateString())->toBe('2026-11-07');

    expect(fn () => Convert::day('2026-02-30'))->toThrow(Refused::class, 'no such day')
        ->and(fn () => Convert::day('0999-12-31'))->toThrow(Refused::class, 'years 1000 to 9999')
        ->and(fn () => Convert::day('2026-11-08 20:54:28'))->toThrow(Refused::class, 'without a time zone');

    // Into a column that holds a day (the business day of an entry of the books), and back.
    $user = User::factory()->create();
    $entry = DB::table('journal_entries')->insertGetId(['entry_no' => 'T176-DAY', 'business_date' => Convert::day('2026-11-08'), 'created_by' => $user->id]);

    expect(substr((string) DB::table('journal_entries')->where('id', $entry)->value('business_date'), 0, 10))->toBe('2026-11-08');
});

/* ---------- money ---------- */

it('brings floating rupees over as whole paisa, or refuses them', function () {
    // 0.1 + 0.2 is 0.30000000000000004 in the old system: noise of the floating number, and 30 paisa.
    expect(0.1 + 0.2)->not->toBe(0.3)
        ->and(Convert::paisaOfFloat(0.1 + 0.2))->toBe(30)
        ->and(Convert::paisaOfFloat('0.30000000000000004'))->toBe(30)
        // The largest amount of the plan: Rs 99,999,999.99, which no 32-bit number holds in paisa.
        ->and(Convert::paisaOfFloat(99999999.99))->toBe(9999999999)
        ->and(Convert::paisaOfFloat(1234.56))->toBe(123456)
        ->and(Convert::paisaOfFloat(-1234.56))->toBe(-123456)
        ->and(Convert::paisaOfFloat(0.0))->toBe(0)
        ->and(Convert::paisaOfFloat(148000))->toBe(14800000)
        // Noise that a subtraction left around nothing, and around twenty paisa.
        ->and(Convert::paisaOfFloat(0.1 + 0.2 - 0.3))->toBe(0)
        ->and(Convert::paisaOfFloat(1000000.10 - 999999.90))->toBe(20)
        ->and(Convert::paisaOfFloat(null))->toBeNull();

    // Half a paisa is not noise. It is refused; it is not rounded, up or down.
    foreach ([1234.565, 0.145, 1.005, 100 / 3, 0.001, '1234.565'] as $amount) {
        expect(fn () => Convert::paisaOfFloat($amount))->toThrow(Refused::class, 'does not come out as whole paisa');
    }

    expect(fn () => Convert::paisaOfFloat(1.0e11))->toThrow(Refused::class, 'too large for a floating number')
        ->and(fn () => Convert::paisaOfFloat('1e+15'))->toThrow(Refused::class, 'too large for a floating number')
        ->and(fn () => Convert::paisaOfFloat(NAN))->toThrow(Refused::class, 'not a finite number')
        ->and(fn () => Convert::paisaOfFloat(INF))->toThrow(Refused::class, 'not a finite number')
        ->and(fn () => Convert::paisaOfFloat('NaN'))->toThrow(Refused::class, 'not a number')
        ->and(fn () => Convert::paisaOfFloat('Rs 1,200'))->toThrow(Refused::class, 'not a number');

    // Into a money column of the admin and back: the same whole number on every server.
    $lead = Lead::factory()->create(['expected_value_minor' => Convert::paisaOfFloat(99999999.99)]);

    expect($lead->fresh()->expected_value_minor)->toBe(9999999999)
        ->and((int) DB::table('leads')->where('id', $lead->id)->value('expected_value_minor'))->toBe(9999999999);
});

it('brings rupees written as a decimal number over exactly', function () {
    // As PostgreSQL prints a NUMERIC column of the books.
    expect(Convert::paisa('1234.50'))->toBe(123450)
        ->and(Convert::paisa('1234.5'))->toBe(123450)
        ->and(Convert::paisa('0.01'))->toBe(1)
        ->and(Convert::paisa('0.10'))->toBe(10)
        ->and(Convert::paisa('1234.5600'))->toBe(123456)
        ->and(Convert::paisa('-5.00'))->toBe(-500)
        ->and(Convert::paisa('-0.00'))->toBe(0)
        ->and(Convert::paisa(7))->toBe(700)
        ->and(Convert::paisa('99999999.99'))->toBe(9999999999)
        ->and(Convert::paisa(null))->toBeNull();

    expect(fn () => Convert::paisa('1234.565'))->toThrow(Refused::class, 'does not come out as whole paisa')
        ->and(fn () => Convert::paisa('0.001'))->toThrow(Refused::class, 'does not come out as whole paisa')
        ->and(fn () => Convert::paisa('1,200.50'))->toThrow(Refused::class, 'not a decimal number')
        ->and(fn () => Convert::paisa(''))->toThrow(Refused::class, 'not a decimal number')
        ->and(fn () => Convert::paisa('NaN'))->toThrow(Refused::class, 'not a decimal number')
        ->and(fn () => Convert::paisa('1e3'))->toThrow(Refused::class, 'not a decimal number')
        ->and(fn () => Convert::paisa('99999999999999999999'))->toThrow(Refused::class, 'too large')
        // A floating number is not taken as if it were exact.
        ->and(fn () => Convert::paisa(1234.5))->toThrow(Refused::class, 'paisaOfFloat');
});

it('makes the sum of a thousand lines the sum of their paisa', function () {
    // A thousand lines as the old system holds them: floating rupees. Beside each, its paisa counted by hand.
    $prices = [[0.1, 10], [0.2, 20], [0.7, 70], [19.95, 1995], [1234.56, 123456], [99.99, 9999], [0.01, 1], [148000.0, 14800000], [2499.5, 249950], [0.3, 30]];
    $floats = [];
    $expected = 0;

    for ($line = 0; $line < 1000; $line++) {
        [$rupees, $paisa] = $prices[$line % count($prices)];
        $floats[] = $rupees;
        $expected += $paisa;
    }

    // The floats added up have drifted: the sum is no longer the amount.
    $drifted = array_sum($floats);
    expect($expected)->toBe(1518553100)
        ->and($drifted)->not->toBe($expected / 100);

    // Each line converted and then added is exact…
    $lines = array_map(Convert::paisaOfFloat(...), $floats);
    expect(array_sum($lines))->toBe($expected)
        // …and the drifted sum, which is noise and not a part of a paisa, arrives as the same amount.
        ->and(Convert::paisaOfFloat($drifted))->toBe($expected);

    // Written as a thousand lines of one entry of the books, the database adds them to the same paisa.
    $user = User::factory()->create();
    $account = DB::table('chart_of_accounts')->insertGetId(['code' => 'T176', 'name' => 'Lines of T-176', 'type' => 'asset']);
    $entry = DB::table('journal_entries')->insertGetId(['entry_no' => 'T176-SUM', 'business_date' => '2026-11-08', 'created_by' => $user->id]);

    foreach (array_chunk($lines, 250) as $chunk) {
        DB::table('journal_lines')->insert(array_map(fn (int $paisa): array => ['journal_entry_id' => $entry, 'account_id' => $account, 'debit_minor' => $paisa], $chunk));
    }

    expect(DB::table('journal_lines')->where('journal_entry_id', $entry)->count())->toBe(1000)
        ->and((int) DB::table('journal_lines')->where('journal_entry_id', $entry)->sum('debit_minor'))->toBe($expected);
});

it('finds every money column an importer writes to wide enough for the largest amount', function () {
    $narrow = [];
    $columns = 0;

    foreach (oldSystemTargets() as $table) {
        foreach (Schema::getColumns($table) as $column) {
            if (str_ends_with($column['name'], '_minor')) {
                $columns++;

                // 64 bits: BIGINT on MySQL and MariaDB, INTEGER on SQLite. A 32-bit column ends at Rs 21,474,836.47.
                if (! in_array(strtolower($column['type_name']), DatabaseServer::isMySqlFamily() ? ['bigint'] : ['integer', 'bigint'], true)) {
                    $narrow[] = "{$table}.{$column['name']} is {$column['type']}";
                }
            }
        }
    }

    expect($narrow)->toBe([])->and($columns)->toBeGreaterThan(30);
});

/* ---------- JSON ---------- */

it('reads the same value back from a JSON column on every server', function () {
    // As the old system keeps a list inside a cell: a text of JSON, with Urdu, an emoji, a number above 2^53
    // (the largest whole number JavaScript holds exactly is 9007199254740992) and keys in a given order.
    $text = '{"zebra":1,"apple":2,"b":3,"name":"آفس فرنیچر — ایگزیکٹو ڈیسک","mark":"🪑","count":9007199254740993,'
        .'"lines":[{"name":"Desk","qty":3},{"name":"Chair","qty":12},{"name":"Cabinet","qty":1}],"price":1234.5,"none":null,"yes":true}';
    $value = Convert::json($text);

    expect($value['count'])->toBe(9007199254740993)
        ->and($value['name'])->toBe('آفس فرنیچر — ایگزیکٹو ڈیسک')
        ->and($value['mark'])->toBe('🪑')
        ->and(array_keys($value))->toBe(['zebra', 'apple', 'b', 'name', 'mark', 'count', 'lines', 'price', 'none', 'yes']);

    $contact = Contact::factory()->create(['meta' => $value]);
    $read = $contact->fresh()->meta;

    // The same value on every server: every key with its value, the number to the last digit…
    expect($read)->toEqual($value)
        ->and($read['count'])->toBe(9007199254740993)
        ->and($read['name'])->toBe('آفس فرنیچر — ایگزیکٹو ڈیسک')
        ->and($read['mark'])->toBe('🪑')
        ->and($read['price'])->toBe(1234.5)
        ->and($read['none'])->toBeNull()
        ->and($read['yes'])->toBeTrue()
        // …and a list in its order, which is why what must stay in order goes in a list.
        ->and(array_column($read['lines'], 'name'))->toBe(['Desk', 'Chair', 'Cabinet']);

    // What differs: the order of the keys of a map. MySQL keeps a JSON column as a document and hands the keys
    // back in an order of its own; MariaDB and SQLite keep the text. Pinned, so that nothing is built on that order.
    $native = strtolower(Schema::getColumnType('contacts', 'meta')) === 'json';
    $raw = (string) DB::table('contacts')->where('id', $contact->id)->value('meta');
    $keys = array_keys(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));

    expect($native)->toBe(DatabaseServer::kind() === DatabaseServer::MYSQL);

    if ($native) {
        expect($keys)->not->toBe(array_keys($value))->and($keys)->toEqualCanonicalizing(array_keys($value));
    } else {
        expect($keys)->toBe(array_keys($value));
    }
});

it('keeps Urdu and an emoji in a text column, and counts characters as the database does', function () {
    $name = 'محمد علی — آفس فرنیچر 🪑';
    $contact = Contact::factory()->create(['name' => Convert::text($name, 160)]);

    expect($contact->fresh()->name)->toBe($name)
        ->and(DB::table('contacts')->where('id', $contact->id)->value('name'))->toBe($name);

    // A name of exactly 160 characters is kept whole on every server: 159 Urdu letters and an emoji are 160
    // characters and 322 bytes, and the column counts characters.
    $whole = str_repeat('ک', 159).'🪑';
    expect(mb_strlen($whole))->toBe(160)->and(strlen($whole))->toBe(322);
    $kept = Contact::factory()->create(['name' => Convert::text($whole, 160)]);
    expect($kept->fresh()->name)->toBe($whole);

    // One more is refused by name, before the database…
    $long = $whole.'ک';
    expect(fn () => Convert::text($long, 160))->toThrow(Refused::class, '161 characters, and the column holds 160')
        ->and(Convert::text(null, 160))->toBeNull()
        ->and(Convert::text('', 160))->toBe('')
        ->and(fn () => Convert::text("\xC3\x28", 160))->toThrow(Refused::class, 'not UTF-8');

    // …because the servers disagree about it: MySQL and MariaDB refuse it with an error of their own, and SQLite,
    // on which a sandbox runs, keeps all 161.
    $write = fn () => Contact::factory()->create(['name' => $long]);

    if (DatabaseServer::isMySqlFamily()) {
        expect($write)->toThrow(QueryException::class);
    } else {
        expect(mb_strlen($write()->fresh()->name))->toBe(161);
    }
});

it('refuses a text that is not JSON, by name', function () {
    expect(Convert::json(null))->toBeNull()
        ->and(Convert::json(''))->toBeNull()
        ->and(Convert::json('  '))->toBeNull()
        ->and(Convert::json('[]'))->toBe([])
        ->and(Convert::json('"a text"'))->toBe('a text')
        // A whole number too large for PHP arrives as its digits, not as a floating number that lost them.
        ->and(Convert::json('{"n":123456789012345678901234567890}'))->toBe(['n' => '123456789012345678901234567890']);

    foreach (['not json', '{"a":', "{'a':1}", '[1,2,]', '{"a":1}{"b":2}', "\xC3\x28"] as $text) {
        try {
            Convert::json($text);
            $this->fail("`{$text}` was taken for JSON.");
        } catch (Refused $refused) {
            expect($refused->what)->toBe('JSON')->and($refused->why)->toStartWith('not JSON (');
        }
    }
});

/* ---------- yes and no ---------- */

it('brings 0 and 1 over as no and yes', function () {
    expect(Convert::flag(1))->toBeTrue()
        ->and(Convert::flag(0))->toBeFalse()
        ->and(Convert::flag('1'))->toBeTrue()
        ->and(Convert::flag('0'))->toBeFalse()
        // The three true yes-or-no columns, as PostgreSQL prints them and as a driver hands them over.
        ->and(Convert::flag('t'))->toBeTrue()
        ->and(Convert::flag('f'))->toBeFalse()
        ->and(Convert::flag(true))->toBeTrue()
        ->and(Convert::flag(false))->toBeFalse()
        ->and(Convert::flag(null))->toBeNull();

    foreach ([2, -1, 'yes', 'no', '', 'T', '01'] as $value) {
        expect(fn () => Convert::flag($value))->toThrow(Refused::class, 'neither 0 nor 1');
    }

    $contact = Contact::factory()->create([
        'is_client' => Convert::flag(1),
        'consent_marketing' => Convert::flag('0'),
        'consent_whatsapp' => Convert::flag('t'),
    ]);
    $read = $contact->fresh();
    $raw = DB::table('contacts')->where('id', $contact->id)->first();

    expect($read->is_client)->toBeTrue()
        ->and($read->consent_marketing)->toBeFalse()
        ->and($read->consent_whatsapp)->toBeTrue()
        ->and([(int) $raw->is_client, (int) $raw->consent_marketing, (int) $raw->consent_whatsapp])->toBe([1, 0, 1]);
});

/* ---------- upper and lower case ---------- */

it('pins what each server does with two values that differ only in case', function () {
    // PostgreSQL holds the two spellings to be two values. So does SQLite, on which a sandbox runs. MySQL and
    // MariaDB, as the admin sets them up, hold them to be one, and a unique column refuses the second.
    $lower = 'office@furniture.example';
    $upper = ucfirst($lower);
    User::factory()->create(['email' => $lower]);
    $second = fn () => User::factory()->create(['email' => $upper]);

    DB::table('commerce_products')->insert(['slug' => 'executive-desk', 'source_key' => 'WX-Desk-01', 'name' => 'Executive desk', 'draft' => '{}']);
    $twin = fn () => DB::table('commerce_products')->insert(['slug' => 'executive-desk-2', 'source_key' => 'wx-desk-01', 'name' => 'Executive desk', 'draft' => '{}']);

    if (DatabaseServer::isMySqlFamily()) {
        expect($second)->toThrow(UniqueConstraintViolationException::class)
            ->and($twin)->toThrow(UniqueConstraintViolationException::class)
            ->and(User::query()->where('email', $upper)->count())->toBe(1)
            ->and(DB::table('commerce_products')->where('source_key', 'wx-desk-01')->count())->toBe(1);
    } else {
        $second();
        $twin();

        expect(User::query()->whereIn('email', [$lower, $upper])->count())->toBe(2)
            ->and(DB::table('commerce_products')->whereIn('source_key', ['WX-Desk-01', 'wx-desk-01'])->count())->toBe(2)
            ->and(User::query()->where('email', $upper)->count())->toBe(1);
    }
});

it('says before the database does that two values meet', function (string $first, string $second, bool $rule, bool $database) {
    // What the rule sees is the same on every server…
    expect(Convert::asCompared($first) === Convert::asCompared($second))->toBe($rule);

    // …and what the database's own comparison says is pinned beside it: the first value is written into a column
    // that is unique, and the database is asked for the second. Where the database holds two values to be one and
    // the rule did not see it (`ß` and `ss`), the unique index refuses the second and the import stops.
    DB::table('commerce_products')->insert(['slug' => 't176-compared', 'source_key' => $first, 'name' => 'Compared', 'draft' => '{}']);
    $same = DB::table('commerce_products')->where('source_key', $second)->exists();

    expect($same)->toBe(DatabaseServer::isMySqlFamily() ? $database : $first === $second);
})->with([
    'upper and lower case' => ['WX-Desk-01', 'wx-desk-01', true, true],
    'a space at the end' => ['wx-desk-01', 'wx-desk-01 ', true, true],
    'a letter with and without its accent' => ['Café Table', 'cafe table', true, true],
    'the same value' => ['wx-desk-01', 'wx-desk-01', true, true],
    'another value' => ['wx-desk-01', 'wx-desk-02', false, false],
    'a dash more' => ['wx-desk-01', 'wxdesk01', false, false],
    'a space at the start' => ['wx-desk-01', ' wx-desk-01', false, false],
    'what only the database sees' => ['Strasse', 'Straße', false, true],
]);

it('applies the written rule of a unique column before anything is written', function () {
    $lower = 'office@furniture.example';
    $upper = ucfirst($lower);

    // A product code: the import stops and names both old records. The same answer on every server.
    try {
        Convert::settle('commerce_products', ['source_key'], [41 => 'WX-Desk-01', 42 => 'wx-desk-02', 43 => 'wx-desk-01']);
        $this->fail('Two product codes that differ only in case were let through.');
    } catch (Refused $refused) {
        expect($refused->what)->toBe('unique column')
            ->and($refused->why)->toContain('the old records 41 and 43 meet on `commerce_products.source_key`')
            ->and($refused->why)->toContain('the import stops');
    }

    // An address for signing in: one mailbox is one person. The lower old number is kept, the other is listed.
    expect(Convert::settle('users', ['email'], [3 => $lower, 9 => $upper, 12 => null, 14 => 'works@furniture.example', 15 => null]))
        ->toBe(['kept' => [3, 12, 14, 15], 'left' => [9 => 3]]);

    // An index of two columns: the same number under two suppliers does not meet; under one it does.
    expect(Convert::settle('bills', ['vendor_id', 'number'], [1 => [5, 'INV-7'], 2 => [6, 'inv-7'], 3 => [5, null]]))
        ->toBe(['kept' => [1, 2, 3], 'left' => []]);
    expect(fn () => Convert::settle('bills', ['vendor_id', 'number'], [1 => [5, 'INV-7'], 2 => [5, 'inv-7']]))
        ->toThrow(Refused::class, 'the old records 1 and 2 meet on `bills.vendor_id+number`');

    // A column the admin fills itself, and one that has no rule, are not settled by guessing.
    expect(fn () => Convert::settle('invoices', ['public_id'], [1 => 'a']))->toThrow(Refused::class, 'is not written into this column')
        ->and(fn () => Convert::settle('invoices', ['notes'], [1 => 'a']))->toThrow(Refused::class, 'no unique index of this name has a written rule');
});

it('has a written rule for every unique index of the tables an importer writes to', function () {
    // The 28 tables the map sends rows into, the table of the old numbers, and the two the plan names as examples.
    $tables = [...oldSystemTargets(), 'commerce_products', 'legacy_refs', 'users'];
    sort($tables);

    $inDatabase = [];
    foreach ($tables as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['unique'] && ! $index['primary']) {
                $inDatabase[] = $table.': '.implode(' + ', $index['columns']);
            }
        }
    }

    $written = array_map(fn (array $rule): string => $rule['table'].': '.implode(' + ', $rule['columns']), Rules::uniqueColumns());
    sort($inDatabase);
    sort($written);

    // A unique index that is added to one of these tables fails here until somebody writes its rule.
    expect($written)->toBe($inDatabase)
        ->and(array_values(array_unique(array_column(Rules::uniqueColumns(), 'table'))))->each->toBeIn($tables);

    foreach (Rules::uniqueColumns() as $rule) {
        $name = $rule['table'].': '.implode(' + ', $rule['columns']);
        $types = array_column(Schema::getColumns($rule['table']), 'type_name', 'name');
        $texts = array_filter($rule['columns'], fn (string $column): bool => preg_match('/char|text/i', $types[$column]) === 1);

        expect($rule['value'])->toBeIn(['admin', 'numbers', 'key', 'carried'], $name)
            ->and(trim($rule['why']))->not->toBe('', $name);

        // "Only numbers" is true of the columns: case cannot matter there.
        expect($rule['value'] === 'numbers')->toBe($texts === [], $name.': says "numbers" exactly when no column is a text');

        if ($rule['value'] === 'carried') {
            expect($rule['rule'] ?? null)->toBeIn(['stop', 'first'], $name);
            // The old column the value comes from belongs to a table of the old system.
            expect(array_key_exists(explode('.', (string) ($rule['from'] ?? '.'))[0], Shape::tables()))->toBeTrue($name.': `from` names no table of the old system');
        } else {
            expect(array_key_exists('rule', $rule))->toBeFalse($name.': only a carried value has a rule');
        }
    }
});

/* ---------- words ---------- */

it('sends every word the old system allows through the conversion: each has a place, or stands on a written list', function () {
    $placed = [];
    $without = [];
    $waiting = [];
    $unplaced = [];

    foreach (Shape::vocabularies() as $where) {
        [$table, $column] = explode('.', $where);

        foreach (Shape::words($table, $column) as $word) {
            try {
                $place = Convert::word($table, $column, $word);
                expect($place)->toBeInstanceOf(BackedEnum::class);
                $placed[] = "{$where}: {$word}";
            } catch (Refused $refused) {
                if (Shape::whyNot($table) !== null) {
                    // A table whose rows are not brought over today: the refusal says which line it waits for.
                    expect($refused->why)->toBe(Shape::whyNot($table));
                    $waiting[] = "{$where}: {$word}";
                } elseif (isset(Rules::vocabulary($table, $column)['without'][$word])) {
                    expect($refused->why)->toContain(Rules::vocabulary($table, $column)['without'][$word]);
                    $without[] = "{$where}: {$word}";
                } else {
                    $unplaced[] = "{$where}: {$word}";
                }
            }
        }
    }

    // A word that has neither a place nor a reason is named here.
    expect($unplaced)->toBe([])
        ->and($placed)->not->toBeEmpty()
        ->and($without)->not->toBeEmpty()
        ->and($waiting)->not->toBeEmpty()
        // Every status, stage and priority that a CHECK constraint lists was among them.
        ->and([...$placed, ...$without, ...$waiting])->toContain(
            'invoices.status: Partially Paid', 'site_visits.status: Confirmed', 'tasks.priority: high',
            'orders.stage: installation', 'job_cards.stage: qc', 'tickets.priority: urgent', 'tickets.status: waiting',
        );
});

it('keeps the written places true to the old system and to the enumerations of the admin', function () {
    $problems = [];

    foreach (Rules::vocabularies() as $where => $vocabulary) {
        [$table, $column] = explode('.', $where);
        $allowed = Shape::words($table, $column);

        if ($allowed === null) {
            $problems[] = "{$where}: the old system lists no words for this column";

            continue;
        }

        if (Shape::whyNot($table) !== null) {
            $problems[] = "{$where}: its table is not brought over today, so it has no places yet";
        }

        $written = [...array_keys($vocabulary['places']), ...array_keys($vocabulary['without'])];

        foreach (array_diff($allowed, $written) as $word) {
            $problems[] = "{$where}: `{$word}` has neither a place nor a reason";
        }
        foreach (array_diff($written, $allowed) as $word) {
            $problems[] = "{$where}: `{$word}` is written, but the old system does not allow it";
        }
        foreach (array_intersect(array_keys($vocabulary['places']), array_keys($vocabulary['without'])) as $word) {
            $problems[] = "{$where}: `{$word}` has a place and a reason for having none";
        }

        if ($vocabulary['places'] === []) {
            continue;
        }

        if ($vocabulary['enum'] === null || ! enum_exists($vocabulary['enum']) || ! is_subclass_of($vocabulary['enum'], BackedEnum::class)) {
            $problems[] = "{$where}: `".($vocabulary['enum'] ?? 'nothing').'` is not an enumeration of the admin';

            continue;
        }

        foreach ($vocabulary['places'] as $word => $place) {
            if ($vocabulary['enum']::tryFrom($place) === null) {
                $problems[] = "{$where}: `{$word}` is sent to `{$place}`, which {$vocabulary['enum']} does not have";
            }
        }
    }

    // A column of a table that moves, whose words the old system lists, and that the file leaves out.
    foreach (Shape::vocabularies() as $where) {
        if (Shape::whyNot(explode('.', $where)[0]) === null && ! isset(Rules::vocabularies()[$where])) {
            $problems[] = "{$where}: the old system lists its words and its table moves, but no place is written";
        }
    }

    expect($problems)->toBe([]);
});

it('places a word exactly as the old system writes it, and refuses the rest by name', function () {
    expect(Convert::word('tasks', 'priority', 'high'))->toBe(LeadPriority::High)
        ->and(Convert::word('tasks', 'priority', null))->toBeNull()
        ->and(Convert::word('invoices', 'status', 'Partially Paid')->value)->toBe('partially_paid')
        ->and(Convert::word('invoices', 'status', 'Cancelled')->value)->toBe('void')
        ->and(Convert::word('accounts', 'kind', 'revenue')->value)->toBe('income')
        ->and(Convert::word('conversations', 'status', 'resolved')->value)->toBe('closed');

    // PostgreSQL compares exactly: `Paid` is a status of an old invoice, `paid` is not.
    expect(fn () => Convert::word('invoices', 'status', 'paid'))->toThrow(Refused::class, 'not a word the old system allows in `invoices.status`')
        ->and(fn () => Convert::word('invoices', 'status', 'Overdue'))->toThrow(Refused::class, 'not a word the old system allows')
        ->and(fn () => Convert::word('invoices', 'status', ''))->toThrow(Refused::class, 'not a word the old system allows')
        // The same word, another meaning: it is not matched by its name.
        ->and(fn () => Convert::word('quotes', 'status', 'Approved'))->toThrow(Refused::class, 'The same word, another meaning')
        // A table that waits, a table that stays, a column that takes any text, a table that does not exist.
        ->and(fn () => Convert::word('orders', 'status', 'Pending'))->toThrow(Refused::class, 'it waits for T-106')
        ->and(fn () => Convert::word('products', 'status', 'draft'))->toThrow(Refused::class, 'stay in the old system')
        ->and(fn () => Convert::word('leads', 'status', 'New'))->toThrow(Refused::class, 'lists no words for this column')
        ->and(fn () => Convert::word('wishlists', 'status', 'open'))->toThrow(Refused::class, 'no table of this name');

    // Into a column of the admin and back.
    $task = Task::query()->create(['title' => 'Call the architect', 'priority' => Convert::word('tasks', 'priority', 'high')]);

    expect($task->fresh()->priority)->toBe(LeadPriority::High)
        ->and(DB::table('tasks')->where('id', $task->id)->value('priority'))->toBe('high');
});
