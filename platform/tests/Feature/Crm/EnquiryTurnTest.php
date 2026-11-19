<?php

use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Services\EnquiryTurn;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
| The order in which an enquiry takes its locks (master list T-063).
|
| Two visitors of the shop who sent an enquiry in the same moment, both new, were stopped by the database: each
| held the empty place where the other's contact would go, and one of the two was told the enquiry had failed.
| The cure is an order: the enquiry's turn first, the question "do we know this person?" after it.
|
| That two enquiries at once now both arrive is shown where it can be shown, on MySQL and MariaDB with two
| processes (tools/ci/two-writers.php, the steps "two-enquiries" and "two-baskets"; without the turn the first
| reports the fault). SQLite lets one writer in at a time and cannot show it. What these tests keep, on every
| run, is the order itself: whoever moves the question in front of the turn fails here.
*/

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    $this->userWithRole('owner');

    /** Every statement the work sends to the database, in order: [the words, what was asked for]. */
    $this->statementsOf = function (Closure $work): array {
        $seen = [];
        $listening = true;
        DB::listen(function (QueryExecuted $query) use (&$seen, &$listening): void {
            if ($listening) {
                $seen[] = [strtolower(str_replace(['"', '`'], '', $query->sql)), $query->bindings];
            }
        });
        $work();
        $listening = false;

        return $seen;
    };

    /** Where the turn is taken, and where the first statement about a table stands; null when there is none. */
    $this->turnIn = function (array $statements): ?int {
        foreach ($statements as $position => [$sql, $bindings]) {
            if (str_starts_with($sql, 'select * from number_sequences where key = ?') && $bindings === [EnquiryTurn::SERIES]) {
                return $position;
            }
        }

        return null;
    };
    $this->firstAbout = function (array $statements, string $table): ?int {
        foreach ($statements as $position => [$sql]) {
            if (preg_match('/\b(from|into|update) '.$table.'\b/', $sql) === 1) {
                return $position;
            }
        }

        return null;
    };

    $this->enquiry = fn (?string $phone, ?string $email, string $message = 'A desk for eight people.'): LeadInput => new LeadInput(
        name: 'Ayesha Khan', phone: $phone, email: $email, source: LeadSource::WebsiteForm, businessLine: BusinessLine::Furniture, message: $message,
    );
});

it('takes the enquiry\'s turn before it asks whether the person is known', function (?string $phone, ?string $email) {
    $statements = ($this->statementsOf)(fn () => app(CaptureLead::class)->handle(($this->enquiry)($phone, $email)));

    $turn = ($this->turnIn)($statements);
    $contacts = ($this->firstAbout)($statements, 'contacts');

    expect($turn)->not->toBeNull('the turn is taken')
        ->and($contacts)->not->toBeNull('the contact is asked for')
        ->and($turn)->toBeLessThan($contacts, 'the turn comes before the first question about a contact')
        // Nothing is written before the turn: a writer that waits there holds nothing another one could want.
        ->and(array_filter(array_slice($statements, 0, $turn), fn (array $statement): bool => preg_match('/^(insert|update|delete)\b/', $statement[0]) === 1))->toBe([]);
})->with([
    'a phone and an address' => ['0322 4000768', 'ayesha@example.test'],
    'a phone' => ['0322 4000768', null],
    'an address' => [null, 'ayesha@example.test'],
]);

it('takes the turn for an enquiry that joins an open one, which takes no number', function () {
    app(CaptureLead::class)->handle(($this->enquiry)('0322 4000768', null));

    $statements = ($this->statementsOf)(fn () => app(CaptureLead::class)->handle(($this->enquiry)('0322 4000768', null, 'And two cabinets.')));

    // One question to the series: the turn. A second one would be a number taken, and none is.
    $asked = array_filter($statements, fn (array $statement): bool => str_starts_with($statement[0], 'select * from number_sequences where key = ?'));
    expect($asked)->toHaveCount(1)
        ->and(($this->turnIn)($statements))->toBeLessThan(($this->firstAbout)($statements, 'contacts'))
        ->and(DB::table('leads')->count())->toBe(1)
        ->and(DB::table('number_sequences')->where('key', EnquiryTurn::SERIES)->value('next_number'))->toBe(2);
});

it('refuses to take the enquiry\'s turn outside a transaction', function () {
    // The suite runs every test inside a transaction; the turn is asked for from outside all of them.
    $level = DB::transactionLevel();
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    try {
        expect(fn () => app(EnquiryTurn::class)->take())->toThrow(LogicException::class, 'take it inside one');
    } finally {
        while (DB::transactionLevel() < $level) {
            DB::beginTransaction();
        }
    }
});

