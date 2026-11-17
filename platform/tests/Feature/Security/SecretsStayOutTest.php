<?php

declare(strict_types=1);

use App\Domain\System\Health\SystemHealthReport;
use App\Domain\System\Models\IntegrationLog;
use App\Integrations\Connectors\IntegrationConnection;
use App\Livewire\Admin\System\Integrations;
use App\Support\DebugGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use Symfony\Component\Finder\Finder;

/*
| Secrets in logs and error pages (document 10 §4; master list T-160).
|
| A password, a key or a token must not come back to a browser, and must not be written where it can be read
| later: an error page, the log file, the list of changes, the state a screen keeps in the page. Each test here
| takes one such way out and fails when it opens.
*/

const A_PASSWORD = 'Hunter2-correct-horse';
const A_DATABASE_PASSWORD = 'Sup3rS3cret-db-pass';
const A_TOKEN = 'ghp_T0kenThatMustNotComeBack0123456789';

/** An address that fails, with a secret in the fault and one in what was sent. */
function aFaultyAddress(): void
{
    Route::middleware('web')->any('/__fault', function (): never {
        throw new RuntimeException('SQLSTATE[HY000] [1045] Access denied: mysql://woodex:'.A_DATABASE_PASSWORD.'@db.internal/woodex');
    });
}

it('answers a fault with the admin\'s own page, and none of the fault', function () {
    aFaultyAddress();
    config(['app.debug' => false]);

    $page = $this->get('/__fault');

    $page->assertStatus(500)->assertSee('data-error-page="500"', false);

    foreach ([A_DATABASE_PASSWORD, 'SQLSTATE', 'RuntimeException', 'Access denied', 'db.internal', 'vendor/', '.php', 'Stack trace', '#0 '] as $detail) {
        expect($page->getContent())->not->toContain($detail);
    }

    $json = $this->getJson('/__fault')->assertStatus(500);

    expect($json->json())->toBe(['message' => 'Server Error']);
});

it('refuses the debugging page on a site that calls itself production, and tells the owner', function () {
    aFaultyAddress();

    // The settings file of a live site, with the developer's switch left on by mistake.
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.debug' => true]);

    DebugGuard::apply(app());

    expect(config('app.debug'))->toBeFalse()->and(DebugGuard::wasAsked())->toBeTrue();

    $page = $this->get('/__fault')->assertStatus(500);

    expect($page->getContent())->not->toContain(A_DATABASE_PASSWORD)->not->toContain('RuntimeException');

    // The health report still says that the settings file is wrong.
    $check = collect(app(SystemHealthReport::class)->checks())->firstWhere('label', 'Debug mode off in production');

    expect($check['ok'])->toBeFalse()->and($check['value'])->toBe('asked for in the settings, and refused');
});

it('leaves the switch alone on a developer\'s machine', function () {
    config(['app.debug' => true]);

    DebugGuard::apply(app());

    expect(config('app.debug'))->toBeTrue()->and(DebugGuard::wasAsked())->toBeFalse();
});

it('never gives a password back to the form or keeps it in the session', function () {
    $this->userWithRole('sales', ['email' => 'secret@woodex.test', 'password' => Hash::make(A_PASSWORD)]);

    $answer = $this->from('/admin/login')->post(route('login.store'), ['email' => 'secret@woodex.test', 'password' => A_PASSWORD.'-wrong']);

    $answer->assertRedirect('/admin/login')->assertSessionHasErrors('email');

    expect(session()->getOldInput())->not->toHaveKey('password')
        ->and(json_encode(session()->all()))->not->toContain(A_PASSWORD);

    expect($this->get('/admin/login')->getContent())->not->toContain(A_PASSWORD);
});

it('writes a fault to the log without what the browser sent', function () {
    aFaultyAddress();
    config(['app.debug' => false]);
    Log::getLogger()->pushHandler($log = new TestHandler);

    $this->post('/__fault', ['email' => 'secret@woodex.test', 'password' => A_PASSWORD, 'token' => A_TOKEN, 'code' => '482913'])->assertStatus(500);

    $written = json_encode(array_map(fn ($record): array => $record->toArray(), $log->getRecords()));

    // The fault is there, so that it can be found…
    expect($log->hasErrorRecords())->toBeTrue()
        // …and nothing of the form that was sent with it.
        ->and($written)->not->toContain(A_PASSWORD)->not->toContain(A_TOKEN)->not->toContain('482913');
});

