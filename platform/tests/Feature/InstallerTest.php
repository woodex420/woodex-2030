<?php

use App\Models\User;
use App\Support\Install\Installer;
use App\Support\Install\ProbesDatabase;
use App\Support\Install\SiteState;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Hash;

/*
| Web installer for Hostinger Git deploys without SSH (D-58). The site under test is a temporary Hostinger-like
| layout: ~/domains/site.test/public_html/platform (no .env) and the data folder ~/woodex-os/site.test.
*/

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/woodex-installer-'.bin2hex(random_bytes(4));
    $this->home = $this->sandbox.'/home/u123';
    $this->data = $this->home.'/woodex-os/site.test';
    mkdir($this->home.'/domains/site.test/public_html/platform', 0777, true);

    $this->app->instance(SiteState::class, new SiteState($this->home.'/domains/site.test/public_html/platform', $this->home, 'u123'));
});

afterEach(function () {
    $remove = function (string $path) use (&$remove): void {
        if (is_dir($path) && ! is_link($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                $remove($path.'/'.$entry);
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    };
    $remove($this->sandbox);
});

function setupCode(string $folder): string
{
    preg_match('/[A-Z2-9]{4}(?:-[A-Z2-9]{4}){5}/', (string) file_get_contents($folder.'/setup-code.txt'), $match);

    return $match[0];
}

function installerForm(array $overrides = []): array
{
    return [
        'app_url' => 'https://interior.woodex.com.pk',
        'connection' => 'sqlite',
        'owner_name' => 'Imtiaz Ahmad',
        'owner_email' => 'Owner@Woodex.com.pk',
        'owner_password' => 'Woodex-Owner-2026',
        'owner_password_confirmation' => 'Woodex-Owner-2026',
        'enforce_2fa' => '1',
        ...$overrides,
    ];
}

it('sends every page of a freshly deployed site to the installer', function () {
    $this->get('/admin/login')->assertRedirect('http://localhost/install');
    $this->get('/')->assertRedirect('http://localhost/install');
    $this->get('/up')->assertOk();
});

it('shows the installer and writes a setup code into the private data folder', function () {
    $this->get('/install')
        ->assertOk()
        ->assertSee('Install Woodex OS')
        ->assertSee('woodex-os/site.test/setup-code.txt')
        ->assertDontSee($this->home);

    expect(fileperms($this->data.'/setup-code.txt') & 0777)->toBe(0600);

    $first = setupCode($this->data);
    $this->get('/install')->assertOk();
    expect(setupCode($this->data))->toBe($first); // reloading keeps the same code
});

it('puts a copy of the setup code in the site folder, where File Manager opens', function () {
    $site = $this->home.'/domains/site.test/public_html';

    $this->get('/install')
        ->assertOk()
        ->assertSee('public_html/WOODEX-SETUP-CODE.txt')
        ->assertSee('woodex-os/site.test/setup-code.txt');

    $code = setupCode($this->data);
    expect((string) file_get_contents($site.'/WOODEX-SETUP-CODE.txt'))->toContain($code)
        ->and(fileperms($site.'/WOODEX-SETUP-CODE.txt') & 0777)->toBe(0600);

    // A deploy replaces the site folder: the next visit writes the copy again, with the same code.
    unlink($site.'/WOODEX-SETUP-CODE.txt');
    $this->get('/install')->assertOk();
    expect((string) file_get_contents($site.'/WOODEX-SETUP-CODE.txt'))->toContain($code)
        ->and(setupCode($this->data))->toBe($code);

    // The copy proves nothing on its own: only the private code counts.
    file_put_contents($site.'/WOODEX-SETUP-CODE.txt', 'AAAA-BBBB-CCCC-DDDD-EEEE-FFFF');
    $this->post('/install', installerForm(['setup_code' => 'AAAA-BBBB-CCCC-DDDD-EEEE-FFFF']))->assertStatus(422);
});

/** A MySQL server stand-in: php-wasm (AI sandbox) has no sockets, and the rule only needs what MySQL answers. */
function fakeMysql(?string $authenticatedAs = null): void
{
    app()->instance(ProbesDatabase::class, new class($authenticatedAs) implements ProbesDatabase
    {
        public function __construct(private readonly ?string $authenticatedAs) {}

        public function probe(array $db): array
        {
            return ['version' => '8.0.36', 'tables' => [], 'user' => $this->authenticatedAs ?? $db['username'].'@localhost'];
        }
    });
}

function mysqlForm(array $overrides = []): array
{
    return installerForm(['setup_code' => '', 'connection' => 'mysql', 'db_host' => 'localhost', 'db_port' => '3306',
        'db_database' => 'u123_woodex', 'db_username' => 'u123_woodex', 'db_password' => 'Db-Secret-2026', ...$overrides]);
}

it('accepts this hosting account\'s local MySQL user instead of the setup code', function () {
    fakeMysql();
    $this->get('/install')->assertSee('Cannot find the file?');
    $code = setupCode($this->data);

    // Step 1 without a code; step 2 gets the real code (it checks the code again).
    $this->post('/install', mysqlForm())
        ->assertOk()
        ->assertSee('Finish installation')
        ->assertSee($code);

    expect((string) file_get_contents($this->data.'/.env'))->toContain('mysql')->toContain('u123_woodex');
});

it('never takes a remote, foreign, anonymous or password-less database as proof', function (array $form, ?string $authenticatedAs) {
    fakeMysql($authenticatedAs);
    $this->get('/install');

    $this->post('/install', mysqlForm($form))
        ->assertStatus(422)
        ->assertSee('Enter the setup code from the file named above');

    expect(file_exists($this->data.'/.env'))->toBeFalse();
})->with([
    'remote host (any user name can be made there)' => [['db_host' => 'db.example.com'], null],
    'another account\'s user' => [['db_username' => 'u999_woodex', 'db_database' => 'u999_woodex'], null],
    'same digits, longer account' => [['db_username' => 'u1234_woodex'], null],
    'no account prefix' => [['db_username' => 'woodex'], null],
    'no password' => [['db_password' => ''], null],
    'SQLite' => [['connection' => 'sqlite'], null],
    'anonymous MySQL login accepting any name' => [[], '@localhost'],
]);

it('treats odd input (arrays instead of text) as empty, without an error page', function () {
    $this->get('/install');

    $this->post('/install', installerForm(['setup_code' => ['x'], 'connection' => 'mysql', 'db_host' => ['localhost'], 'db_username' => ['u123_x']]))
        ->assertStatus(422);
    expect(file_exists($this->data.'/.env'))->toBeFalse();
});

it('stops guessing after 10 failed proofs for 10 minutes', function () {
    $this->get('/install');
    $code = setupCode($this->data);

    foreach (range(1, 10) as $attempt) {
        $this->post('/install', installerForm(['setup_code' => 'AAAA-BBBB-CCCC-DDDD-EEEE-FFFF']))->assertStatus(422);
    }

    $this->post('/install', installerForm(['setup_code' => $code]))->assertStatus(429)->assertSee('Too many attempts');
    expect(file_exists($this->data.'/.env'))->toBeFalse();

    $this->travel(11)->minutes();
    $this->post('/install', installerForm(['setup_code' => $code]))->assertOk()->assertSee('Finish installation');
});

it('only offers the database route when the hosting account is known', function () {
    $this->app->instance(SiteState::class, new SiteState($this->home.'/domains/site.test/public_html/platform', $this->home, 'root'));

    $this->get('/install')
        ->assertOk()
        ->assertDontSee('Cannot find the file?');

    fakeMysql();
    $this->post('/install', mysqlForm())->assertStatus(422);
    expect(file_exists($this->data.'/.env'))->toBeFalse();
});

it('names only the private file when the site folder is not writable', function () {
    $site = $this->home.'/domains/site.test/public_html';
    chmod($site, 0555);

    try {
        $this->get('/install')
            ->assertOk()
            ->assertDontSee('WOODEX-SETUP-CODE.txt')
            ->assertSee('Access all files of hosting')
            ->assertSee('woodex-os/site.test/setup-code.txt');
    } finally {
        chmod($site, 0755);
    }
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores folder permissions');

it('refuses a wrong setup code and writes nothing', function () {
    $this->get('/install');

    $this->post('/install', installerForm(['setup_code' => 'AAAA-BBBB-CCCC-DDDD-EEEE-FFFF']))
        ->assertStatus(422)
        ->assertSee('The setup code does not match');

    expect(file_exists($this->data.'/.env'))->toBeFalse();
});

it('validates the form without echoing passwords', function () {
    $this->get('/install');

    $this->post('/install', installerForm([
        'setup_code' => setupCode($this->data),
        'connection' => 'mysql',
        'owner_password_confirmation' => 'something-else-1',
    ]))
        ->assertStatus(422)
        ->assertSee('The two owner passwords do not match.')
        ->assertSee('The database name field is required.')
        ->assertDontSee('Woodex-Owner-2026');
});

it('explains a database it cannot use', function () {
    $this->get('/install');

    $this->post('/install', installerForm([
        'setup_code' => setupCode($this->data),
        'connection' => 'mysql',
        'db_host' => '127.0.0.1',
        'db_port' => '1',
        'db_database' => 'u123_woodex',
        'db_username' => 'u123_woodex',
        'db_password' => 'x',
    ]))->assertStatus(422)->assertSeeText('Please check');

    expect(file_exists($this->data.'/.env'))->toBeFalse();
})->skip(str_starts_with(php_uname('s'), 'Emscripten'), 'php-wasm (AI sandbox) has no network sockets for MySQL');

it('installs in two steps and closes itself', function () {
    $this->get('/install');
    $code = setupCode($this->data);

    // Step 1: settings. The code is accepted in lower case and with spaces.
    $this->post('/install', installerForm(['setup_code' => ' '.strtolower($code).' ']))
        ->assertOk()
        ->assertSee('Creating the database tables')
        ->assertSee('name="setup_code" value="', false);

    $env = Dotenv::parse((string) file_get_contents($this->data.'/.env'));
    expect(fileperms($this->data.'/.env') & 0777)->toBe(0600)
        ->and($env['APP_ENV'])->toBe('production')
        ->and($env['APP_DEBUG'])->toBe('false')
        ->and($env['APP_KEY'])->toStartWith('base64:')
        ->and(strlen(base64_decode(substr($env['APP_KEY'], 7))))->toBe(32)
        ->and($env['APP_URL'])->toBe('https://interior.woodex.com.pk')
        ->and($env['ADMIN_PATH'])->toBe('admin')
        ->and($env['ENFORCE_2FA'])->toBe('true')
        ->and($env['SESSION_SECURE_COOKIE'])->toBe('true')
        ->and($env['DB_CONNECTION'])->toBe('sqlite')
        ->and($env['DB_DATABASE'])->toBe($this->data.'/database.sqlite')
        ->and($env['PUBLIC_LEADS_ALLOWED_ORIGINS'])->toContain('https://interior.woodex.com.pk')
        ->and(is_dir($this->data.'/storage/framework/views'))->toBeTrue()
        ->and(file_exists($this->home.'/domains/site.test/public_html/platform/.env'))->toBeFalse();

    // The owner password waits for step 2 only as a hash.
    $pending = (string) file_get_contents($this->data.'/install-pending.json');
    expect($pending)->not->toContain('Woodex-Owner-2026');

    // Until step 2 has run, pages keep leading to the installer, which asks for the code again.
    $this->get('/admin/login')->assertRedirect('http://localhost/install');
    $this->get('/install')->assertOk()->assertSee('Finish installation');
    $this->post('/install', installerForm(['setup_code' => $code]))->assertNotFound();
    $this->post('/install/finish', ['setup_code' => 'AAAA-BBBB-CCCC-DDDD-EEEE-FFFF'])->assertStatus(422);

    // Step 2: tables, reference data and the owner.
    $this->post('/install/finish', ['setup_code' => $code])
        ->assertOk()
        ->assertSee('Woodex OS is installed')
        ->assertSee('owner@woodex.com.pk')
        ->assertSee('artisan schedule:run')
        ->assertSee('woodex-os/site.test/.env');

    $owner = User::query()->where('email', 'owner@woodex.com.pk')->firstOrFail();
    expect($owner->hasRole('owner'))->toBeTrue()
        ->and($owner->is_active)->toBeTrue()
        ->and(Hash::check('Woodex-Owner-2026', $owner->password))->toBeTrue()
        ->and(file_exists($this->data.'/setup-code.txt'))->toBeFalse()
        ->and(file_exists($this->home.'/domains/site.test/public_html/WOODEX-SETUP-CODE.txt'))->toBeFalse()
        ->and(file_exists($this->data.'/install-pending.json'))->toBeFalse();

    // Closed for good.
    $this->get('/install')->assertNotFound();
    $this->post('/install/finish', ['setup_code' => $code])->assertNotFound();
    $this->get('/admin/login')->assertOk();
});

it('keeps an existing owner when it is pointed at an installed database', function () {
    $existing = $this->userWithRole('owner', ['email' => 'first@woodex.com.pk']);
    $this->get('/install');
    $code = setupCode($this->data);
    $this->post('/install', installerForm(['setup_code' => $code]))->assertOk();

    $this->post('/install/finish', ['setup_code' => $code])
        ->assertOk()
        ->assertSee('already had an owner account');

    expect(User::role('owner')->pluck('id')->all())->toBe([$existing->id]);
});

it('suggests the cron command for the PHP the site runs', function () {
    expect(app(Installer::class)->cronCommand())->toEndWith('/domains/site.test/public_html/platform/artisan schedule:run');
});
