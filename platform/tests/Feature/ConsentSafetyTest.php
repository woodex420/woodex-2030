<?php

use App\Domain\Conversations\Actions\ReceiveWhatsAppMessage;
use App\Domain\Conversations\Actions\SendConversationMessage;
use App\Domain\Conversations\Exceptions\ConsentRequired;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Actions\MergeContacts;
use App\Domain\Crm\Actions\SaveContact;
use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Contact;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Notification::fake();
    $this->gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $this->gateway);
    $this->staff = $this->userWithRole('sales');
});

function stoppedContact(array $extra = []): Contact
{
    return Contact::factory()->create(array_replace([
        'phone' => '+923000000001', 'whatsapp' => '+923000000001',
        'consent_whatsapp' => false, 'consent_marketing' => false, 'opted_out_at' => now()->subDay(),
    ], $extra));
}

it('does not resurrect an opt out when a returning enquiry supplies consent checkboxes', function () {
    $contact = stoppedContact();
    $at = $contact->opted_out_at->toDateTimeString();
    app(CaptureLead::class)->handle(new LeadInput(
        name: 'Test returning client', phone: $contact->phone, email: null, source: LeadSource::WebsiteForm,
        consentMarketing: true, consentWhatsapp: true,
    ));
    expect($contact->refresh()->consent_whatsapp)->toBeFalse()->and($contact->consent_marketing)->toBeFalse()
        ->and($contact->opted_out_at->toDateTimeString())->toBe($at)->and($contact->leads()->count())->toBe(1);
});

it('keeps either side opt out sticky during a contact merge even with stale models', function (bool $targetStopped) {
    $target = Contact::factory()->create(['consent_whatsapp' => true, 'consent_marketing' => true]);
    $duplicate = Contact::factory()->create(['consent_whatsapp' => true, 'consent_marketing' => true]);
    $stopped = $targetStopped ? $target : $duplicate;
    Contact::query()->whereKey($stopped->id)->update(['opted_out_at' => now(), 'consent_whatsapp' => false, 'consent_marketing' => false]);
    app(MergeContacts::class)->handle($target, $duplicate, $this->staff);
    expect($target->refresh()->opted_out_at)->not->toBeNull()
        ->and($target->consent_whatsapp)->toBeFalse()->and($target->consent_marketing)->toBeFalse();
})->with([true, false]);

it('refuses ordinary consent restoration and rereads a stale contact before editing', function () {
    $contact = Contact::factory()->create(['phone' => '+923000000001', 'consent_whatsapp' => true]);
    Contact::query()->whereKey($contact->id)->update(['opted_out_at' => now(), 'consent_whatsapp' => false, 'consent_marketing' => false]);
    expect(fn () => app(SaveContact::class)->handle([
        'name' => 'Test contact', 'phone' => $contact->phone, 'consent_whatsapp' => true,
    ], $contact))->toThrow(ValidationException::class);
    $saved = app(SaveContact::class)->handle(['name' => 'Updated name', 'phone' => $contact->phone], $contact);
    expect($saved->name)->toBe('Updated name')->and($saved->consent_whatsapp)->toBeFalse()->and($saved->opted_out_at)->not->toBeNull();
});

it('does not use a new inbound message or START to undo STOP', function () {
    $contact = stoppedContact();
    $at = $contact->opted_out_at->toDateTimeString();
    foreach (['START', 'Please give me a quotation', 'STOP'] as $index => $body) {
        app(ReceiveWhatsAppMessage::class)->handle(['messages' => [[
            'id' => 'wamid.CONSENT-'.$index, 'from' => $contact->phone, 'type' => 'text',
            'timestamp' => (string) now()->timestamp, 'text' => ['body' => $body],
        ]]]);
    }
    expect($contact->refresh()->consent_whatsapp)->toBeFalse()->and($contact->consent_marketing)->toBeFalse()
        ->and($contact->opted_out_at->toDateTimeString())->toBe($at);
});

it('blocks both reply and template before provider calls when a contact opted out', function (bool $template) {
    $contact = stoppedContact();
    $conversation = Conversation::factory()->for($contact)->create();
    $action = app(SendConversationMessage::class);
    expect(fn () => $template
        ? $action->template($conversation, 'test_template', user: $this->staff)
        : $action->handle($conversation, 'Test response', $this->staff))->toThrow(ConsentRequired::class);
    expect($this->gateway->sent)->toBe([])->and(Message::query()->count())->toBe(0);
})->with([true, false]);

it('rereads opted out state even when a conversation and contact were loaded before STOP', function () {
    $contact = Contact::factory()->create(['consent_whatsapp' => true]);
    $conversation = Conversation::factory()->for($contact)->create();
    $conversation->load('contact');
    Contact::query()->whereKey($contact->id)->update(['opted_out_at' => now(), 'consent_whatsapp' => false]);
    expect(fn () => app(SendConversationMessage::class)->handle($conversation, 'Test response', $this->staff))->toThrow(ConsentRequired::class);
    expect($this->gateway->sent)->toBe([]);
});

it('requires explicit WhatsApp opt in for templates but does not infer marketing permission', function () {
    $contact = Contact::factory()->create(['consent_whatsapp' => false, 'consent_marketing' => false]);
    $conversation = Conversation::factory()->for($contact)->create();
    $action = app(SendConversationMessage::class);
    expect(fn () => $action->template($conversation, 'test_template', user: $this->staff))->toThrow(ConsentRequired::class);
    $contact->forceFill(['consent_whatsapp' => true])->save();
    $action->template($conversation, 'test_template', user: $this->staff);
    expect($this->gateway->sent)->toHaveCount(1)->and($contact->refresh()->consent_marketing)->toBeFalse();
});

it('refuses null inactive and unauthorized actors at the message action boundary', function (string $case) {
    $conversation = Conversation::factory()->create();
    $actor = match ($case) {
        'null' => null,
        'no-permission' => $this->userWithRole('editor'),
        default => $this->staff,
    };
    if ($case === 'inactive') {
        User::query()->whereKey($actor->id)->update(['is_active' => false]);
    }
    expect(fn () => app(SendConversationMessage::class)->handle($conversation, 'Test response', $actor))->toThrow(AuthorizationException::class);
    expect($this->gateway->sent)->toBe([])->and(Message::query()->count())->toBe(0);
})->with(['null', 'inactive', 'no-permission']);

it('allows an internal note about an opted out client without contacting them', function () {
    $conversation = Conversation::factory()->for(stoppedContact())->create();
    app(SendConversationMessage::class)->note($conversation, 'Do not message this contact', $this->staff);
    expect(Message::query()->sole()->isNote())->toBeTrue()->and($this->gateway->sent)->toBe([]);
});
