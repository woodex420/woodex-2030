<?php

use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Actions\LogLeadActivity;
use App\Domain\Crm\Actions\ManageTask;
use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Jobs\AskForPhoneNumber;
use App\Domain\Crm\Jobs\SendLeadAlert;
use App\Domain\Crm\Mail\AskForNumberMail;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\Task;
use App\Domain\Crm\Notifications\NewLeadNotification;
use App\Domain\System\Models\Automation;
use App\Integrations\Telegram\TelegramChannel;
use App\Settings\NotificationSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
| P8.5 part 2: new-enquiry alerts and the ask-for-number flow. The alert reaches the owner AND the
| sales managers, with email for staff who have no Telegram; the ask-for-number reply is a single
| service response to the client's own enquiry, never marketing, and it can never send twice.
*/

function captureEnquiry(object $test, ?string $phone = '0322 4000768', ?string $email = null): Lead
{
    $result = app(CaptureLead::class)->handle(new LeadInput(
        name: 'Ayesha Khan',
        phone: $phone,
        email: $email,
        source: LeadSource::WebsiteForm,
        businessLine: BusinessLine::Interior,
        city: 'Lahore',
        message: 'Wardrobe for the master bedroom.',
    ));

    return $result->lead->refresh();
}

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->manager = $this->userWithRole('sales_manager');
});

it('alerts the lead owner and the sales managers about a new enquiry', function () {
    Notification::fake();

    $lead = captureEnquiry($this);
    $lead->forceFill(['owner_id' => $this->owner->id])->save();

    (new SendLeadAlert($lead->id, true))->handle(app(NotificationSettings::class));

    Notification::assertSentTo([$this->owner, $this->manager], NewLeadNotification::class);
});

it('alerts the unassigned-lead roles when nobody owns the enquiry', function () {
    Notification::fake();

    $lead = captureEnquiry($this);
    $lead->forceFill(['owner_id' => null])->save(); // capture auto-assigns; the fallback needs nobody

    (new SendLeadAlert($lead->id, true))->handle(app(NotificationSettings::class));

    // Default unassigned roles: sales_manager + owner.
    Notification::assertSentTo([$this->manager, $this->owner], NewLeadNotification::class);
});

it('emails staff with legacy Telegram links while only the owner can receive Telegram', function () {
    $notification = new NewLeadNotification(captureEnquiry($this), true);
    $this->manager->telegramLink()->create(['chat_id' => 500, 'chat_type' => 'private', 'is_active' => true]);
    $this->owner->telegramLink()->create(['chat_id' => 501, 'chat_type' => 'private', 'is_active' => true]);
    $unlinkedOwner = $this->userWithRole('owner');

    expect($notification->via($this->manager))->not->toContain(TelegramChannel::class)
        ->and($notification->via($this->manager))->toContain('mail')->toContain('database')
        ->and($notification->via($this->owner))->toContain(TelegramChannel::class)
        ->and($notification->via($this->owner))->not->toContain('mail')
        ->and($notification->via($unlinkedOwner))->toContain('mail')->toContain('database');
});

it('stays silent when the new-enquiry alert automation is paused', function () {
    Notification::fake();

    Automation::toggle('woodex:new-lead-alert', $this->owner);

    $lead = captureEnquiry($this);

    (new SendLeadAlert($lead->id, true))->handle(app(NotificationSettings::class));

    Notification::assertNothingSent();
});

it('asks once for a missing number by email and opens a follow-up task', function () {
    Mail::fake();

    $lead = captureEnquiry($this, phone: null, email: 'ayesha@example.com');

    (new AskForPhoneNumber($lead->id, true))->handle(app(LogLeadActivity::class), app(ManageTask::class));

    Mail::assertQueued(AskForNumberMail::class, fn (AskForNumberMail $mail) => $mail->leadId === $lead->id);

    $task = Task::query()->where('lead_id', $lead->id)->sole();

    expect($task->title)->toBe("Get the client's phone number — the enquiry came without one")
        ->and($task->assignee_id)->toBe($lead->owner_id)
        ->and($lead->activities()->where('type', ActivityType::System->value)->where('body', 'like', 'Asked % for a phone number%')->exists())->toBeTrue();

    // A retry (queue retry, replay, whatever) must never send the email a second time.
    (new AskForPhoneNumber($lead->id, true))->handle(app(LogLeadActivity::class), app(ManageTask::class));

    Mail::assertQueued(AskForNumberMail::class, 1);
});

it('never asks when the enquiry has a number or the automation is paused', function () {
    Mail::fake();

    $withPhone = captureEnquiry($this, phone: '0322 4000768', email: 'ayesha@example.com');
    (new AskForPhoneNumber($withPhone->id, true))->handle(app(LogLeadActivity::class), app(ManageTask::class));

    Automation::toggle('woodex:ask-for-number', $this->owner);

    $emailOnly = captureEnquiry($this, phone: null, email: 'no-reply@example.com');
    (new AskForPhoneNumber($emailOnly->id, true))->handle(app(LogLeadActivity::class), app(ManageTask::class));

    Mail::assertNothingQueued();
});

it('does not ask for a returning enquiry on an existing lead', function () {
    Mail::fake();

    $lead = captureEnquiry($this, phone: null, email: 'ayesha@example.com');

    (new AskForPhoneNumber($lead->id, false))->handle(app(LogLeadActivity::class), app(ManageTask::class));

    Mail::assertNothingQueued();
    expect(Task::query()->where('lead_id', $lead->id)->exists())->toBeFalse();
});
