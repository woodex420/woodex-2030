<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| Document 03 §2: "Store timestamps in UTC; render in configured workspace timezone." Document 04: "ISO-8601
| timestamps in UTC."
|
| The workspace's time zone is one setting, APP_TIMEZONE (Asia/Karachi). Staff read and type Pakistan time on
| every screen, PDF and export. The database holds the instant in UTC: every time column is a TIMESTAMP, which
| MySQL keeps in UTC and converts through the session's time zone — and that session zone is derived from
| APP_TIMEZONE (config/database.php), so the two cannot disagree.
|
| The first three tests run on every engine. The last one asks MySQL itself, so it runs in CI (MySQL 8.4) and is
| skipped on SQLite, which only a preview and this suite use: SQLite keeps the workspace's wall clock as text.
*/

it('shows the workspace time on screen and sends UTC to a program', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 14:30:00', 'Asia/Karachi'));

    $user = User::factory()->create()->fresh();

    expect(config('app.timezone'))->toBe('Asia/Karachi')
        // What a screen, a PDF or an export prints: Pakistan time.
        ->and($user->created_at->format('Y-m-d H:i'))->toBe('2026-11-17 14:30')
        ->and($user->created_at->timezoneName)->toBe('Asia/Karachi')
        // What an API answer carries: the same instant as ISO-8601 in UTC.
        ->and($user->toArray()['created_at'])->toBe('2026-11-17T09:30:00.000000Z')
        ->and($user->created_at->getTimestamp())->toBe(CarbonImmutable::parse('2026-11-17 09:30:00', 'UTC')->getTimestamp());
});

it('takes the time zone of the database session from the workspace time zone', function () {
    $offset = CarbonImmutable::now(config('app.timezone'))->format('P');

    expect($offset)->toBe('+05:00')
        ->and(config('database.connections.mysql.timezone'))->toBe($offset)
        ->and(config('database.connections.mariadb.timezone'))->toBe($offset);

    // The installer and the example settings name the workspace zone once, and no second value that could differ.
    expect((string) file_get_contents(base_path('.env.example')))->toContain('APP_TIMEZONE=Asia/Karachi')->not->toContain("\nDB_TIMEZONE=")
        ->and((string) file_get_contents(app_path('Support/Install/Installer.php')))->not->toContain("'DB_TIMEZONE'");
});

it('declares every time column as a TIMESTAMP, never a DATETIME', function () {
    // A DATETIME is kept as written: Pakistan wall clock, with nothing in the database saying so. One such column
    // would break "the database holds UTC" for itself only, and silently.
    $found = [];
    foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
        if (preg_match_all('/->(dateTime|dateTimeTz|softDeletesDatetime|datetimes)\(/', (string) file_get_contents($file), $matches)) {
            $found[] = basename($file).': '.implode(', ', array_unique($matches[1]));
        }
    }

    expect($found)->toBe([]);
});

it('holds the instant in UTC on MySQL', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Asks MySQL itself; runs in CI on MySQL 8.4. SQLite (previews, this suite) keeps the wall clock as text.');
    }

    $instant = CarbonImmutable::parse('2026-11-17 14:30:00', 'Asia/Karachi');
    $this->travelTo($instant);
    $user = User::factory()->create();

    $row = DB::selectOne("select @@session.time_zone as zone, unix_timestamp(created_at) as epoch, convert_tz(created_at, @@session.time_zone, '+00:00') as utc from users where id = ?", [$user->id]);

    // The session speaks Pakistan time…
    expect($row->zone)->toBe('+05:00')
        // …and what MySQL holds is the instant itself: its epoch, which is UTC by definition.
        ->and((int) $row->epoch)->toBe($instant->getTimestamp())
        ->and((string) $row->utc)->toBe('2026-11-17 09:30:00');

    // No column of the schema is a DATETIME.
    $datetimes = DB::select("select table_name, column_name from information_schema.columns where table_schema = database() and data_type = 'datetime'");
    expect($datetimes)->toBe([]);
});
