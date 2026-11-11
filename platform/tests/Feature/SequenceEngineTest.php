<?php

use App\Domain\Automation\Actions\EnrollLead;
use App\Domain\Automation\Enums\EnrolmentStatus;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Enums\SequenceTrigger;
use App\Domain\Automation\Models\AutomationEnrolment;
use App\Domain\Automation\Models\AutomationSequence;
use App\Domain\Conversations\Actions\ReceiveWhatsAppMessage;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Crm\Enums\TaskType;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Crm\Models\Task;
use App\Domain\System\Enums\WhatsAppTemplateStatus;
use App\Domain\System\Models\WhatsAppTemplate;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Livewire\Admin\Automation\SequencesIndex;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\Artisan;

/*
| AUT-001/002/005/006: the follow-up sequences engine. One heartbeat command walks every due
| step; templates come from the moment mapping (never a hard-coded Meta name); sends pass the
| safe-limits guard — quiet hours and the daily per-client cap hold a message for its next legal
| moment; a client's reply or a won/lost lead ends the enrolment; everything is logged per run.
*/

beforeEach(function (): void {
    // Pin the clock to mid-morning: the engine's safe-limits guard holds automated sends during quiet
    // hours (21:00-09:00 by default), so a run of this file after 21:00 would hold every "expected"
    // send for the morning and fail. From 11:00 the relative travels (+61/+121 minutes) stay outside
    // the window at any wall-clock time; the quiet-hours test below pins its own times explicitly.
    $this->travelTo(now()->setTime(11, 0));

    config()->set('services.whatsapp', [
        'token' => 'test-token', 'phone_number_id' => '1234567890', 'waba_id' => '987654321',
        'app_secret' => 'app-secret', 'verify_token' => 'verify-me',
        'api_version' => 'v23.0', 'api_base' => 'https://graph.facebook.com',
    ]);

    $this->gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $this->gateway);

    $this->owner = $this->userWithRole('owner');

    // An approved template mapped to the follow-up moment — what the steps will send.
    WhatsAppTemplate::create([
        'name' => 'quote_followup_v2', 'language' => 'en', 'category' => 'UTILITY',
        'status' => WhatsAppTemplateStatus::Approved, 'event_key' => 'follow_up',
        'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, following up on your quotation.']],
        'synced_at' => now(),
    ]);

    // A lead with a WhatsApp conversation, the way a real one exists.
    $contact = Contact::create([
        'name' => 'Bilal Mirza', 'phone' => '923224007688', 'whatsapp' => '923224007688',
        'source' => 'whatsapp', 'consent_whatsapp' => true,
    ]);

    $this->lead = Lead::factory()->create([
        'contact_id' => $contact->id,
        'stage_id' => PipelineStage::byKey('new')->id,
        'business_line' => 'interior', 'service' => 'wardrobe', 'city' => 'Lahore',
    ]);

    app(ReceiveWhatsAppMessage::class)->handle([
        'messaging_product' => 'whatsapp',
        'contacts' => [['wa_id' => '923224007688', 'profile' => ['name' => 'Bilal Mirza']]],
        'messages' => [['from' => '923224007688', 'id' => 'wamid.SEQ1', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Assalam o Alaikum']]],
    ]);

    $this->sequence = AutomationSequence::create([
        'name' => 'Test follow-up', 'slug' => 'test-follow-up', 'trigger' => 'quotation_sent',
        'exits_on_reply' => true, 'is_active' => true,
    ]);
    $this->sequence->steps()->createMany([
        ['position' => 1, 'kind' => 'wait', 'wait_minutes' => 60],
        ['position' => 2, 'kind' => 'send_template', 'template_event' => 'follow_up'],
        ['position' => 3, 'kind' => 'wait', 'wait_minutes' => 120],
        ['position' => 4, 'kind' => 'create_task', 'task_title' => 'Call {first_name} about the quotation', 'task_due_minutes' => 240],
    ]);
});

