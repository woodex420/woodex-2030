<?php

use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Models\User;
use App\Support\Install\DatabaseUpdates;
use Database\Seeders\ProductionSeeder;
use Database\Seeders\QuoteTemplateSeeder;
use Database\Seeders\RateBookSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;

/*
| After a deploy with new migrations the owner applies them with one click (D-58); seeders never undo owner edits.
*/

function fakeDatabaseUpdates(array $pending): DatabaseUpdates
{
    $fake = new class(app('migrator'), app('cache')->store()) extends DatabaseUpdates
    {
        /** @var list<string> */
        public array $waiting = [];

        public int $applied = 0;

        public function pending(): array
        {
            return $this->waiting;
        }

        public function isCurrent(): bool
        {
            return $this->waiting === [];
        }

        public function apply(): string
        {
            $this->applied++;
            $this->waiting = [];

            return 'Migrated: 2099_01_01_000000_create_new_table';
        }
    };
    $fake->waiting = $pending;
    app()->instance(DatabaseUpdates::class, $fake);

    return $fake;
}

it('sees no pending migrations on a migrated database', function () {
    $updates = app(DatabaseUpdates::class);

    expect($updates->pending())->toBe([])
        ->and($updates->isCurrent())->toBeTrue();
});

it('sends the owner to the update page after a deploy with new migrations', function () {
    fakeDatabaseUpdates(['2099_01_01_000000_create_new_table']);
    $owner = $this->userWithRole('owner');

    $this->actingAs($owner)->get('/admin/leads')->assertRedirect(route('admin.system.updates'));
    $this->actingAs($owner)->get(route('admin.system.updates'))
        ->assertOk()
        ->assertSee('A database update is needed')
        ->assertSee('2099_01_01_000000_create_new_table');
});

it('applies the update with one click and opens the admin again', function () {
    $fake = fakeDatabaseUpdates(['2099_01_01_000000_create_new_table']);
    $owner = $this->userWithRole('owner');

    $this->actingAs($owner)->post(route('admin.system.updates.apply'))
        ->assertRedirect(route('admin.system.updates'))
        ->assertSessionHas('update_log');

    expect($fake->applied)->toBe(1);
    $this->actingAs($owner)->get('/admin/leads')->assertOk();
    $this->assertDatabaseHas('activity_log', ['description' => 'Applied database updates', 'causer_id' => $owner->id]);
});

it('shows staff a short notice while the update waits', function () {
    fakeDatabaseUpdates(['2099_01_01_000000_create_new_table']);

    $this->actingAs($this->userWithRole('sales'))->get('/admin/leads')
        ->assertStatus(503)
        ->assertSee('Woodex OS is being updated');
    $this->actingAs($this->userWithRole('sales'))->get(route('admin.system.updates'))->assertForbidden();
    $this->actingAs($this->userWithRole('sales'))->post(route('admin.system.updates.apply'))->assertForbidden();
});

it('lets the owner update before two-factor is set up, without a redirect loop', function () {
    config(['woodex.security.enforce_2fa' => true]);
    fakeDatabaseUpdates(['2099_01_01_000000_create_new_table']);
    $owner = $this->userWithRole('owner');

    $this->actingAs($owner)->get('/admin')->assertRedirect(route('admin.system.updates'));
    $this->actingAs($owner)->get(route('admin.system.updates'))->assertOk();
    $this->actingAs($owner)->post(route('admin.system.updates.apply'))->assertRedirect(route('admin.system.updates'));
    $this->actingAs($owner)->get('/admin')->assertOk(); // Owner enrolment is optional after updating too.
});

it('runs the real update (migrations, permissions, caches) from the button and over SSH', function () {
    // The real migrations and seeders; only the cache clear is recorded instead of deleting compiled views.
    $updates = new class(app('migrator'), app('cache')->store()) extends DatabaseUpdates
    {
        public int $cleared = 0;

        protected function clearCaches(): void
        {
            $this->cleared++;
        }
    };
    app()->instance(DatabaseUpdates::class, $updates);
    Permission::findByName('quotes.approve')->delete();

    expect($updates->apply())->toContain('Permissions, number sequences and the chart of accounts checked.')
        ->and(Permission::where('name', 'quotes.approve')->exists())->toBeTrue()
        ->and(Artisan::call('woodex:update'))->toBe(0)
        ->and($updates->cleared)->toBe(2)
        ->and($updates->isCurrent())->toBeTrue();
});

it('refuses to run two updates at once, and runs again once the first finishes', function () {
    $updates = new class(app('migrator'), app('cache')->store()) extends DatabaseUpdates
    {
        protected function clearCaches(): void {}
    };

    // Someone else's update is still in flight (the 17 Oct double-click).
    $lock = Cache::lock('woodex:database-update', 600);
    expect($lock->get())->toBeTrue();

    expect(fn (): string => $updates->apply())
        ->toThrow(RuntimeException::class, 'An update is already running');

    $lock->release();

    // With the lock free the very same call completes.
    expect($updates->apply())->toContain('Nothing to migrate');

    // And the lock is released by apply() itself, so a third call also works.
    expect($updates->apply())->toContain('Nothing to migrate');
});

it('reports pending migrations on the Health page', function () {
    $this->actingAs($this->userWithRole('owner'))->get(route('admin.system.health'))
        ->assertOk()
        ->assertSee('Database up to date')
        ->assertSee('Settings and uploads folder')
        ->assertSee('Front-end build');
});

it('never undoes owner edits when reference data is seeded again', function () {
    $item = CatalogItem::query()->where('rate_minor', '>', 0)->firstOrFail();
    $item->update(['rate_minor' => 123_400, 'is_active' => false]);
    $template = QuoteTemplate::query()->firstOrFail();
    $template->update(['name' => 'Renamed by the owner', 'is_active' => false]);
    $stage = PipelineStage::query()->where('key', 'negotiation')->firstOrFail();
    $stage->update(['name' => 'Final talks', 'probability' => 80]);
    $counts = [CatalogItem::count(), QuoteTemplate::count(), PipelineStage::count()];

    $this->seed(ProductionSeeder::class);
    $this->seed([RateBookSeeder::class, QuoteTemplateSeeder::class]);

    expect($item->fresh()->rate_minor)->toBe(123_400)
        ->and($item->fresh()->is_active)->toBeFalse()
        ->and($template->fresh()->name)->toBe('Renamed by the owner')
        ->and($template->fresh()->is_active)->toBeFalse()
        ->and($stage->fresh()->name)->toBe('Final talks')
        ->and([CatalogItem::count(), QuoteTemplate::count(), PipelineStage::count()])->toBe($counts);
});

it('still installs over SSH with woodex:install', function () {
    config(['woodex.owner' => ['name' => 'SSH Owner', 'email' => 'ssh@woodex.com.pk', 'password' => 'Ssh-Owner-2026']]);

    expect(Artisan::call('woodex:install', ['--no-interaction' => true]))->toBe(0)
        ->and(User::role('owner')->where('email', 'ssh@woodex.com.pk')->exists())->toBeTrue();
});
