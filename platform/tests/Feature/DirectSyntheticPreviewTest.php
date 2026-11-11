<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Woodex\Sandbox\NativeDirectPreview;

// The tool is deliberately outside application autoload/bootstrap, including on hosted CI.
$directToolRoot = __DIR__;
while (! is_file($directToolRoot.'/tools/sandbox/NativeDirectPreview.php') && dirname($directToolRoot) !== $directToolRoot) {
    $directToolRoot = dirname($directToolRoot);
}
require_once $directToolRoot.'/tools/sandbox/NativeDirectPreview.php';

function configureDirectPreviewFixture(): void
{
    config(['preview.synthetic' => true, 'preview.direct_admin' => true, 'mail.default' => 'array', 'queue.default' => 'database']);
}

function attachDirectPreviewForTest(): void
{
    app(Kernel::class)->prependToMiddlewarePriority(NativeDirectPreview::class);
    app(Router::class)->prependMiddlewareToGroup('web', NativeDirectPreview::class);
}

it('never installs direct access through normal application bootstrap even if the demo flag is enabled', function () {
    configureDirectPreviewFixture();
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
    $root = dirname((new ReflectionClass(NativeDirectPreview::class))->getFileName(), 3);
    foreach (['platform/public/index.php', 'platform/bootstrap/app.php', 'platform/app/Providers/AppServiceProvider.php'] as $file) {
        expect(file_get_contents($root.'/'.$file))->not->toContain('NativeDirectPreview');
    }
});

it('refuses to install into a production application tree', function () {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('basePath')->once()->andReturn('/var/www/production/platform');
    expect(fn () => NativeDirectPreview::install($application))->toThrow(RuntimeException::class, 'private native preview clone');
});

it('requires the explicit direct flag and non-sending isolated configuration', function (string $key, mixed $value) {
    configureDirectPreviewFixture();
    $before = config($key);
    try {
        config([$key => $value]);
        expect(NativeDirectPreview::allowed())->toBeFalse();
    } finally {
        config([$key => $before]);
    }
})->with([
    ['preview.direct_admin', false], ['preview.synthetic', false], ['mail.default', 'smtp'], ['queue.default', 'sync'],
    ['database.default', 'mysql'], ['database.connections.sqlite.driver', 'mysql'],
    ['database.connections.sqlite.url', 'mysql://example.invalid/foreign'],
    ['database.connections.sqlite.read', ['database' => 'foreign']], ['database.connections.sqlite.write', ['database' => 'foreign']],
    ['database.connections.sqlite.database', '/not-an-isolated-fixture.sqlite'],
]);

it('refuses production and staging environments even with all flags enabled', function (string $environment) {
    configureDirectPreviewFixture();
    app()->detectEnvironment(fn () => $environment);
    expect(NativeDirectPreview::allowed())->toBeFalse();
})->with(['production', 'staging']);

it('uses only a request-scoped demo identity and keeps the transparent welcome page anonymous', function () {
    configureDirectPreviewFixture();
    $this->userWithRole('owner', ['email' => 'owner@woodex.test']);
    attachDirectPreviewForTest();
    if (config('database.default') !== 'sqlite') {
        // Genuine hosted MySQL must never become a direct-access demo, even in testing.
        $this->get(route('admin.dashboard'))->assertServiceUnavailable();

        return;
    }
    $this->get(route('admin.dashboard'))->assertOk()->assertHeader('X-Woodex-Preview', 'synthetic-direct')
        ->assertSee('Admin demo · no sign-in · NOT PRODUCTION')->assertSee('View transparent welcome page');
    $this->assertGuest();
    expect(session()->has(auth()->guard('web')->getName()))->toBeFalse();
    $this->get(route('login'))->assertOk()->assertSee('Sign-in is paused in this isolated demo.')
        ->assertSee('Open updated admin')->assertDontSee('data-sign-in data-', false)->assertDontSee('Select Sign in to continue');
    $this->assertGuest();
});

it('does not substitute a missing inactive two-factor or foreign-staff identity', function (string $condition) {
    configureDirectPreviewFixture();
    if ($condition !== 'missing') {
        $attributes = ['email' => 'owner@woodex.test'];
        if ($condition === 'inactive') {
            $attributes['is_active'] = false;
        }
        if ($condition === 'two-factor') {
            $attributes['two_factor_secret'] = encrypt('JBSWY3DPEHPK3PXP');
            $attributes['two_factor_confirmed_at'] = now();
        }
        $this->userWithRole('owner', $attributes);
        if ($condition === 'foreign-staff') {
            User::factory()->create(['email' => 'foreign@example.test']);
        }
    }
    attachDirectPreviewForTest();
    $this->get(route('admin.dashboard'))->assertServiceUnavailable();
    $this->assertGuest();
})->with(['missing', 'inactive', 'two-factor', 'foreign-staff']);
