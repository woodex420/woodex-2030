<?php

use App\Console\Commands\Heartbeat;
use App\Domain\System\Health\SystemHealthReport;
use App\Domain\System\Models\ScheduledTaskRun;
use App\Domain\System\Models\TelegramLink;
use App\Integrations\Telegram\Fakes\FakeTelegramGateway;
use App\Livewire\Admin\System\Health;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| SYS-008 (P5 S5): the one collector behind both System → Health and /health.
| Database size, last backup, scheduled tasks with their last run, a test-mail button, versions.
| The rule under test: the screen and the phone must never tell two different stories.
*/

it('shows the new health items on the screen: db size, last backup, scheduled tasks', function () {
    $admin = $this->userWithRole('owner');
    Livewire::actingAs($admin);

    // A backup written two hours ago and a task that ran five minutes ago.
    Storage::fake('local');
    Storage::disk('local')->put('woodex-backup.zip', 'zip');
    touch(Storage::disk('local')->path('woodex-backup.zip'), now()->subHours(2)->getTimestamp());
    ScheduledTaskRun::query()->create(['task' => 'woodex:heartbeat', 'ran_at' => now()->subMinutes(5), 'runtime_ms' => 12]);

    $component = Livewire::test(Health::class);

    $component->assertSee('Database size')
        ->assertSee('Last backup')
        ->assertSee('2 hours ago')
        ->assertSee('Scheduled tasks')
        ->assertSee('woodex:heartbeat')
        ->assertSee('Send test email')
        ->assertSee('Laravel');

    // The collector's database size answers on SQLite too (the pragma path).
    expect(app(SystemHealthReport::class)->databaseSize())->not->toBeNull();
});

it('answers a test-mail press by sending to the admin own address', function () {
    $admin = $this->userWithRole('owner', ['email' => 'owner@woodex.test']);
    config(['mail.default' => 'array']);

    Livewire::actingAs($admin)
        ->test(Health::class)
        ->call('sendTestMail')
        ->assertDispatched('toast');

    // The array transport keeps every message it was asked to send: the proof, without faking anything.
    $transport = app(MailManager::class)->mailer('array')->getSymfonyTransport();
    $messages = $transport->messages();

    expect($messages)->not->toBeEmpty();

    $email = $messages[0]->getOriginalMessage();

    expect($email->getTo()[0]->getAddress())->toBe($admin->email)
        ->and($email->getSubject())->toBe('Woodex OS test email')
        ->and(str_contains($email->toString(), 'outgoing mail works'))->toBeTrue();
});

it('warns when no backup exists and no task has ever run', function () {
    $admin = $this->userWithRole('owner');
    Storage::fake('local');

    Livewire::actingAs($admin)
        ->test(Health::class)
        ->assertSee('never')
        ->assertSee('no runs recorded yet');
});

it('answers /health with the same truth: db size, backup, and the stale task by name', function () {
    config(['services.telegram.webhook_secret' => 'hook-secret']);
    Storage::fake('local');
    Storage::disk('local')->put('woodex-backup.zip', 'zip');
    touch(Storage::disk('local')->path('woodex-backup.zip'), now()->subHours(3)->getTimestamp());
    Cache::put(Heartbeat::CACHE_KEY, now()->toIso8601String());
    ScheduledTaskRun::query()->create(['task' => 'woodex:heartbeat', 'ran_at' => now()->subMinutes(1)]);
    ScheduledTaskRun::query()->create(['task' => 'woodex:sla-check', 'ran_at' => now()->subDays(2)]);

    $telegram = FakeTelegramGateway::bind();
    $owner = $this->userWithRole('owner');
    TelegramLink::query()->create(['user_id' => $owner->id, 'chat_id' => 500, 'is_active' => true, 'linked_at' => now()]);

    $this->postJson('/webhooks/telegram/hook-secret', [
        'update_id' => 900,
        'message' => [
            'message_id' => 901,
            'chat' => ['id' => 500, 'type' => 'private'],
            'from' => ['id' => 500, 'first_name' => 'Imtiaz'],
            'text' => '/health',
        ],
    ], headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();

    $replies = $telegram->textsFor(500);
    $reply = (string) end($replies);

    expect($reply)->toContain('Backup: 3 hours ago')
        ->and($reply)->toContain('DB: ')
        ->and($reply)->toContain('Tasks stale: woodex:sla-check')
        ->and($reply)->toContain('PHP '.PHP_VERSION);
});

it('records a finished scheduler task and prunes the old runs', function () {
    $schedule = app(Schedule::class);
    $event = $schedule->command('woodex:heartbeat')->everyMinute();

    event(new ScheduledTaskFinished($event, 0.012));

    $run = ScheduledTaskRun::query()->where('task', 'woodex:heartbeat')->first();

    expect($run)->not->toBeNull()
        ->and($run->runtime_ms)->toBe(12)
        ->and($run->ran_at)->not->toBeNull();

    // Old rows are pruned; fresh ones stay.
    ScheduledTaskRun::query()->create(['task' => 'old:task', 'ran_at' => now()->subDays(20)]);
    ScheduledTaskRun::pruneOld();

    expect(ScheduledTaskRun::query()->where('task', 'old:task')->exists())->toBeFalse()
        ->and(ScheduledTaskRun::query()->where('task', 'woodex:heartbeat')->exists())->toBeTrue();
});