it('hands out no person with their password, their sign-in token or their second-step secret', function () {
    $person = $this->userWithRole('sales', ['password' => Hash::make(A_PASSWORD)]);
    $person->forceFill(['remember_token' => 'remember-me-token-0123456789', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb']))])->save();
    $stored = DB::table('users')->where('id', $person->id)->first();

    foreach ([$person->fresh()->toArray(), json_decode((string) json_encode($person->fresh()), true), json_decode($person->fresh()->toJson(), true)] as $shown) {
        expect($shown)->not->toHaveKey('password')->not->toHaveKey('remember_token')->not->toHaveKey('two_factor_secret')->not->toHaveKey('two_factor_recovery_codes');
        expect(json_encode($shown))->not->toContain($stored->password)->not->toContain('remember-me-token');
    }

    // The password is kept as a hash, never as it was typed.
    expect($stored->password)->not->toBe(A_PASSWORD)->and(Hash::check(A_PASSWORD, $stored->password))->toBeTrue();
});

it('keeps a changed password out of the list of changes', function () {
    $person = $this->userWithRole('sales', ['password' => Hash::make(A_PASSWORD)]);
    $this->actingAs($this->userWithRole('owner'));

    $person->update(['password' => A_PASSWORD.'-new', 'name' => 'A new name']);

    $changes = json_encode(DB::table('activity_log')->get()->all());

    expect($changes)->not->toContain(A_PASSWORD)->not->toContain((string) $person->fresh()->password);
});

it('hides every column that holds a secret when a record is handed out', function () {
    // A record that is turned into an array goes to a browser, into a log, or into a queue. Every column whose
    // name says it holds a secret must be left out there. The few names that only look like one are listed.
    $notSecrets = [
        'bot_usages.tokens_in', 'bot_usages.tokens_out',            // counts of words
        'commerce_products.source_hash', 'storefront_quote_requests.payload_hash',  // fingerprints of content
        'webhook_events.signature_valid',                           // yes or no
    ];
    $shown = [];

    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $model = new $class;

        if (! Schema::hasTable($model->getTable())) {
            continue;
        }

        foreach (Schema::getColumnListing($model->getTable()) as $column) {
            $looksSecret = preg_match('/password|secret|token|recovery|credential|api_key|private_key|otp|code_hash|_hash$|signature|salt/i', $column) === 1;

            if ($looksSecret && ! in_array($column, $model->getHidden(), true) && ! in_array($model->getTable().'.'.$column, $notSecrets, true)) {
                $shown[] = class_basename($class).': '.$column;
            }
        }
    }

    expect($shown)->toBe([]);
});

it('keeps a provider\'s key out of the page, out of the log, and unreadable where it is stored', function () {
    Http::fake(['api.github.com/user' => Http::response(['login' => 'woodex420', 'id' => 1], 200, ['X-OAuth-Scopes' => 'repo'])]);
    $this->actingAs($this->userWithRole('owner'));

    $screen = Livewire::test(Integrations::class)
        ->call('openConnector', 'github')
        ->set('connectorInput.token', A_TOKEN)
        ->call('connectConnector')
        ->assertHasNoErrors();

    // The box is emptied: the key does not stay in the state the page carries, nor in what is drawn.
    expect($screen->get('connectorInput'))->toBe([])
        ->and($screen->html())->not->toContain(A_TOKEN)
        ->and(json_encode($screen->snapshot))->not->toContain(A_TOKEN);

    // Stored so that the database alone does not give it away, and never handed out with the record.
    $row = DB::table('integration_connections')->where('provider', 'github')->first();
    $connection = IntegrationConnection::query()->where('provider', 'github')->sole();

    expect((string) $row->credentials)->not->toContain(A_TOKEN)
        ->and(json_encode($connection))->not->toContain(A_TOKEN)
        ->and($connection->toArray())->not->toHaveKey('credentials')
        // …while the application itself can still use it.
        ->and(json_encode($connection->credentials))->toContain(A_TOKEN);

    // Nothing that was written about the call carries it.
    expect(json_encode(IntegrationLog::query()->get()->toArray()))->not->toContain(A_TOKEN)
        ->and(json_encode(DB::table('activity_log')->get()->all()))->not->toContain(A_TOKEN);
});
