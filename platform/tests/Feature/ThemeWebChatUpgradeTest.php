<?php

use App\Domain\Conversations\Actions\ReceiveWebChatMessage;
use App\Domain\Conversations\Actions\SendConversationMessage;
use App\Domain\Conversations\Actions\StartWebChat;
use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Events\LeadCaptured;
use App\Domain\Crm\Jobs\AskForPhoneNumber;
use App\Domain\Crm\Models\Lead;
use App\Livewire\Admin\Conversations\LiveChatBell;
use App\Livewire\Admin\Crm\WebChatLeads;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    Mail::fake();
    Event::fake([LeadCaptured::class]);
});

function intake48(string $contact = '03211234567'): Conversation
{
    $thread = app(StartWebChat::class)->handle(null);
    app(ReceiveWebChatMessage::class)->handle($thread, 'Website QA Visitor');
    app(ReceiveWebChatMessage::class)->handle($thread, $contact);

    return $thread->refresh();
}

it('captures a phone before service and never assumes messaging consent', function () {
    $thread = intake48();
    expect($thread->lead_id)->not->toBeNull()
        ->and($thread->contact->phone)->toBe('+923211234567')
        ->and($thread->contact->consent_whatsapp)->toBeFalse()
        ->and($thread->contact->consent_marketing)->toBeFalse()
        ->and($thread->lead->service)->toBeNull()
        ->and($thread->widget_state)->toBe('asked_service');
    Event::assertDispatchedTimes(LeadCaptured::class, 1);
});

it('qualifies the existing lead without recapture or duplicate enrollment events', function () {
    $thread = intake48();
    $leadId = $thread->lead_id;
    app(ReceiveWebChatMessage::class)->handle($thread, 'Kitchen renovation');
    expect($thread->lead_id)->toBe($leadId)->and(Lead::count())->toBe(1)
        ->and($thread->lead->service)->toBe('Kitchen renovation')->and($thread->widget_state)->toBe('live');
    Event::assertDispatchedTimes(LeadCaptured::class, 1);
});

it('keeps a repeated number from becoming service or a second capture', function () {
    $thread = intake48();
    app(ReceiveWebChatMessage::class)->handle($thread, '+92 321 1234567');
    expect($thread->widget_state)->toBe('asked_service')->and(Lead::count())->toBe(1)
        ->and($thread->lead->service)->toBeNull();
    Event::assertDispatchedTimes(LeadCaptured::class, 1);
});

it('captures email immediately with the followup anchor and no automatic email', function () {
    $thread = intake48('visitor48@example.test');
    expect($thread->contact->email)->toBe('visitor48@example.test')
        ->and($thread->lead->tasks()->where('title', AskForPhoneNumber::TASK_TITLE)->count())->toBe(1);
    app(ReceiveWebChatMessage::class)->handle($thread, 'Office fit-out');
    expect($thread->lead->tasks()->where('title', AskForPhoneNumber::TASK_TITLE)->count())->toBe(1);
    Mail::assertNothingOutgoing();
    Event::assertDispatchedTimes(LeadCaptured::class, 1);
});

it('does not create a CRM lead from invalid contact details', function () {
    $thread = intake48('not a phone or email');
    expect($thread->lead_id)->toBeNull()->and($thread->widget_state)->toBe('asked_number')
        ->and(Lead::count())->toBe(0);
    Event::assertNotDispatched(LeadCaptured::class);
});

it('resumes legacy partial intake and preserves an existing open lead service', function () {
    $result = app(CaptureLead::class)->handle(new LeadInput(name: 'Existing Client', phone: '03211234567', email: null, source: LeadSource::WebsiteChat, service: 'Established kitchen scope'));
    $thread = Conversation::factory()->create(['channel' => Channel::WebChat, 'subject' => 'Returning Client', 'widget_state' => 'asked_service|+923211234567', 'contact_id' => null, 'lead_id' => null]);
    app(ReceiveWebChatMessage::class)->handle($thread, 'New wardrobe request');
    expect($thread->lead_id)->toBe($result->lead->id)->and(Lead::count())->toBe(1)
        ->and($thread->lead->service)->toBe('Established kitchen scope')
        ->and($thread->lead->activities()->where('body', 'like', '%New wardrobe request%')->exists())->toBeTrue();
});

it('reloads intake state under lock rather than recapturing from a stale model', function () {
    $thread = app(StartWebChat::class)->handle(null);
    app(ReceiveWebChatMessage::class)->handle($thread, 'Stale State Visitor');
    $stale = Conversation::findOrFail($thread->id);
    app(ReceiveWebChatMessage::class)->handle($thread, '03211234567');
    app(ReceiveWebChatMessage::class)->handle($stale, '03211234567');
    expect(Lead::count())->toBe(1)->and($stale->widget_state)->toBe('asked_service');
    Event::assertDispatchedTimes(LeadCaptured::class, 1);
});