it('walks a sequence: wait, send the mapped template, then the task', function () {
    app(EnrollLead::class)->forTrigger(SequenceTrigger::QuotationSent, $this->lead);

    // Nothing runs before its time.
    Artisan::call('woodex:automation-run');
    expect($this->gateway->sent)->toHaveCount(0);

    $this->travel(61)->minutes();
    Artisan::call('woodex:automation-run');

    expect($this->gateway->sent)->toHaveCount(1)
        ->and($this->gateway->sent[0]['template'])->toBe('quote_followup_v2');

    $this->travel(121)->minutes();
    Artisan::call('woodex:automation-run');

    $task = Task::query()->where('lead_id', $this->lead->id)->first();
    expect($task)->not->toBeNull()
        ->and($task->title)->toBe('Call Bilal about the quotation')
        ->and($task->type)->toBe(TaskType::Todo);

    $enrolment = AutomationEnrolment::query()->where('lead_id', $this->lead->id)->where('sequence_id', $this->sequence->id)->first();
    expect($enrolment->status)->toBe(EnrolmentStatus::Completed)
        ->and($enrolment->runs->count())->toBe(4)
        ->and($enrolment->runs->pluck('status')->unique()->values()->all())->toBe([RunStatus::Done]);
});

it('a client reply ends the sequence before anything more is sent', function () {
    app(EnrollLead::class)->forTrigger(SequenceTrigger::QuotationSent, $this->lead);

    $this->travel(30)->minutes();

    app(ReceiveWhatsAppMessage::class)->handle([
        'messaging_product' => 'whatsapp',
        'contacts' => [['wa_id' => '923224007688', 'profile' => ['name' => 'Bilal Mirza']]],
        'messages' => [['from' => '923224007688', 'id' => 'wamid.SEQ2', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'I saw the quote, calling you']]],
    ]);

    $this->travel(31)->minutes();
    Artisan::call('woodex:automation-run');

    expect($this->gateway->sent)->toHaveCount(0);

    $enrolment = AutomationEnrolment::query()->where('lead_id', $this->lead->id)->where('sequence_id', $this->sequence->id)->first();
    expect($enrolment->status)->toBe(EnrolmentStatus::Exited)
        ->and($enrolment->end_reason)->toBe('the client replied');
});

it('holds automated sends through quiet hours, unless the client wrote first', function () {
    $settings = app(SalesSettings::class);
    $settings->automation_quiet_start = '21:00';
    $settings->automation_quiet_end = '09:00';
    $settings->save();

    app(EnrollLead::class)->forTrigger(SequenceTrigger::QuotationSent, $this->lead);

    // 22:00, nobody spoke: the send waits for the morning.
    $this->travelTo(now()->setTime(22, 0)->addMinutes(61));
    Artisan::call('woodex:automation-run');

    expect($this->gateway->sent)->toHaveCount(0);

    $run = AutomationEnrolment::query()->where('lead_id', $this->lead->id)->where('sequence_id', $this->sequence->id)->first()->runs->last();
    expect($run->status)->toBe(RunStatus::Pending)
        ->and($run->scheduled_at->format('H:i'))->toBe('09:05')
        ->and($run->attempt)->toBe(1);

    // 09:05 the next morning: it goes.
    $this->travelTo($run->scheduled_at->addMinute());
    Artisan::call('woodex:automation-run');

    expect($this->gateway->sent)->toHaveCount(1);
});

it('enforces the daily per-client cap across sequences', function () {
    $settings = app(SalesSettings::class);
    $settings->automation_daily_contact_limit = 1;
    $settings->save();

    // One automated template already went out today (the quotation send itself, say).
    $conversation = Conversation::query()->sole();
    $conversation->messages()->create([
        'direction' => MessageDirection::Out,
        'type' => 'template', 'body' => 'quote_ready_v2', 'status' => MessageStatus::Sent,
        'sent_at' => now(),
    ]);

    app(EnrollLead::class)->forTrigger(SequenceTrigger::QuotationSent, $this->lead);
    $this->travel(61)->minutes();
    Artisan::call('woodex:automation-run');

    expect($this->gateway->sent)->toHaveCount(0);

    $run = AutomationEnrolment::query()->where('lead_id', $this->lead->id)->where('sequence_id', $this->sequence->id)->first()->runs->last();
    expect($run->status)->toBe(RunStatus::Pending)
        ->and($run->note)->toBe('held: daily message cap reached');
});

