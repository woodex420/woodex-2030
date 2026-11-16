<?php

namespace Tests;

use App\Models\User;
use App\Support\Install\SiteState;
use Database\Seeders\ProductionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Illuminate\Filesystem\Filesystem;

abstract class TestCase extends BaseTestCase
{
    /**
     * Feature tests run against seeded reference data (roles, stages, numbering, rate book, templates).
     *
     * @var bool
     */
    protected $seed = true;

    /**
     * @var class-string
     */
    protected $seeder = ProductionSeeder::class;

    private ?string $installedSiteFixture = null;

    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        // Configure the environment loader BEFORE kernel bootstrap. No attempted reads from a
        // developer/hosting .env, and no suppression of dotenv's missing-file warnings.
        if ($this->installedSiteFixture === null) {
            $this->installedSiteFixture = sys_get_temp_dir().'/woodex-feature-state-'.bin2hex(random_bytes(8));
            $site = $this->fixtureSite();
            $directory = $site->externalDirectory();
            (new Filesystem)->makeDirectory($directory, 0700, recursive: true);
            file_put_contents($directory.'/.env', "# Synthetic installed-state marker only; QA runtime comes from process environment.\n");
            chmod($directory.'/.env', 0600);
        }

        $site = $this->fixtureSite();
        $app->useEnvironmentPath($site->environmentDirectory());
        $app->instance(SiteState::class, $site);

        // Preserve Laravel's normal trait/cached-state lifecycle when customizing application boot.
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app);
        }
        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
            $app->booting(fn () => $this->markRoutesCached($app));
        }
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private function fixtureSite(): SiteState
    {
        return new SiteState($this->installedSiteFixture.'/domains/feature.test/public_html/platform', $this->installedSiteFixture);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // This suite asserts integer exit codes and subsequent DB effects. Execute commands now,
        // rather than leaving a PendingCommand to run during destruction after app teardown.
        $this->withoutMockingConsoleOutput();

        // Feature tests never depend on built front-end assets.
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->installedSiteFixture !== null) {
                (new Filesystem)->deleteDirectory($this->installedSiteFixture);
                $this->installedSiteFixture = null;
            }
        }
    }

    protected function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
