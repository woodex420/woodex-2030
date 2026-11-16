<?php

use App\Console\Commands\SendMonthlyReport;
use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\System\Actions\GenerateMonthlyReport;
use App\Domain\System\Models\Automation;
use App\Domain\System\Models\MonthlyReport;
use App\Domain\System\Notifications\MonthlyReportNotification;
use App\Integrations\Telegram\TelegramChannel;
use App\Livewire\Admin\System\AutomationsIndex;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

/*
| P8.5 monthly CRM report (the owner's 17 Oct decision): on the 1st at 09:00 the month that just
| ended reaches the owner in one message — Telegram, email when Telegram is not linked — and the
| saved copy lives under System → Automations. The digest reuses the reports kit the screens use,
| so the message and the dashboards can never disagree.
*/

function reportOwnerWithChat(): User
{
    $owner = User::factory()->create();
    $owner->assignRole('owner');
    $owner->telegramLink()->create(['chat_id' => 900, 'chat_type' => 'private', 'is_active' => true]);

    return $owner;
}

beforeEach(function () {
    $this->owner = reportOwnerWithChat();
});

it('builds, archives and sends the previous month by default', function () {
    Notification::fake();

    $report = app(GenerateMonthlyReport::class)->handle();

    expect($report->period_start->isSameDay(CarbonImmutable::now()->startOfMonth()->subSecond()->startOfMonth()))->toBeTrue()
        ->and($report->period_end->toDateString())->toBe($report->period_start->endOfMonth()->toDateString())
        ->and($report->payload['period']['label'])->toBe($report->period_start->translatedFormat('F Y'))
        ->and($report->notified_at)->not->toBeNull();

    Notification::assertSentTo($this->owner, MonthlyReportNotification::class);
});

it('counts only the month it reports, website chats included', function () {
    $september = CarbonImmutable::parse('2026-09-01');
    $august = CarbonImmutable::parse('2026-08-01');

    $contact = Contact::factory()->create();
    Lead::factory()->create(['contact_id' => $contact->id, 'source' => LeadSource::WebsiteForm, 'business_line' => BusinessLine::Interior, 'created_at' => $september->addDays(3)]);
    Lead::factory()->create(['contact_id' => $contact->id, 'source' => LeadSource::WebsiteChat, 'business_line' => BusinessLine::Furniture, 'created_at' => $september->addDays(10)]);
    Lead::factory()->create(['contact_id' => $contact->id, 'source' => LeadSource::PhoneCall, 'business_line' => BusinessLine::Furniture, 'created_at' => $august->addDays(2)]); // not this month

    Conversation::query()->create([
        'channel' => Channel::WebChat->value,
        'widget_token' => 'sep-thread-0000000000000001',
        'widget_state' => 'live',
    ])->forceFill(['created_at' => $september->addDays(5)])->save();

    Notification::fake();
    $report = app(GenerateMonthlyReport::class)->handle($september);

    expect($report->period_start->toDateString())->toBe('2026-09-01')
        ->and($report->payload['funnel']['leads'])->toBe(2)
        ->and($report->payload['funnel_previous']['leads'])->toBe(1) // August's lead is the comparison
        ->and($report->payload['web_chat']['threads'])->toBe(1)
        ->and($report->payload['top_sources'][0]['label'])->toBeString();
});

it('keeps one row per month — regenerating updates instead of duplicating', function () {
    Notification::fake();

    $september = CarbonImmutable::parse('2026-09-01');
    app(GenerateMonthlyReport::class)->handle($september);
    app(GenerateMonthlyReport::class)->handle($september);

    expect(MonthlyReport::query()->whereDate('period_start', '2026-09-01')->count())->toBe(1);

    // A lead that appears later (a late import, a correction) shows up in the regenerated row.
    $lateContact = Contact::factory()->create();
    Lead::factory()->create(['contact_id' => $lateContact->id, 'created_at' => CarbonImmutable::parse('2026-09-20')]);
    $fresh = app(GenerateMonthlyReport::class)->handle($september);

    expect($fresh->payload['funnel']['leads'])->toBe(1);
});

