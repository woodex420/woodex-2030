<?php

use App\Domain\Conversations\Actions\ReceiveWhatsAppMessage;
use App\Domain\Conversations\Actions\SendConversationMessage;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Enums\MessageType;
use App\Domain\Conversations\Exceptions\OutsideWindow;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Livewire\Admin\Conversations\Inbox;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| CNV-008: media back to the client. A photograph, a PDF, a voice note or a clip goes through the
| same rules as text — consent, the 24-hour window — lands in Meta via upload + send, and the
| studio keeps its own copy so the thread still shows it next year. The thread now renders media
| both ways: the client's photographs were stored but invisible before this.
*/

beforeEach(function (): void {
    config()->set('services.whatsapp', [
        'token' => 'test-token',
        'phone_number_id' => '1234567890',
        'waba_id' => '987654321',
        'app_secret' => 'app-secret',
        'verify_token' => 'verify-me',
        'api_version' => 'v23.0',
        'api_base' => 'https://graph.facebook.com',
    ]);

    $this->gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $this->gateway);

    $this->owner = $this->userWithRole('owner');
    Storage::fake('local');
});

/** A WhatsApp conversation with the window open, fresh from an inbound message. */
function whatsappConversation(object $test): Conversation
{
    app(ReceiveWhatsAppMessage::class)->handle([
        'messaging_product' => 'whatsapp',
        'contacts' => [['wa_id' => '923224007688', 'profile' => ['name' => 'Bilal Mirza']]],
        'messages' => [['from' => '923224007688', 'id' => 'wamid.IN'.random_int(1000, 9999), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Assalam o Alaikum']]],
    ]);

    return Conversation::query()->sole();
}

it('sends a photograph with a caption and keeps the studio copy', function () {
    $conversation = whatsappConversation($this);
    $file = UploadedFile::fake()->image('wardrobe.jpg', 800, 600);

    $message = app(SendConversationMessage::class)->media($conversation, $file, 'Walnut finish, like this', $this->owner);

    expect($message->type)->toBe(MessageType::Image)
        ->and($message->status)->toBe(MessageStatus::Sent)
        ->and($message->body)->toBe('Walnut finish, like this')
        ->and($message->media_disk)->toBe('local')
        ->and($message->media_path)->not->toBeNull();

    Storage::disk('local')->assertExists($message->media_path);

    expect($this->gateway->uploads)->toHaveCount(1)
        ->and($this->gateway->uploads[0]['mime'])->toBe('image/jpeg')
        ->and($this->gateway->mediaSent)->toHaveCount(1)
        ->and($this->gateway->mediaSent[0]['type'])->toBe('image')
        ->and($this->gateway->mediaSent[0]['caption'])->toBe('Walnut finish, like this')
        ->and($conversation->refresh()->last_preview)->toBe('Photograph — Walnut finish, like this')
        ->and($conversation->reply_due_at)->toBeNull(); // a reply is a reply: the SLA clock stops
});

it('sends a PDF with its filename, and a voice note without a caption', function () {
    $conversation = whatsappConversation($this);

    $pdf = UploadedFile::fake()->create('quotation-1024.pdf', 120, 'application/pdf');
    $message = app(SendConversationMessage::class)->media($conversation, $pdf, '', $this->owner);

    expect($message->type)->toBe(MessageType::Document)
        ->and($this->gateway->mediaSent[0]['filename'])->toBe('quotation-1024.pdf')
        ->and($this->gateway->mediaSent[0]['caption'])->toBeNull();

    $voice = UploadedFile::fake()->create('note.m4a', 40, 'audio/mp4');
    $voiceMessage = app(SendConversationMessage::class)->media($conversation, $voice, '', $this->owner);

    expect($voiceMessage->type)->toBe(MessageType::Audio)
        ->and($voiceMessage->body)->toBeNull();
});

it('refuses what WhatsApp refuses, and respects the window like text', function () {
    $conversation = whatsappConversation($this);

    // Not a kind WhatsApp takes.
    $spreadsheet = UploadedFile::fake()->create('boq.xlsx', 20, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    app(SendConversationMessage::class)->media($conversation, $spreadsheet, '', $this->owner);
})->throws(ValidationException::class);

it('marks the message failed when Meta refuses the upload, but keeps the copy', function () {
    $conversation = whatsappConversation($this);
    $this->gateway->fails('Meta storage rejected the file.');

    // The fake fails the *send*; simulate the upload leg failing by unconfiguring after arming.
    $file = UploadedFile::fake()->image('photo.png', 100, 100);

    $this->gateway->configured(false);
    $message = app(SendConversationMessage::class)->media($conversation, $file, '', $this->owner);

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->error_message)->not->toBeNull()
        ->and($message->media_path)->not->toBeNull();

    Storage::disk('local')->assertExists($message->media_path);
});

it('never sends media outside the 24-hour window', function () {
    $conversation = whatsappConversation($this);
    $conversation->forceFill(['window_expires_at' => now()->subMinute()])->save();

    $file = UploadedFile::fake()->image('late.jpg', 100, 100);
    app(SendConversationMessage::class)->media($conversation, $file, 'too late', $this->owner);
})->throws(OutsideWindow::class);

it('serves a message media file only to people who may read the conversation', function () {
    $conversation = whatsappConversation($this);
    $file = UploadedFile::fake()->image('render.jpg', 120, 90);
    $message = app(SendConversationMessage::class)->media($conversation, $file, '', $this->owner);

    $viewer = $this->userWithRole('sales');

    $this->actingAs($viewer)->get(route('admin.conversations.media', $message))->assertOk();
    $this->actingAs($viewer)->get(route('admin.conversations.media', [$message, 'download' => 1]))->assertOk();

    $stranger = User::query()->create([
        'name' => 'No Inbox', 'email' => 'noinbox@woodex.test', 'password' => 'password123',
        'is_active' => true,
    ]);

    $this->actingAs($stranger)->get(route('admin.conversations.media', $message))->assertForbidden();

    // A text message has no media: 404, not an error.
    $text = $conversation->messages()->where('type', 'text')->firstOrFail();
    $this->actingAs($viewer)->get(route('admin.conversations.media', $text))->assertNotFound();
});

it('uploads and sends from the composer, with the caption doubling as the draft', function () {
    $conversation = whatsappConversation($this);

    Livewire::actingAs($this->owner)
        ->test(Inbox::class)
        ->call('select', $conversation->id)
        ->set('draft', 'This is the shade we discussed')
        ->set('attachment', UploadedFile::fake()->image('shade.jpg', 640, 480))
        ->call('send')
        ->assertHasNoErrors();

    $message = $conversation->messages()->where('direction', MessageDirection::Out)->where('type', 'image')->firstOrFail();

    expect($message->body)->toBe('This is the shade we discussed')
        ->and($message->status)->toBe(MessageStatus::Sent)
        ->and($this->gateway->mediaSent)->toHaveCount(1);
});

it('shows the attach button only on WhatsApp conversations', function () {
    $conversation = whatsappConversation($this);

    Livewire::actingAs($this->owner)
        ->test(Inbox::class)
        ->call('select', $conversation->id)
        ->assertSee('wire:model="attachment"', false);

    $webChat = Conversation::create([
        'channel' => 'web_chat', 'status' => 'open', 'last_direction' => 'in', 'unread_count' => 0,
        'last_message_at' => now(), 'widget_token' => 'tok', 'last_preview' => 'chat',
    ]);

    Livewire::actingAs($this->owner)
        ->test(Inbox::class)
        ->call('select', $webChat->id)
        ->assertDontSee('wire:model="attachment"', false);
});
