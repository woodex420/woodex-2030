<?php

use App\Domain\System\Alerts\AlertCatalogue;
use App\Domain\System\Alerts\AlertRouter;
use App\Domain\System\Alerts\RoutedAlert;
use App\Domain\System\Models\AlertRule;
use App\Domain\System\Notifications\AgentReportNotification;
use App\Livewire\Admin\System\AlertRulesIndex;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| AUT-003 (P5 step S7, TGM-002, docs/02 §8): the alert-rules builder. No rule → the default
| routing, byte for byte what the OS shipped with. A rule takes over one event: who hears it, on
| which channels, and whether quiet hours hold; a critical rule reaches a muted chat at once.
*/

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->owner->assignRole('owner');

    AlertRule::query()->delete();
});

it('keeps the catalogue honest: unique keys, and only keys the router actually fires', function () {
    $keys = AlertCatalogue::keys();

    expect(count($keys))->toBe(count(array_unique($keys)))
        ->and($keys)->toContain('lead.new')
        ->and($keys)->toContain('payment.verified');

    // Every key the router is called with (grep the wiring) exists in the catalogue.
    $wired = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/AlertRouter::class\)->send\('([^']+)'/", (string) file_get_contents($file->getPathname()), $matches);
        $wired = [...$wired, ...$matches[1]];
    }

    expect($wired)->not->toBe([]);

    foreach (array_unique($wired) as $event) {
        expect($keys)->toContain($event);
    }
});

it('routes exactly as before when no rule exists', function () {
    $manager = User::factory()->create();
    $manager->assignRole('sales_manager');

    Notification::fake();

    $sent = app(AlertRouter::class)->send('system.agent_report', [$this->owner, $manager], new AgentReportNotification(
        kind: 'agent.seo',
        title: 'SEO health check',
        lines: ['✅ Everything looks healthy.'],
        url: '/admin',
    ));

    expect($sent)->toBe(2);
    Notification::assertSentTo([$this->owner, $manager], AgentReportNotification::class);
});

it('takes an event over: roles and named people, on the chosen channels only', function () {
    Notification::fake();

    $accountant = User::factory()->create(['email' => 'books@woodex.test']);
    $accountant->assignRole('accountant');
    $sales = User::factory()->create();
    $sales->assignRole('sales_manager');

    AlertRule::query()->create([
        'event' => 'system.agent_report',
        'name' => 'Accountants only, in-app',
        'recipients' => ['roles' => ['accountant'], 'users' => []],
        'channels' => ['database'],
        'respect_quiet_hours' => true,
        'priority' => 'normal',
    ]);

    $sent = app(AlertRouter::class)->send('system.agent_report', [$this->owner, $sales], new AgentReportNotification(
        kind: 'agent.seo',
        title: 'SEO health check',
        lines: ['🔍 12 published pages checked'],
        url: '/admin',
    ));

    // Only the accountant — the rule replaced the default recipients.
    expect($sent)->toBe(1);

    // The accountant hears the wrapped alert, in-app only; nobody else hears anything.
    Notification::assertSentTo($accountant, RoutedAlert::class,
        fn ($alert, $channels): bool => $channels === ['database']);
    Notification::assertNotSentTo($this->owner, RoutedAlert::class);
    Notification::assertNotSentTo($sales, RoutedAlert::class);
});

it('lets a critical rule reach a muted chat at once', function () {
    Notification::fake();

    $muted = User::factory()->create();
    $muted->assignRole('owner');
    $muted->telegramLink()->create([
        'chat_id' => 424242,
        'chat_type' => 'private',
        'code' => 'ABCDEF',
        'is_active' => true,
        'muted_until' => now()->addHours(6),
    ]);

    $normalRule = AlertRule::query()->create([
        'event' => 'payment.verified',
        'name' => 'Owners, normal',
        'recipients' => ['roles' => ['owner'], 'users' => []],
        'channels' => ['database', 'telegram'],
        'respect_quiet_hours' => true,
        'priority' => 'normal',
    ]);

    // The wrapper answers isCritical() for the Telegram channel's mute check.
    $routed = new RoutedAlert(
        new AgentReportNotification('agent.seo', 't', ['l'], '/admin'),
        ['database'],
        true,
    );
    expect($routed->isCritical())->toBeTrue();

    // Priority critical on the rule → the wrapper carries it.
    $normalRule->update(['priority' => 'critical']);
    $criticalRouted = null;
    Notification::fake();
    app(AlertRouter::class)->send('payment.verified', [], new AgentReportNotification('agent.seo', 't', ['l'], '/admin'));
    Notification::assertSentTo($muted, RoutedAlert::class, function ($alert) use (&$criticalRouted): bool {
        $criticalRouted = $alert;

        return true;
    });
    expect($criticalRouted?->isCritical())->toBeTrue();
});

it('builds rules from the screen: add, pause, edit, remove', function () {
    $component = Livewire::actingAs($this->owner)
        ->test(AlertRulesIndex::class)
        ->assertSee('default routing')
        ->assertSee('New lead');

    // A rule with nobody to hear it is refused.
    $component->call('addRule')
        ->set('event', 'lead.new')
        ->set('name', 'Owners only')
        ->set('roles', [])
        ->set('users', [])
        ->call('save')
        ->assertHasErrors('roles');

    expect(AlertRule::query()->count())->toBe(0);

    // A real one lands.
    $component->set('roles', ['owner'])
        ->set('channels', ['database', 'telegram'])
        ->set('priority', 'critical')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: "Rule added — it takes over this event's routing now.");

    $rule = AlertRule::query()->first();
    expect($rule)->not->toBeNull()
        ->and($rule->event)->toBe('lead.new')
        ->and($rule->recipients)->toBe(['roles' => ['owner'], 'users' => []])
        ->and($rule->channels)->toBe(['database', 'telegram'])
        ->and($rule->priority)->toBe('critical')
        ->and($rule->is_active)->toBeTrue();

    // Pausing returns the event to the default; resuming takes it over again.
    $component->call('toggleRule', $rule->id)
        ->assertDispatched('toast', message: 'Rule paused — the default routing applies again.');
    expect($rule->fresh()->is_active)->toBeFalse();

    $component->call('toggleRule', $rule->id);
    expect($rule->fresh()->is_active)->toBeTrue();

    // Editing round-trips the form.
    $component->call('editRule', $rule->id)
        ->assertSet('name', 'Owners only')
        ->set('name', 'Owners and accountants')
        ->set('roles', ['owner', 'accountant'])
        ->call('save');

    expect($rule->fresh()->name)->toBe('Owners and accountants')
        ->and($rule->fresh()->recipients['roles'])->toBe(['owner', 'accountant']);

    // Removing returns the event to its default.
    $component->call('removeRule', $rule->id)
        ->assertDispatched('toast', message: 'Rule removed — the default routing applies again.');
    expect(AlertRule::query()->count())->toBe(0);
});

it('keeps the builder to settings managers', function () {
    $staff = User::factory()->create();

    Livewire::actingAs($staff)
        ->test(AlertRulesIndex::class)
        ->assertStatus(403);
});