it('hands intake to a real staff reply without a competing bot question', function () {
    $thread = app(StartWebChat::class)->handle(null);
    app(SendConversationMessage::class)->handle($thread, 'A team member is helping now.', $this->owner);
    $before = $thread->messages()->count();
    app(ReceiveWebChatMessage::class)->handle($thread, 'Please help me');
    expect($thread->widget_state)->toBe('live')->and($thread->messages()->count())->toBe($before + 1);
});

it('does not treat internal notes as staff takeover', function () {
    $thread = app(StartWebChat::class)->handle(null);
    Message::factory()->create(['conversation_id' => $thread->id, 'direction' => 'out', 'type' => 'note', 'user_id' => $this->owner->id]);
    app(ReceiveWebChatMessage::class)->handle($thread, 'Still Visitor');
    expect($thread->widget_state)->toBe('asked_number');
});

it('reopens a closed website thread when the visitor returns', function () {
    $thread = intake48();
    $thread->update(['status' => ConversationStatus::Closed, 'closed_at' => now(), 'unread_count' => 0]);
    app(ReceiveWebChatMessage::class)->handle($thread, 'I am back');
    expect($thread->status)->toBe(ConversationStatus::Open)->and($thread->closed_at)->toBeNull()
        ->and($thread->unread_count)->toBeGreaterThan(0);
});

it('records the first real staff reply on the linked lead', function () {
    $thread = intake48();
    expect($thread->lead->first_response_at)->toBeNull();
    app(SendConversationMessage::class)->handle($thread, 'We can help.', $this->owner);
    $at = $thread->lead()->firstOrFail()->first_response_at;
    expect($at)->not->toBeNull();
    $this->travel(5)->minutes();
    app(SendConversationMessage::class)->handle($thread, 'Another reply.', $this->owner);
    expect($thread->lead()->firstOrFail()->first_response_at->equalTo($at))->toBeTrue();
});

it('shows anonymous and incomplete SQL conversations in the Web Chat tab', function () {
    $thread = intake48();
    app(StartWebChat::class)->handle(null);
    $this->get('/admin/leads?tab=web_chat')->assertOk()->assertSee('Web Chat Leads');
    Livewire::test(WebChatLeads::class)->assertSee('Website QA Visitor')->assertSee('Anonymous website visitor')
        ->set('focus', 'qualify')->assertSee('Website QA Visitor')->assertDontSee('Anonymous website visitor')
        ->set('search', 'no-match-48')->assertSee('No website conversations match')
        ->call('clearFilters')->assertSee('Website QA Visitor')
        ->set('from', '2026-99-99')->assertSee('Invalid date filter ignored');
});

it('never includes other channels in website tracking', function () {
    Conversation::factory()->create(['channel' => Channel::Whatsapp, 'subject' => 'Other channel secret']);
    Livewire::test(WebChatLeads::class)->assertDontSee('Other channel secret');
});

it('scopes linked lead tracking to lead visibility while keeping anonymous intake accessible', function () {
    $hidden = intake48();
    $hidden->lead()->update(['owner_id' => $this->owner->id]);
    $sales = $this->userWithRole('sales');
    $this->actingAs($sales);
    Livewire::test(WebChatLeads::class)->assertDontSee('Website QA Visitor');
    $hidden->lead()->update(['owner_id' => $sales->id]);
    Livewire::test(WebChatLeads::class)->assertSee('Website QA Visitor');
});

it('requires both CRM and inbox permissions for the tab and its component', function () {
    $this->actingAs($this->userWithRole('editor'));
    Livewire::test(WebChatLeads::class)->assertForbidden();
    $this->get('/admin/leads?tab=web_chat')->assertForbidden();
});

it('filters unread assignment status date and overdue lead targets', function () {
    $thread = intake48();
    $thread->update(['assignee_id' => $this->owner->id, 'status' => 'pending']);
    $thread->lead()->update(['sla_due_at' => now()->subHour()]);
    Livewire::test(WebChatLeads::class)->set('focus', 'mine')->set('status', 'pending')->assertSee('Website QA Visitor')
        ->set('from', now()->addDay()->toDateString())->assertDontSee('Website QA Visitor')
        ->set('from', '')->set('focus', 'overdue')->assertSee('Website QA Visitor')
        ->set('focus', 'unassigned')->assertDontSee('Website QA Visitor');
});

it('keeps an in-progress draft when a different website thread arrives', function () {
    $first = intake48();
    $second = app(StartWebChat::class)->handle(null);
    $bell = Livewire::test(LiveChatBell::class)->call('select', $first->id)->call('openDrawer')->set('draft', 'Unsaved reply');
    app(ReceiveWebChatMessage::class)->handle($second, 'Another Visitor');
    $bell->call('poll')->assertSet('selectedId', $first->id)->assertSet('draft', 'Unsaved reply')
        ->assertSet('pendingId', $second->id)->assertSee('Switch to new message')
        ->call('switchPending')->assertSet('selectedId', $second->id)->assertSet('draft', '')->assertSet('pendingId', null);
});

