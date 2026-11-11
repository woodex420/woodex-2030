<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\SyntheticPreview;
use Database\Seeders\SyntheticPreviewSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config([
        'preview.synthetic' => true,
        'preview.show_credentials' => true,
        'preview.login_email' => 'owner@woodex.test',
        'preview.login_password' => 'Synthetic-fixture-only-103',
    ]);
});

it('prefills only opted-in isolated SQLite credentials and still requires normal authentication', function () {
    $sqlite = config('database.default') === 'sqlite';
    expect(SyntheticPreview::credentials() !== null)->toBe($sqlite);
    $response = $this->get(route('login'))->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    if ($sqlite) {
        $response->assertSee('Synthetic-fixture-only-103')->assertSee('value="Synthetic-fixture-only-103"', false)
            ->assertSee('No automatic login.')->assertSee('data-show-password', false);
    } else {
        $response->assertDontSee('Synthetic-fixture-only-103');
    }
    $this->assertGuest();
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

it('never exposes a configured preview password on production or staging', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);
    expect(SyntheticPreview::credentials())->toBeNull();
    $this->get(route('login'))->assertOk()->assertDontSee('Synthetic-fixture-only-103')->assertDontSee('owner@woodex.test');
})->with(['production', 'staging']);

it('fails closed when an exposure gate is missing', function (array $override) {
    config($override);
    expect(SyntheticPreview::credentials())->toBeNull();
    $this->get(route('login'))->assertOk()->assertDontSee('Synthetic-fixture-only-103');
})->with([
    'not opted in' => [['preview.synthetic' => false]],
    'display not opted in' => [['preview.show_credentials' => false]],
    'ordinary database' => [['database.connections.sqlite.database' => '/does-not-exist/ordinary.sqlite']],
    'real address' => [['preview.login_email' => 'owner@example.com']],
    'invalid address' => [['preview.login_email' => 'invalid@woodex.test@woodex.test']],
    'empty password' => [['preview.login_password' => '']],
    'short password' => [['preview.login_password' => 'short']],
]);

it('rejects a synthetic-looking symlink to an ordinary database', function () {
    $directory = sys_get_temp_dir().'/woodex-demo-'.bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    touch($directory.'/ordinary.sqlite');
    symlink($directory.'/ordinary.sqlite', $directory.'/woodex-synthetic-link.sqlite');
    try {
        config(['database.connections.sqlite.database' => $directory.'/woodex-synthetic-link.sqlite']);
        expect(SyntheticPreview::isolatedDatabase())->toBeFalse()->and(SyntheticPreview::credentials())->toBeNull();
    } finally {
        unlink($directory.'/woodex-synthetic-link.sqlite');
        unlink($directory.'/ordinary.sqlite');
        rmdir($directory);
    }
});

it('seeds only three role-specific synthetic accounts idempotently', function () {
    config(['mail.default' => 'array', 'queue.default' => 'database']);
    if (config('database.default') !== 'sqlite') {
        expect(fn () => app(SyntheticPreviewSeeder::class)->run())->toThrow(RuntimeException::class);
        expect(User::query()->count())->toBe(0);

        return;
    }
    app(SyntheticPreviewSeeder::class)->run();
    app(SyntheticPreviewSeeder::class)->run();
    expect(User::query()->count())->toBe(3);
    foreach (['owner@woodex.test' => 'owner', 'manager@woodex.test' => 'sales_manager', 'accounts@woodex.test' => 'accountant'] as $email => $role) {
        $user = User::query()->where('email', $email)->firstOrFail();
        expect($user->hasRole($role))->toBeTrue()->and($user->is_active)->toBeTrue()
            ->and(Hash::check('Synthetic-fixture-only-103', $user->password))->toBeTrue();
    }
});

it('refuses synthetic account writes with a sending mailer or executable queue', function (array $override) {
    config(['mail.default' => 'array', 'queue.default' => 'database']);
    config($override);
    expect(fn () => app(SyntheticPreviewSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);
})->with([
    'sending transport' => [['mail.default' => 'smtp']],
    'synchronous jobs' => [['queue.default' => 'sync']],
]);

it('refuses synthetic account seeding when non-preview staff exist', function () {
    $this->userWithRole('owner', ['email' => 'installed-owner@example.com']);
    config(['mail.default' => 'array', 'queue.default' => 'database']);
    expect(fn () => app(SyntheticPreviewSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(1);
});

it('shares actual filled vector geometry across the app mark and favicons without embedded rasters', function () {
    $svg = file_get_contents(public_path('assets/brand/woodex-mark.svg'));
    preg_match('/<path d="([^"]+)"/', $svg, $match);
    expect($match[1] ?? '')->not->toBeEmpty();
    expect(file_get_contents(resource_path('views/components/brand-mark.blade.php')))->toContain($match[1])->toContain('#b49a73');
    foreach (['favicon.svg', 'assets/img/favicon.svg', 'assets/brand/woodex-os.svg', 'assets/brand/woodex-interior.svg'] as $file) {
        $asset = file_get_contents(public_path($file));
        expect($asset)->toContain($match[1])->toContain('#b49a73')->not->toContain('<image', 'base64,');
    }
});
