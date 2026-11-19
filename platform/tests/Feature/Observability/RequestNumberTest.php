<?php

declare(strict_types=1);

use App\Domain\Crm\Models\Lead;
use App\Support\Install\DatabaseUpdates;
use App\Support\RequestLog;
use App\Support\RequestNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Monolog\Formatter\JsonFormatter;

/*
| A request number on every answer and on every log line; logs in a structured form
| (master list T-062; documents 02 §9 and 04 §2 of the specification).
|
| What must hold: an error page shows the number, and the same number finds the line in the log.
| And what a log must never hold: an address with a token in it, a query string, an e-mail address.
*/

final class SaysSomethingLater implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        Log::warning('Said later, from the queue.');
    }
}

beforeEach(function () {
    config(['modules.enabled' => 'lead_api', 'woodex.business_lines' => ['furniture'], 'app.debug' => false]);

    // The log of this test: a file of its own, in the form the product ships with.
    $this->logFile = storage_path('framework/testing/request-number-'.bin2hex(random_bytes(6)).'.log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logFile, 'logging.channels.single.level' => 'debug']);
    app('log')->forgetChannel('single');

    Route::middleware('web')->get('/__fault', fn () => throw new RuntimeException('A fault made on purpose.'));
    Route::middleware('web')->get('/__status/{code}', fn (int $code) => abort($code));
    Route::middleware(['web', 'auth'])->get('/__says', function () {
        Log::warning('Said while answering.');

        return 'ok';
    })->name('test.says');
    Route::middleware('web')->get('/__says-as-anybody', function () {
        Log::warning('Said to anybody.');

        return 'ok';
    });
    Route::middleware('web')->get('/__queues', function () {
        SaysSomethingLater::dispatch();

        return 'queued';
    });
});

afterEach(function () {
    @unlink($this->logFile);
});

/** Every line of this test's log, read as the JSON it must be. */
function logLines(string $file): array
{
    if (! is_file($file)) {
        return [];
    }

    return array_map(
        fn (string $line): array => json_decode($line, true, 64, JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", (string) file_get_contents($file)), fn (string $line): bool => $line !== '')),
    );
}

function numberOnThePage(TestResponse $answer): string
{
    preg_match('/data-request-number>([^<]+)</', (string) $answer->getContent(), $found);

    return $found[1] ?? '';
}

it('makes a number that can be read out: twelve characters in three groups, never an I, L, O or U', function () {
    $numbers = array_map(fn (): string => RequestNumber::make(), range(1, 500));

    foreach ($numbers as $number) {
        expect($number)->toMatch('/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/');
    }

    expect(array_unique($numbers))->toHaveCount(500)
        ->and(RequestNumber::looksLikeOne('4F7K-9Q2M-T8RB'))->toBeTrue()
        ->and(RequestNumber::looksLikeOne('4F7K-9Q2M-T8RO'))->toBeFalse()   // an O
        ->and(RequestNumber::looksLikeOne("4F7K-9Q2M-T8RB\n"))->toBeFalse()
        ->and(RequestNumber::looksLikeOne(null))->toBeFalse();
});

it('sends the number with every kind of answer', function (TestResponse $answer, int $status) {
    expect($answer->getStatusCode())->toBe($status)
        ->and(RequestNumber::looksLikeOne($answer->headers->get('X-Request-Id')))->toBeTrue('no request number in the answer');
})->with([
    'a page' => [fn () => $this->get('/admin/login'), 200],
    'a redirect to the sign-in' => [fn () => $this->get('/admin'), 302],
    'a page that does not exist' => [fn () => $this->get('/admin/no-such-screen'), 404],
    'a screen the person may not open' => [fn () => $this->actingAs($this->userWithRole('sales'))->get(route('admin.settings')), 403],
    'a download' => [fn () => $this->actingAs($this->userWithRole('owner'))->get(route('admin.leads.export', ['year' => now()->year])), 200],
    'a JSON refusal' => [fn () => $this->getJson('/api/v1/leads'), 405],
    'a JSON answer to a form that is not filled in' => [fn () => $this->postJson('/api/v1/leads', [], idempotencyKey()), 422],
    'the health check' => [fn () => $this->get('/up'), 200],
    'a fault' => [fn () => $this->get('/__fault'), 500],
    'a switched-off module' => [fn () => $this->get('/portal'), 404],
]);

it('makes a new number for every request, and never takes over the one a caller sends', function () {
    config(['woodex.logging.requests' => 'all']);

    $first = $this->get('/admin/login')->headers->get('X-Request-Id');
    $second = $this->get('/admin/login')->headers->get('X-Request-Id');
    $chosen = $this->withHeaders(['X-Request-Id' => 'AAAA-BBBB-CCCC'])->get('/admin/login')->headers->get('X-Request-Id');
    $this->withHeaders(['X-Request-Id' => 'two words "and a quote"'])->get('/admin/login');

    expect($first)->not->toBe($second)->and($chosen)->not->toBe('AAAA-BBBB-CCCC');

    $lines = logLines($this->logFile);

    // The caller's own number is written beside ours when it is a plain token, and dropped when it is not.
    expect($lines)->toHaveCount(4)
        ->and($lines[0]['extra'])->not->toHaveKey('client_request_id')
        ->and($lines[2]['extra']['request_id'])->toBe($chosen)
        ->and($lines[2]['extra']['client_request_id'])->toBe('AAAA-BBBB-CCCC')
        ->and($lines[3]['extra'])->not->toHaveKey('client_request_id')
        ->and((string) file_get_contents($this->logFile))->not->toContain('two words');
});

it('shows the number on every error page: the one the answer carries', function (int $status) {
    $answer = $this->get('/__status/'.$status)->assertStatus($status);

    expect(numberOnThePage($answer))->toBe($answer->headers->get('X-Request-Id'))
        ->and(RequestNumber::looksLikeOne(numberOnThePage($answer)))->toBeTrue()
        ->and((string) $answer->getContent())->toContain('Give this number when you ask for help');
})->with([403, 404, 419, 429, 500, 503]);

it('shows it on the notice that staff see while a database update waits', function () {
    $this->mock(DatabaseUpdates::class, fn ($updates) => $updates->shouldReceive('isCurrent')->andReturnFalse());

    $answer = $this->actingAs($this->userWithRole('sales'))->get('/admin/leads')->assertStatus(503)->assertSee('Woodex OS is being updated');

    expect(numberOnThePage($answer))->toBe($answer->headers->get('X-Request-Id'))
        ->and(RequestNumber::looksLikeOne(numberOnThePage($answer)))->toBeTrue();
});

it('finds a fault in the log by the number on its error page', function () {
    $person = $this->userWithRole('sales');
    $answer = $this->actingAs($person)->get('/__fault?secret=in-the-query')->assertStatus(500);
    $number = numberOnThePage($answer);

    expect($number)->not->toBe('')->and((string) $answer->getContent())->not->toContain('A fault made on purpose');

    $found = array_values(array_filter(logLines($this->logFile), fn (array $line): bool => ($line['extra']['request_id'] ?? null) === $number));

    // Two lines: the fault itself, with where it happened, and the request it answered.
    expect($found)->toHaveCount(2);

    [$fault, $request] = $found;

    expect($fault['level_name'])->toBe('ERROR')
        ->and($fault['message'])->toBe('A fault made on purpose.')
        ->and($fault['context']['exception']['class'])->toBe(RuntimeException::class)
        ->and($fault['context']['exception']['trace'])->toBeArray()->not->toBeEmpty()
        ->and($fault['extra']['method'])->toBe('GET')
        ->and($fault['extra']['path'])->toBe('__fault')
        ->and($fault['extra']['user_id'])->toBe($person->id)
        ->and($fault['datetime'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d+[+-]\d\d:\d\d$/')
        ->and($request['level_name'])->toBe('ERROR')
        ->and($request['message'])->toBe('Request answered 500')
        ->and($request['context']['status'])->toBe(500)
        ->and($request['context']['ms'])->toBeInt()
        ->and((string) file_get_contents($this->logFile))->not->toContain('in-the-query');
});

it('finds a refusal in the log too, with the record by its number and never by its words', function () {
    $owner = $this->userWithRole('owner');
    $missing = Lead::query()->max('id') + 4321;
    $answer = $this->actingAs($owner)->get('/admin/leads/'.$missing.'?token=a-token-in-the-query')->assertNotFound();
    $number = numberOnThePage($answer);

    $found = array_values(array_filter(logLines($this->logFile), fn (array $line): bool => ($line['extra']['request_id'] ?? null) === $number));

    expect($found)->toHaveCount(1)
        ->and($found[0]['level_name'])->toBe('WARNING')
        ->and($found[0]['message'])->toBe('Request answered 404')
        ->and($found[0]['context']['status'])->toBe(404)
        ->and($found[0]['context']['ids'])->toBe(['lead' => $missing])
        ->and($found[0]['extra']['route'])->toBe('admin.leads.show')
        ->and($found[0]['extra']['path'])->toBe('admin/leads/{lead}')
        ->and($found[0]['extra']['user_id'])->toBe($owner->id)
        ->and((string) file_get_contents($this->logFile))->not->toContain('a-token-in-the-query');
});

it('never writes a token, a public address or an e-mail address that an address carries', function () {
    config(['woodex.logging.requests' => 'all', 'modules.enabled' => 'lead_api,client_portal']);

    $this->get('/admin/reset-password/THE-RESET-TOKEN-0123456789?email=someone%40example.com')->assertOk();
    $this->get('/q/THE-PUBLIC-ADDRESS-OF-A-QUOTATION?signature=abcdef')->assertNotFound();
    $this->get('/admin/no-such-screen/typed-by-somebody?email=someone%40example.com')->assertNotFound();

    $log = (string) file_get_contents($this->logFile);
    $lines = logLines($this->logFile);

    expect($lines)->toHaveCount(3)
        ->and($lines[0]['extra']['path'])->toBe('admin/reset-password/{token}')
        ->and($lines[0]['context'])->not->toHaveKey('ids')
        ->and($lines[1]['extra']['path'])->toBe('q/{quote}')
        ->and($lines[1]['context'])->not->toHaveKey('ids')
        // No route owns the third address: what was typed is written, cut, without its query.
        ->and($lines[2]['context']['path'])->toBe('/admin/no-such-screen/typed-by-somebody')
        ->and($log)->not->toContain('THE-RESET-TOKEN')
        ->and($log)->not->toContain('THE-PUBLIC-ADDRESS')
        ->and($log)->not->toContain('someone')
        ->and($log)->not->toContain('abcdef');
});

it('puts the number, the route and the person on a line the application writes while answering', function () {
    $person = $this->userWithRole('sales');
    $number = $this->actingAs($person)->get('/__says')->assertOk()->headers->get('X-Request-Id');

    $lines = logLines($this->logFile);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['message'])->toBe('Said while answering.')
        ->and($lines[0]['extra'])->toEqual(['request_id' => $number, 'method' => 'GET', 'route' => 'test.says', 'path' => '__says', 'user_id' => $person->id]);
});

it('learns who asks in the middle of a request, when the session is read or the person signs in', function (string $how) {
    // In the product nobody is known when a request begins: the person comes out of the session later.
    $person = $this->userWithRole('sales');
    auth()->logout();

    Route::middleware('web')->get('/__becomes-known', function () use ($person, $how) {
        $how === 'signs in' ? Auth::login($person) : Auth::setUser($person);
        Log::warning('Said once the person was known.');

        return 'ok';
    });

    $this->get('/__becomes-known')->assertOk();

    $lines = logLines($this->logFile);

    expect($lines)->toHaveCount(1)->and($lines[0]['extra']['user_id'] ?? null)->toBe($person->id);
})->with(['signs in', 'is read from the session']);

it('does not hand what one request knew to the next', function () {
    $this->actingAs($this->userWithRole('sales'))->withHeaders(['X-Request-Id' => 'the-first-callers-number'])->get('/__says')->assertOk();

    auth()->logout();
    $this->flushHeaders();
    $second = $this->get('/__says-as-anybody')->assertOk()->headers->get('X-Request-Id');

    $lines = logLines($this->logFile);

    expect($lines)->toHaveCount(2)
        ->and($lines[1]['extra'])->toEqual(['request_id' => $second, 'method' => 'GET', 'path' => '__says-as-anybody']);
});

it('keeps the number on what a queued job writes later', function () {
    config(['queue.default' => 'database']);

    $number = $this->get('/__queues')->assertOk()->headers->get('X-Request-Id');

    expect(logLines($this->logFile))->toBe([]);

    // Another process, later: it knows nothing of the request.
    Context::flush();
    expect($this->artisan('queue:work', ['--once' => true]))->toBe(0);

    $lines = logLines($this->logFile);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['message'])->toBe('Said later, from the queue.')
        ->and($lines[0]['extra']['request_id'])->toBe($number);
});

it('writes one line for a request by the setting: refusals and faults, every request, or none', function () {
    $this->get('/admin/login')->assertOk();
    expect(logLines($this->logFile))->toBe([]);                       // "errors", the default: a page is no line

    config(['woodex.logging.requests' => 'all']);
    $this->get('/admin/login')->assertOk();
    expect(logLines($this->logFile))->toHaveCount(1)
        ->and(logLines($this->logFile)[0]['level_name'])->toBe('INFO')
        ->and(logLines($this->logFile)[0]['message'])->toBe('Request answered 200');

    config(['woodex.logging.requests' => 'off']);
    $this->get('/admin/no-such-screen')->assertNotFound();
    expect(logLines($this->logFile))->toHaveCount(1);                 // nothing added

    config(['woodex.logging.requests' => 'something else']);
    $this->get('/admin/no-such-screen')->assertNotFound();
    expect(logLines($this->logFile))->toHaveCount(2)->and(RequestLog::mode())->toBe('errors');
});

it('answers the same when the log cannot be written', function () {
    // A folder the web server may not write to, a full disk: common on shared hosting. A page that does not
    // exist must still be answered as one, with its number, not as a failure.
    config(['logging.channels.single.path' => '/dev/null/nothing/can/be/written/here.log']);
    app('log')->forgetChannel('single');

    $answer = $this->get('/admin/no-such-screen')->assertNotFound();

    expect(numberOnThePage($answer))->toBe($answer->headers->get('X-Request-Id'))
        ->and(RequestNumber::looksLikeOne($answer->headers->get('X-Request-Id')))->toBeTrue();
});

it('names the screen part and the action of a Livewire call, never what was typed', function () {
    $request = Request::create('/livewire/update', 'POST', [
        'components' => [
            [
                'snapshot' => json_encode(['data' => ['phone' => '0300-TYPED-BY-A-PERSON'], 'memo' => ['name' => 'admin.crm.lead-show', 'path' => 'admin/leads/12']]),
                'updates' => ['note' => 'WORDS TYPED INTO A FORM'],
                'calls' => [['method' => 'save', 'params' => ['A TYPED PARAMETER']], ['method' => 'not a name; rm -rf', 'params' => []]],
            ],
            ['snapshot' => 'not json', 'calls' => []],
            ['snapshot' => json_encode(['memo' => ['name' => "a name\nwith a new line"]]), 'calls' => []],
        ],
    ]);
    $request->headers->set('X-Livewire', '1');

    RequestLog::write($request, new Response('', 403), microtime(true));

    $lines = logLines($this->logFile);
    $log = (string) file_get_contents($this->logFile);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['context']['livewire'])->toBe(['admin.crm.lead-show::save'])
        ->and($log)->not->toContain('TYPED')
        ->and($log)->not->toContain('rm -rf')
        ->and($log)->not->toContain('new line');
});

it('writes the log as one JSON object a line by default, with the trace of a fault, and as text when asked', function () {
    $read = function (?string $form): array {
        $form === null ? putenv('LOG_FORMAT') : putenv('LOG_FORMAT='.$form);
        unset($_ENV['LOG_FORMAT'], $_SERVER['LOG_FORMAT']);

        try {
            return (require config_path('logging.php'))['channels'];
        } finally {
            putenv('LOG_FORMAT');
        }
    };

    foreach (['single', 'daily', 'monthly', 'stderr'] as $channel) {
        expect($read(null)[$channel]['formatter'] ?? null)->toBe(JsonFormatter::class, $channel)
            ->and($read(null)[$channel]['formatter_with'] ?? null)->toBe(['includeStacktraces' => true], $channel)
            ->and($read('json')[$channel]['formatter'] ?? null)->toBe(JsonFormatter::class, $channel)
            ->and($read('text')[$channel])->not->toHaveKey('formatter', $channel);
    }
});

it('lets a form on the shop read the number of its request', function () {
    config(['cors.allowed_origins' => ['https://shop.example'], 'woodex.public_leads.allowed_origins' => ['https://shop.example']]);

    $answer = $this->withHeaders(['Origin' => 'https://shop.example'])->postJson('/api/v1/leads', []);

    expect(strtolower((string) $answer->headers->get('Access-Control-Expose-Headers')))->toContain('x-request-id')
        ->and(RequestNumber::looksLikeOne($answer->headers->get('X-Request-Id')))->toBeTrue();
});

it('shows a failed screen part the same page with its number, and without a script the screen would refuse', function () {
    $answer = $this->withHeaders(['X-Livewire' => '1'])->get('/__fault')->assertStatus(500);

    expect(numberOnThePage($answer))->toBe($answer->headers->get('X-Request-Id'))
        ->and(preg_match('/<script\b(?![^>]*\bsrc=)/i', (string) $answer->getContent()))->toBe(0);
});