it('says so when the series of enquiries is not there, instead of going on without a turn', function () {
    DB::table('number_sequences')->where('key', EnquiryTurn::SERIES)->delete();

    expect(fn () => DB::transaction(fn () => app(EnquiryTurn::class)->take()))->toThrow(RuntimeException::class, 'Number sequence [lead] is not configured');
});

/*
| A lock on "the rows where …" locks what the database reads on the way, and when no row is there it holds the
| empty place. Each lock of the CRM that is not on one row by its key stands here with the reason it is safe. A new
| one fails this test until it is by key, or until it is added here by somebody who has read
| App\Domain\Crm\Services\EnquiryTurn and given tools/ci/two-writers.php a step that tries it.
*/
it('locks one row by its own key wherever an enquiry is written, or says why not', function () {
    $known = [
        'Actions/CaptureLead.php' => [
            "->wherePhone(\$phone)->oldest('id')->lockForUpdate()->first()" => 'inside the enquiry\'s turn: is this phone a contact already',
            "->where('email', \$email)->oldest('id')->lockForUpdate()->first()" => 'inside the enquiry\'s turn: is this address a contact already',
        ],
        'Actions/ManualWhatsAppHandoff.php' => [
            "->where('token_hash', hash('sha256', \$token))->lockForUpdate()->first()" => 'one row, found by a unique key; nothing is written where there is none',
        ],
        'Services/EnquiryTurn.php' => [
            "->where('key', self::SERIES)->lockForUpdate()->first()" => 'the turn itself: one row, found by its name, which is a key',
        ],
    ];

    $unexplained = [];
    $met = [];
    $files = glob(app_path('Domain/Crm/{Actions,Services,Models}/*.php'), GLOB_BRACE);
    expect(count($files))->toBeGreaterThan(20);

    foreach ($files as $file) {
        $name = str_replace(app_path('Domain/Crm/'), '', $file);
        foreach (explode(';', (string) file_get_contents($file)) as $statement) {
            if (! str_contains($statement, 'lockForUpdate()')) {
                continue;
            }
            $statement = trim((string) preg_replace('/\s+/', ' ', $statement));
            // One row by its key: find(), findOrFail(), whereKey().
            if (preg_match('/->lockForUpdate\(\)->find(OrFail)?\(|whereKey\(/', $statement)) {
                continue;
            }
            $reasons = array_filter($known[$name] ?? [], fn (string $words): bool => str_contains($statement, $words), ARRAY_FILTER_USE_KEY);
            if ($reasons === []) {
                $unexplained[] = "{$name}: {$statement}";
            }
            foreach (array_keys($reasons) as $words) {
                $met["{$name} {$words}"] = true;
            }
        }
    }

    expect($unexplained)->toBe([], 'a lock that is not on one row by its key, in the part that writes enquiries');

    // An entry of the list that no longer matches anything is taken off it, so the list stays what is true.
    foreach ($known as $name => $entries) {
        foreach (array_keys($entries) as $words) {
            expect($met)->toHaveKey("{$name} {$words}");
        }
    }
});

it('knows every place that asks, with a lock, whether a person is known', function () {
    // A place that asks the contacts for a phone or an address with a lock, outside the turn, holds the empty place
    // of a person who is not known yet: the fault this order cured. A new one fails here until it takes the turn.
    $asking = [];
    foreach ((new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (explode(';', (string) file_get_contents($file->getPathname())) as $statement) {
            if (str_contains($statement, 'Contact::') && str_contains($statement, 'lockForUpdate()') && preg_match('/wherePhone\(|\'(email|phone|whatsapp|alt_phone)\'/', $statement)) {
                $asking[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }
    }

    sort($asking);

    expect(array_values(array_unique($asking)))->toBe([
        // The WhatsApp inbox. It asks OUTSIDE the turn and has the fault still. The inbox is switched off and no
        // WhatsApp account is connected (decision DL-034, master list T-014), so no message reaches it; master list
        // T-190 cures it, with a step of its own in tools/ci/two-writers.php, before the inbox is opened.
        'Domain/Conversations/Actions/ReceiveWhatsAppMessage.php',
        // Every enquiry, from every channel: inside the turn.
        'Domain/Crm/Actions/CaptureLead.php',
    ]);
});
