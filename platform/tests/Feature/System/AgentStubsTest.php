<?php

use App\Console\Commands\SeoAgent;
use App\Console\Commands\SocialAgent;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Models\Project;
use App\Domain\System\Models\Automation;
use App\Domain\System\Notifications\AgentReportNotification;
use App\Domain\Website\Models\Redirect;
use App\Integrations\Telegram\TelegramChannel;
use App\Livewire\Admin\System\AutomationsIndex;
use App\Models\User;
use Database\Factories\Website\PageFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

/*
| P8.5's dormant agents, the last piece: the SEO health check and the social post ideas are
| read-only digests for the owner — they never post, never edit, never name a client — and they
| ship DORMANT (A0): the switch is off until the owner turns it on from the Automations screen.
*/

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->owner->assignRole('owner');
});

it('ships both agents dormant while every other automation stays on by default', function () {
    expect(Automation::query()->count())->toBe(0)
        ->and(Automation::enabled('woodex:seo-agent'))->toBeFalse()
        ->and(Automation::enabled('woodex:social-agent'))->toBeFalse()
        // The fail-safe for everything else is unchanged: a missing row means enabled.
        ->and(Automation::enabled('woodex:sla-check'))->toBeTrue()
        ->and(Automation::enabled('woodex:monthly-report'))->toBeTrue()
        ->and(Automation::defaultEnabled('woodex:seo-agent'))->toBeFalse()
        ->and(Automation::defaultEnabled('woodex:sla-check'))->toBeTrue();
});

it('shows the dormant agents on the dashboard and wakes one with the switch', function () {
    $test = Livewire::actingAs($this->owner)
        ->test(AutomationsIndex::class)
        ->assertOk()
        ->assertSee('SEO health check')
        ->assertSee('Social post ideas')
        ->assertSee('Dormant — off by default')
        ->assertSee('Switch on')
        ->assertSee('Has never run — it is dormant until you switch it on');

    $test->call('toggle', 'woodex:seo-agent')->assertHasNoErrors();

    expect(Automation::enabled('woodex:seo-agent'))->toBeTrue()
        ->and(Automation::enabled('woodex:social-agent'))->toBeFalse(); // the other one stays asleep

    // Switching off again is an ordinary pause — the row exists now.
    $test->call('toggle', 'woodex:seo-agent');

    expect(Automation::enabled('woodex:seo-agent'))->toBeFalse()
        ->and(Automation::query()->where('key', 'woodex:seo-agent')->count())->toBe(1);
});

it('keeps the dormant agents out of the schedule until they are switched on', function () {
    foreach (['woodex:seo-agent', 'woodex:social-agent'] as $key) {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, $key));

        expect($event)->not->toBeNull()
            ->and($event->filtersPass(app()))->toBeFalse(); // dormant = the scheduler skips it

        Automation::toggle($key, $this->owner);

        expect($event->filtersPass(app()))->toBeTrue();
    }
});

it('reports real SEO findings and never touches anything', function () {
    Notification::fake();

    PageFactory::new()->published()->create([
        'title' => 'Complete home interior Lahore',
        'slug' => '/healthy-page/',
        'seo' => ['description' => 'Everything a finished home needs, in one team.'],
    ]);
    PageFactory::new()->published()->create([
        'title' => 'Bare page with no description',
        'slug' => '/bare-page/',
        'excerpt' => null,
    ]);
    PageFactory::new()->published()->create([
        'title' => str_repeat('Long ', 14).'title',
        'slug' => '/long-title/',
        'excerpt' => 'Has an excerpt but the title runs on and on.',
    ]);
    Redirect::query()->create(['from_path' => '/old-address/', 'to_path' => '/gone-away/', 'code' => 301]);

    expect($this->artisan('woodex:seo-agent'))->toBe(0);

    Notification::assertSentTo(
        $this->owner,
        fn (AgentReportNotification $notification): bool => str_contains(implode("\n", $notification->lines), 'Bare page with no description')
            && str_contains(implode("\n", $notification->lines), '/old-address/ → /gone-away/')
            && str_contains(implode("\n", $notification->lines), 'title(s) longer than 65')
            && $notification->kind === 'agent.seo',
    );
});

it('gives the SEO agent a clean bill on a healthy website', function () {
    Notification::fake();

    PageFactory::new()->published()->create([
        'slug' => '/healthy/',
        'seo' => ['description' => 'A perfectly described page.'],
    ]);

    expect($this->artisan(SeoAgent::class))->toBe(0);

    Notification::assertSentTo(
        $this->owner,
        fn (AgentReportNotification $notification): bool => str_contains(implode("\n", $notification->lines), 'Everything looks healthy'),
    );
});

it('gathers the week\'s post ideas without naming a client', function () {
    Notification::fake();

    $contact = Contact::factory()->create(['name' => 'Ayesha Khan']);
    Project::query()->create([
        'code' => 'PRJ-WEEK-1',
        'name' => 'DHA Phase 6 villa handover',
        'contact_id' => $contact->id,
        'business_line' => BusinessLine::Interior->value,
        'client_ok_at' => now()->subDays(2),
    ]);
    Project::query()->create([
        'code' => 'PRJ-OLD-1',
        'name' => 'Last month\'s handover',
        'contact_id' => $contact->id,
        'business_line' => BusinessLine::Interior->value,
        'client_ok_at' => now()->subDays(40),
    ]);
    Lead::factory()->create(['contact_id' => $contact->id, 'won_at' => now()->subDays(3)]);
    PageFactory::new()->published()->create(['slug' => '/fresh-story/', 'title' => 'A fresh story about wood']);

    expect($this->artisan('woodex:social-agent'))->toBe(0);

    Notification::assertSentTo(
        $this->owner,
        fn (AgentReportNotification $notification): bool => $notification->kind === 'agent.social'
            && str_contains(implode("\n", $notification->lines), 'DHA Phase 6 villa handover')
            && str_contains(implode("\n", $notification->lines), 'project(s) won')
            && str_contains(implode("\n", $notification->lines), 'A fresh story about wood')
            // the 40-day-old handover is out of the window, and the client is never named
            && ! str_contains(implode("\n", $notification->lines), 'Last month')
            && ! str_contains(implode("\n", $notification->lines), 'Ayesha'),
    );
});

it('keeps the social agent honest on a quiet week', function () {
    Notification::fake();

    expect($this->artisan(SocialAgent::class))->toBe(0);

    Notification::assertSentTo(
        $this->owner,
        fn (AgentReportNotification $notification): bool => str_contains(implode("\n", $notification->lines), 'quiet week'),
    );
});

it('routes the agent digests by Telegram, or email when Telegram is not linked', function () {
    Notification::fake();

    PageFactory::new()->published()->create(['slug' => '/any/', 'seo' => ['description' => 'x']]);
    $this->artisan('woodex:seo-agent');

    $notification = Notification::sent($this->owner, AgentReportNotification::class)->first()->notification
        ?? new AgentReportNotification('agent.seo', 'SEO health check', ['a line'], route('admin.automation.index'));

    expect($notification->via($this->owner))->toContain('mail') // no Telegram link in these tests
        ->and($notification->via($this->owner))->toContain('database');

    $this->owner->telegramLink()->create(['chat_id' => 901, 'chat_type' => 'private', 'is_active' => true]);
    $linked = $this->owner->fresh(); // the relation was already loaded (null) above

    expect($notification->via($linked))->toContain(TelegramChannel::class);
});
