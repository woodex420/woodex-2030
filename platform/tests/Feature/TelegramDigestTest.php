<?php

use App\Domain\Crm\Actions\ScheduleSiteVisit;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\Task;
use App\Domain\Crm\Notifications\NewLeadNotification;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\System\Models\Automation;
use App\Domain\System\Models\PendingTelegramMessage;
use App\Domain\System\Models\TelegramLink;
use App\Integrations\Telegram\Fakes\FakeTelegramGateway;
use App\Integrations\Telegram\TelegramChannel;
use App\Integrations\Telegram\TelegramMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/*
| P5 S4 + S8: the digests, and quiet hours that queue instead of dropping.
|
| Everything runs against the fake gateway — the owner's phone is not the test bench.
*/

beforeEach(function () {
    // Pin the clock to mid-morning so the daily digest's "today" windows (a visit in +3h, a task in
    // +2h, a follow-up in +1h) stay inside today whenever the suite runs. Without this the file only
    // passes before 21:00: the same +3h would land tomorrow after midnight and the digest would
    // rightly show nothing for today.
    $this->travelTo(now()->setTime(10, 0));

    Storage::fake('local');
    $this->telegram = FakeTelegramGateway::bind();

    $this->owner = $this->userWithRole('owner');
    TelegramLink::query()->create([
        'user_id' => $this->owner->id,
        'chat_id' => 500,
        'is_active' => true,
        'linked_at' => now(),
    ]);

    $this->contact = Contact::factory()->create();
});

function digestRun(string $period = 'daily'): void
{
    expect(test()->artisan('woodex:digest', ['--period' => $period]))->toBe(0);
}

it('sends the daily digest to every linked chat', function () {
    // The visit lead is created today; only the explicit yesterdayLead below belongs in yesterday's count.
    // The factory otherwise chooses a random time in the last 20 days, occasionally adding a second lead.
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id, 'created_at' => now()]);
    app(ScheduleSiteVisit::class)->handle($lead, now()->addHours(3), 60, 'DHA Phase 5', $this->owner, $this->owner);
    Task::query()->create(['title' => 'Send the walnut sample', 'type' => 'todo', 'priority' => 'normal', 'status' => 'open', 'due_at' => now()->addHours(2), 'assignee_id' => $this->owner->id, 'created_by' => $this->owner->id]);
    // created_at is forced after the insert: this row is exactly what the digest's "yesterday" window
    // counts, so it must not depend on how the factory happens to apply timestamps.
    $yesterdayLead = Lead::factory()->create(['owner_id' => $this->owner->id, 'next_action_at' => now()->addHours(1), 'next_action_type' => 'call']);
    $yesterdayLead->forceFill(['created_at' => now()->subDay()->setTime(10, 0)])->saveQuietly();

    $invoice = Invoice::query()->create([
        'number' => 'INV-T-0001', 'kind' => 'manual', 'status' => InvoiceStatus::Issued, 'contact_id' => $this->contact->id,
        'currency' => 'PKR', 'business_line' => 'interior', 'subtotal_minor' => 100_000 * 100, 'tax_minor' => 0,
        'total_minor' => 100_000 * 100, 'paid_minor' => 100_000 * 100, 'balance_minor' => 0,
        'issue_date' => today()->toDateString(), 'created_by' => $this->owner->id, 'title' => 'Digest test',
    ]);
    Payment::query()->create([
        'contact_id' => $this->contact->id, 'invoice_id' => $invoice->id, 'amount_minor' => 100_000 * 100,
        'received_on' => now()->subDay()->toDateString(), 'method' => 'cash', 'status' => PaymentStatus::Verified,
        'verified_at' => now()->subDay()->setTime(11, 0), 'verified_by' => $this->owner->id, 'created_by' => $this->owner->id,
    ]);

    digestRun();

    $text = $this->telegram->textsFor(500)[0] ?? '';

    expect($text)->toContain('your day')
        ->and($text)->toContain('Visits today:')
        ->and($text)->toContain('DHA Phase 5')
        ->and($text)->toContain('Send the walnut sample')
        ->and($text)->toContain('Follow-ups due:')
        ->and($text)->toContain('Yesterday: 1 new lead.')
        ->and($text)->toContain('Money in yesterday:')
        ->and($text)->toContain('100,000')
        ->and($text)->toContain('Approvals waiting: 0');

    // The in-app bell gets the same summary.
    expect($this->owner->fresh()->notifications)->toHaveCount(1);
});

it('keeps staff digests in-app while only the owner receives Telegram', function () {
    $sales = $this->userWithRole('sales');
    TelegramLink::query()->create(['user_id' => $sales->id, 'chat_id' => 600, 'is_active' => true, 'linked_at' => now()]);

    digestRun();

    $ownerText = $this->telegram->textsFor(500)[0] ?? '';
    $salesText = $this->telegram->textsFor(600)[0] ?? '';

    expect($ownerText)->toContain('Money in yesterday:')
        ->and($ownerText)->toContain('Approvals waiting:');

    expect($salesText)->toBe('')
        ->and($sales->fresh()->notifications)->toHaveCount(1);
});