it('keeps a closed drawer draft safe when a different thread triggers automatic opening', function () {
    $first = intake48();
    $second = app(StartWebChat::class)->handle(null);
    $bell = Livewire::test(LiveChatBell::class)->call('select', $first->id)->set('draft', 'Closed but retained');
    app(ReceiveWebChatMessage::class)->handle($second, 'New Visitor');
    $bell->call('poll')->assertSet('drawer', true)->assertSet('selectedId', $first->id)
        ->assertSet('draft', 'Closed but retained')->assertSet('pendingId', $second->id);
});

it('marks a matching open thread read without replacing the composer draft', function () {
    $thread = intake48();
    $bell = Livewire::test(LiveChatBell::class)->call('select', $thread->id)->call('openDrawer')->set('draft', 'Keep writing');
    app(ReceiveWebChatMessage::class)->handle($thread, 'Kitchen');
    $bell->call('poll')->assertSet('draft', 'Keep writing')->assertSet('selectedId', $thread->id);
    expect($thread->refresh()->unread_count)->toBe(0);
    $bell->call('closeDrawer')->call('poll')->assertSet('drawer', false)->assertNotDispatched('chat-alert');
});

it('rejects cross-channel direct thread opening even with an existing draft', function () {
    $other = Conversation::factory()->create(['channel' => Channel::Whatsapp]);
    Livewire::test(LiveChatBell::class)->set('draft', 'Do not lose')->call('openThread', $other->id)->assertForbidden();
});

it('offers a three-mode native theme without the misleading header pill', function () {
    $this->get('/admin/leads')->assertOk()->assertSee('Color theme')->assertSee('System')->assertDontSee('Demo data');
});

it('stores long valid email in the contact rather than overflowing the intake state column', function () {
    $email = str_repeat('a', 60).'@'.str_repeat('b', 60).'.example.test';
    $thread = intake48($email);
    expect($thread->contact->email)->toBe($email)->and($thread->widget_state)->toBe('asked_service');
    app(ReceiveWebChatMessage::class)->handle($thread, 'Office design');
    expect($thread->lead->service)->toBe('Office design')->and(Lead::count())->toBe(1);
});

it('shows legacy captured contact details read-only without a historical backfill', function () {
    $thread = Conversation::factory()->create(['channel' => Channel::WebChat, 'subject' => 'Legacy Waiting Visitor', 'contact_id' => null, 'lead_id' => null, 'widget_state' => 'asked_service|email:legacy48@example.test']);
    Livewire::test(WebChatLeads::class)->set('focus', 'qualify')->assertSee('Legacy Waiting Visitor')
        ->assertSee('legacy48@example.test')->assertSee('Legacy intake · CRM capture pending');
    expect($thread->fresh()->lead_id)->toBeNull()->and(Lead::count())->toBe(0);
});

it('does not discard a draft when reopening the already selected thread', function () {
    $thread = intake48();
    Livewire::test(LiveChatBell::class)->call('select', $thread->id)->set('draft', 'Keep the current reply')
        ->call('openThread', $thread->id)->assertSet('draft', 'Keep the current reply')->assertSet('drawer', true)
        ->call('closeDrawer')->call('openThread', $thread->id)->assertSet('draft', 'Keep the current reply');
});

it('never turns visitor service text into privileged staff mention notifications', function () {
    Notification::fake();
    $thread = intake48();
    app(ReceiveWebChatMessage::class)->handle($thread, 'Kitchen design for @'.$this->owner->name);
    Notification::assertNothingSent();
    expect($thread->lead->activities()->latest('id')->first()->data)->toBeNull();
});

it('uses PKT calendar boundaries for tracking dates rather than the stored UTC day', function () {
    // This fixture explicitly stores UTC; default application timestamps use app.timezone.
    config(['app.timezone' => 'UTC']);
    Conversation::factory()->create(['channel' => Channel::WebChat, 'subject' => 'PKT midnight visitor', 'contact_id' => null, 'lead_id' => null, 'created_at' => '2026-10-28 20:00:00']);
    Livewire::test(WebChatLeads::class)->set('from', '2026-10-29')->set('to', '2026-10-29')
        ->assertSee('PKT midnight visitor')->set('from', '2026-10-28')->set('to', '2026-10-28')
        ->assertDontSee('PKT midnight visitor');
});


it('compares PKT chat date controls in the configured timestamp storage timezone', function (string $timezone) {
    config(['app.timezone' => $timezone]);
    $thread = intake48();
    $at = \Carbon\CarbonImmutable::parse('2026-11-01 23:30:00', 'Asia/Karachi')->setTimezone($timezone);
    $thread->forceFill(['created_at' => $at])->save();
    Livewire::test(WebChatLeads::class)->set('from', '2026-11-02')->assertDontSee('Website QA Visitor')
        ->set('from', '2026-11-01')->set('to', '2026-11-01')->assertSee('Website QA Visitor');
})->with(['UTC', 'Asia/Karachi']);