it('skips the send when no approved template is mapped, and says so', function () {
    WhatsAppTemplate::query()->first()->forceFill(['event_key' => 'quotation'])->save();

    app(EnrollLead::class)->forTrigger(SequenceTrigger::QuotationSent, $this->lead);
    $this->travel(61)->minutes();
    Artisan::call('woodex:automation-run');

    expect($this->gateway->sent)->toHaveCount(0);

    $run = AutomationEnrolment::query()->with('runs.step')->where('lead_id', $this->lead->id)->where('sequence_id', $this->sequence->id)->first()
        ->runs->first(fn ($run) => $run->step->kind->value === 'send_template');
    expect($run->status)->toBe(RunStatus::Skipped)
        ->and($run->note)->toContain('no approved template mapped');
});

it('exits a lead that is won or lost (nurture sequences excepted)', function () {
    $nurture = AutomationSequence::create([
        'name' => 'Nurture', 'slug' => 'nurture-test', 'trigger' => 'lead_lost', 'exits_on_reply' => true,
    ]);
    $nurture->steps()->createMany([
        ['position' => 1, 'kind' => 'wait', 'wait_minutes' => 60],
        ['position' => 2, 'kind' => 'send_template', 'template_event' => 'follow_up'],
        ['position' => 3, 'kind' => 'wait', 'wait_minutes' => 10080],
    ]);

    app(EnrollLead::class)->forTrigger(SequenceTrigger::QuotationSent, $this->lead);
    app(EnrollLead::class)->forTrigger(SequenceTrigger::LeadLost, $this->lead);

    $lost = PipelineStage::byKey('lost');
    $this->lead->forceFill(['stage_id' => $lost->id])->save();

    $this->travel(61)->minutes();
    Artisan::call('woodex:automation-run');

    $statuses = AutomationEnrolment::query()->where('lead_id', $this->lead->id)->get()->map(fn ($e) => [$e->sequence_id, $e->status->value, $e->end_reason]);

    expect($statuses->firstWhere(0, $this->sequence->id)[1])->toBe('exited')
        ->and($statuses->firstWhere(0, $this->sequence->id)[2])->toBe('the lead was lost')
        ->and($statuses->firstWhere(0, $nurture->id)[1])->toBe('active');
});

it('pause holds the place, resume catches up, cancel stops for good', function () {
    $enrolment = app(EnrollLead::class)->inSequence($this->sequence, $this->lead);

    $enrolment->pause();
    $this->travel(61)->minutes();
    Artisan::call('woodex:automation-run');
    expect($this->gateway->sent)->toHaveCount(0); // paused: the due step does not run

    $enrolment->refresh();
    $enrolment->resume();
    expect($enrolment->status)->toBe(EnrolmentStatus::Active);

    Artisan::call('woodex:automation-run');
    expect($this->gateway->sent)->toHaveCount(1); // resumed: the held step runs at once

    $enrolment->end(EnrolmentStatus::Cancelled, 'test');
    $this->travel(121)->minutes();
    Artisan::call('woodex:automation-run');
    expect(Task::query()->where('lead_id', $this->lead->id)->count())->toBe(0)
        ->and($enrolment->refresh()->status)->toBe(EnrolmentStatus::Cancelled);
});

it('renders the sequences screen with the built-ins and their steps', function () {
    Livewire::actingAs($this->owner)
        ->test(SequencesIndex::class)
        ->assertOk()
        ->assertSee('Pick a sequence')
        ->assertSee('Lead not reached')
        ->call('select', $this->sequence->id)
        ->assertOk()
        // describe() renders {first_name} per lead; with none enrolled it says "the client".
        ->assertSee('Call the client about the quotation')
        ->assertSee('Send the mapped follow_up template')
        ->assertSee('The steps');
});