it('summarises the week that just ended on Mondays', function () {
    $start = now()->startOfWeek()->subWeek(); // the week the Monday digest reports on

    $lead = Lead::factory()->create(['owner_id' => $this->owner->id, 'contact_id' => $this->contact->id, 'source' => 'website_form', 'created_at' => $start->copy()->addDays(1), 'won_at' => $start->copy()->addDays(3)]);
    Lead::factory()->create(['owner_id' => $this->owner->id, 'source' => 'website_form', 'created_at' => $start->copy()->addDays(2), 'lost_at' => $start->copy()->addDays(4), 'lost_reason' => 'price']);

    $quote = app(CreateQuote::class)->handle($this->contact, BusinessLine::Interior, $this->owner, $lead, null, 'Lounge');
    $quote->forceFill(['sent_at' => $start->copy()->addDays(2), 'accepted_at' => $start->copy()->addDays(3)])->save();

    Invoice::query()->create([
        'number' => 'INV-T-0002', 'kind' => 'manual', 'status' => InvoiceStatus::Issued, 'contact_id' => $this->contact->id,
        'currency' => 'PKR', 'business_line' => 'interior', 'subtotal_minor' => 400_000 * 100, 'tax_minor' => 0,
        'total_minor' => 400_000 * 100, 'paid_minor' => 0, 'balance_minor' => 400_000 * 100,
        'issue_date' => today()->subDays(10)->toDateString(), 'due_date' => today()->subDays(2)->toDateString(),
        'created_by' => $this->owner->id, 'title' => 'Weekly digest test',
    ]);

    digestRun('weekly');

    $text = $this->telegram->textsFor(500)[0] ?? '';

    expect($text)->toContain('Leads: 2.')
        ->and($text)->toContain('website_form 2')
        ->and($text)->toContain('Won: 1 · lost: 1.')
        ->and($text)->toContain('Quotations: 1 sent, 1 accepted.')
        ->and($text)->toContain('Still to collect:')
        ->and($text)->toContain('400,000')
        ->and($text)->toContain('1 invoice (1 overdue).');
});

it('queues the digest for a muted chat instead of dropping it', function () {
    $this->owner->telegramLink->update(['muted_until' => now()->addHours(2)]);

    digestRun();

    expect($this->telegram->sent)->toBeEmpty();

    $pending = PendingTelegramMessage::query()->whereNull('delivered_at')->get();

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->text)->toContain('your day');

    // The bell copy still lands straight away.
    expect($this->owner->fresh()->notifications)->toHaveCount(1);
});

it('delivers the backlog when the mute ends', function () {
    $this->owner->telegramLink->update(['muted_until' => now()->addHour()]);

    digestRun();
    $this->owner->notify(new NewLeadNotification(Lead::factory()->create(['owner_id' => $this->owner->id])));

    expect($this->telegram->sent)->toBeEmpty();

    // Still muted: the flush must hold everything.
    expect($this->artisan('woodex:telegram-flush'))->toBe(0);
    expect($this->telegram->sent)->toBeEmpty();

    $this->owner->telegramLink->update(['muted_until' => now()->subMinute()]);

    expect($this->artisan('woodex:telegram-flush'))->toBe(0);

    $texts = $this->telegram->textsFor(500);

    expect($texts)->toHaveCount(2)
        ->and($texts[0])->toContain('your day')
        ->and($texts[1])->toContain('New lead');

    expect(PendingTelegramMessage::query()->whereNull('delivered_at')->count())->toBe(0)
        ->and(PendingTelegramMessage::query()->whereNotNull('delivered_at')->count())->toBe(2);
});

it('keeps the inline buttons of a queued alert', function () {
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id]);
    $this->owner->telegramLink->update(['muted_until' => now()->addMinutes(30)]);
    $this->owner->notify(new NewLeadNotification($lead));

    $queued = PendingTelegramMessage::query()->whereNull('delivered_at')->first();

    expect($queued->buttons)->not->toBeEmpty();

    $this->owner->telegramLink->update(['muted_until' => now()->subMinute()]);
    expect($this->artisan('woodex:telegram-flush'))->toBe(0);

    $sent = collect($this->telegram->sent)->first(fn (array $item): bool => (int) $item['chat_id'] === 500);

    expect($sent['message']->buttons)->not->toBeEmpty();
});

it('lets a critical notification bypass the mute', function () {
    $this->owner->telegramLink->update(['muted_until' => now()->addHours(4)]);

    $critical = new class extends Notification
    {
        public function isCritical(): bool
        {
            return true;
        }

        /** @return list<string> */
        public function via(object $notifiable): array
        {
            return [TelegramChannel::class];
        }

        public function toTelegram(object $notifiable): TelegramMessage
        {
            return new TelegramMessage('🚨 The nightly backup failed.');
        }
    };

    $this->owner->notify($critical);

    expect($this->telegram->textsFor(500)[0] ?? '')->toContain('backup failed')
        ->and(PendingTelegramMessage::query()->count())->toBe(0);
});

it('caps the quiet-hours queue at twenty messages per chat', function () {
    $this->owner->telegramLink->update(['muted_until' => now()->addHours(4)]);

    $plain = new class extends Notification
    {
        /** @return list<string> */
        public function via(object $notifiable): array
        {
            return [TelegramChannel::class];
        }

        public function toTelegram(object $notifiable): TelegramMessage
        {
            return new TelegramMessage('queue me');
        }
    };

    for ($i = 0; $i < 22; $i++) {
        $this->owner->notify($plain);
    }

    expect(PendingTelegramMessage::query()->whereNull('delivered_at')->count())->toBe(20);
});

it('lists the digests in the automation catalog, on by default', function () {
    $keys = collect(Automation::catalog())->pluck('key');

    expect($keys)->toContain('woodex:daily-digest')
        ->and($keys)->toContain('woodex:weekly-digest')
        ->and($keys)->toContain('woodex:telegram-flush')
        ->and(Automation::defaultEnabled('woodex:daily-digest'))->toBeTrue()
        ->and(Automation::defaultEnabled('woodex:weekly-digest'))->toBeTrue()
        ->and(Automation::defaultEnabled('woodex:telegram-flush'))->toBeTrue();
});