it('archives without messaging when send is off', function () {
    Notification::fake();

    $report = app(GenerateMonthlyReport::class)->handle(CarbonImmutable::parse('2026-09-01'), send: false);

    expect($report->exists)->toBeTrue()
        ->and($report->notified_at)->toBeNull();
    Notification::assertNothingSent();
});

it('reaches the owner by Telegram, and by email when Telegram is not linked', function () {
    Notification::fake();

    $report = app(GenerateMonthlyReport::class)->handle(CarbonImmutable::parse('2026-09-01'), send: false);
    $notification = new MonthlyReportNotification($report);

    expect($notification->via($this->owner))->toContain(TelegramChannel::class)
        ->and($notification->via($this->owner))->toContain('database');

    $unlinked = User::factory()->create();
    $unlinked->assignRole('owner');
    expect($notification->via($unlinked))->toContain('mail');

    // The Telegram copy carries the headline, the archive button only on https.
    $telegram = $notification->toTelegram($this->owner);
    expect($telegram->text)->toContain('Monthly report')
        ->and($telegram->buttons)->toBeArray();
});

it('does not fail when there is no owner to receive it', function () {
    Notification::fake();
    User::query()->delete();

    $report = app(GenerateMonthlyReport::class)->handle(CarbonImmutable::parse('2026-09-01'));

    expect($report->exists)->toBeTrue()
        ->and($report->notified_at)->toBeNull();
    Notification::assertNothingSent();
});

it('refuses a period that does not start on the first of a month', function () {
    expect(fn () => app(GenerateMonthlyReport::class)->handle(CarbonImmutable::parse('2026-09-15')))
        ->toThrow(InvalidArgumentException::class);
});

it('runs as the scheduled command on the 1st at 09:00, behind the automation switch', function () {
    Notification::fake();

    expect($this->artisan('woodex:monthly-report', ['--month' => '2026-09-01']))->toBe(0);
    expect(MonthlyReport::query()->whereDate('period_start', '2026-09-01')->exists())->toBeTrue();
    Notification::assertSentTo($this->owner, MonthlyReportNotification::class);

    expect($this->artisan(SendMonthlyReport::class, ['--no-send' => true, '--month' => '2026-09-01']))->toBe(0);

    // The schedule entry exists, points at the command, and honours the owner's switch.
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'woodex:monthly-report'));

    expect($event)->not->toBeNull()
        ->and($event->filtersPass(app()))->toBeTrue();

    Automation::toggle('woodex:monthly-report', $this->owner);

    expect($event->filtersPass(app()))->toBeFalse();
});

it('archives and shows the report on the automations screen, and builds it on demand', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    Notification::fake();

    $september = CarbonImmutable::parse('2026-09-01');
    $contact = Contact::factory()->create();
    Lead::factory()->create(['contact_id' => $contact->id, 'source' => LeadSource::WebsiteForm, 'business_line' => BusinessLine::Interior, 'created_at' => $september->addDays(3)]);
    $report = app(GenerateMonthlyReport::class)->handle($september, send: false);

    Livewire::actingAs($this->owner)
        ->test(AutomationsIndex::class)
        ->assertOk()
        ->assertSee('Monthly CRM report')
        ->assertSee($september->translatedFormat('F Y'))
        ->assertSee('archived, not sent');

    // "Build last month now" runs the same computation and sends it — an explicit human action.
    // Today is October, so last month IS September: the row is updated in place and marked sent.
    Notification::fake();
    Livewire::actingAs($this->owner)
        ->test(AutomationsIndex::class)
        ->call('generateNow')
        ->assertHasNoErrors();

    Notification::assertSentTo($this->owner, MonthlyReportNotification::class);
    expect(MonthlyReport::query()->count())->toBe(1)
        ->and(MonthlyReport::query()->firstWhere('id', $report->id)->notified_at)->not->toBeNull();
});
