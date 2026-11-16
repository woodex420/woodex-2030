<?php

use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\System\Models\TelegramLink;
use App\Integrations\Telegram\Fakes\FakeTelegramGateway;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->telegram = FakeTelegramGateway::bind();
    $this->newStage = PipelineStage::query()->where('key', 'new')->value('id');
});

it('flags an uncontacted lead once with owner-only Telegram and preserved staff in-app alerts', function () {
    $owner = $this->userWithRole('owner');
    TelegramLink::query()->create(['user_id' => $owner->id, 'chat_id' => 503, 'is_active' => true]);
    $manager = $this->userWithRole('sales_manager');
    $sales = $this->userWithRole('sales');
    TelegramLink::query()->create(['user_id' => $sales->id, 'chat_id' => 501, 'is_active' => true, 'linked_at' => now()]);
    TelegramLink::query()->create(['user_id' => $manager->id, 'chat_id' => 502, 'is_active' => true, 'linked_at' => now()]);

    $late = Lead::factory()->create(['owner_id' => $sales->id, 'stage_id' => $this->newStage, 'first_response_at' => null, 'sla_due_at' => now()->subMinutes(5), 'sla_breached_at' => null]);
    $contacted = Lead::factory()->create(['owner_id' => $sales->id, 'stage_id' => $this->newStage, 'first_response_at' => now()->subMinutes(20), 'sla_due_at' => now()->subMinutes(5)]);
    $notDue = Lead::factory()->create(['owner_id' => $sales->id, 'stage_id' => $this->newStage, 'first_response_at' => null, 'sla_due_at' => now()->addMinutes(10)]);

    expect(Artisan::call('woodex:sla-check'))->toBe(0);

    expect($late->fresh()->sla_breached_at)->not->toBeNull()
        ->and($contacted->fresh()->sla_breached_at)->toBeNull()
        ->and($notDue->fresh()->sla_breached_at)->toBeNull()
        ->and($late->activities()->where('type', 'system')->where('body', 'like', 'First response time missed%')->exists())->toBeTrue()
        ->and($this->telegram->textsFor(501))->toBe([])
        ->and($this->telegram->textsFor(502))->toBe([])
        ->and($this->telegram->textsFor(503))->toHaveCount(1)
        ->and($this->telegram->textsFor(503)[0])->toContain('Response time missed')->toContain($late->ref)
        ->and($manager->notifications()->count())->toBe(1)
        ->and($owner->notifications()->count())->toBe(1)
        ->and($sales->notifications()->first()->data['kind'])->toBe('lead.sla_breached');

    // Running again does not alert again.
    Artisan::call('woodex:sla-check');

    expect($this->telegram->sent)->toHaveCount(1)
        ->and($sales->notifications()->count())->toBe(1);
});

it('ignores closed leads', function () {
    $lead = Lead::factory()->create([
        'stage_id' => PipelineStage::query()->where('key', 'lost')->value('id'),
        'first_response_at' => null,
        'sla_due_at' => now()->subHour(),
    ]);

    Artisan::call('woodex:sla-check');

    expect($lead->fresh()->sla_breached_at)->toBeNull();
});

it('is scheduled every minute', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'woodex:sla-check'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});
