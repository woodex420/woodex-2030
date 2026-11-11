<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Actions;

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Enums\MessageType;
use App\Domain\Conversations\Exceptions\ConsentRequired;
use App\Domain\Conversations\Exceptions\OutsideWindow;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Conversations\Notifications\ConversationMentionedNotification;
use App\Domain\Crm\Actions\NotifyMentions;
use App\Domain\Crm\Models\Contact;
use App\Domain\System\Alerts\AlertRouter;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\SendResult;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A reply from the team (WAP-004).
 *
 * Meta's rule is enforced here rather than in the screen: inside the 24-hour window the message goes as written;
 * outside it, only an approved template may go out. A failed send is stored as a message with status `failed` and
 * Meta's error next to it — the team sees that the client did not receive it, instead of assuming they did.
 */
final class SendConversationMessage
{
    public function __construct(private readonly WhatsAppGateway $whatsapp) {}

    /**
     * @throws OutsideWindow when the window has closed and this is a free-form message
     */
    public function handle(Conversation $conversation, string $body, ?User $user = null): Message
    {
        $conversation = $this->authorizedConversation($conversation, $user);

        // Website chat (P8.5): no gateway and no WhatsApp rules apply. The visitor opened this
        // thread themselves, so there is no consent question and no 24-hour window — the message
        // is stored and the widget picks it up on its next poll.
        if ($conversation->channel === Channel::WebChat) {
            return $this->storeWebChatReply($conversation, $body, $user);
        }

        $this->assertConsent($conversation);
        $body = trim($body);

        if ($body === '') {
            throw new \InvalidArgumentException('An empty message is not sent.');
        }

        if (! $conversation->isInWindow()) {
            throw new OutsideWindow;
        }

        $result = $this->whatsapp->sendText((string) $conversation->external_number, $body);

        return DB::transaction(function () use ($conversation, $body, $user, $result): Message {
            /** @var Message $message */
            $message = $conversation->messages()->create([
                'direction' => MessageDirection::Out,
                'type' => MessageType::Text,
                'body' => $body,
                'user_id' => $user?->id,
                'external_id' => $result->messageId,
                'status' => $result->ok ? MessageStatus::Sent : MessageStatus::Failed,
                'error_message' => $result->ok ? null : $result->error,
                'sent_at' => $result->ok ? now() : null,
            ]);

            if ($result->ok) {
                $conversation->touchLastMessage(MessageDirection::Out, $body);
                $this->clearReplyClock($conversation);
            }

            return $message;
        });
    }

    /**
     * Media back to the client (CNV-008): a photograph, a PDF, a voice note, a clip.
     *
     * Meta's media id lives 30 days and is only a handle — the studio's copy is kept on the same
     * disk the inbound media lands on, so what was sent is still visible in the thread next year.
     * Sending media is a free-form message: the 24-hour window and consent apply exactly as for text.
     *
     * @throws OutsideWindow when the window has closed
     * @throws ValidationException when the file is not a kind WhatsApp takes, or is too large
     */
    public function media(Conversation $conversation, UploadedFile $file, string $caption = '', ?User $user = null): Message
    {
        $conversation = $this->authorizedConversation($conversation, $user);

        if ($conversation->channel !== Channel::Whatsapp) {
            throw new \InvalidArgumentException('Media can only be sent on WhatsApp conversations for now.');
        }

        $this->assertConsent($conversation);

        if (! $conversation->isInWindow()) {
            throw new OutsideWindow;
        }

        $type = $this->mediaType($file);
        $caption = trim($caption);

        // The studio's copy first: even a failed Meta upload stays in the history, marked failed, like text.
        $path = 'conversations/'.now()->format('Y/m').'/'.Str::ulid()->toBase32().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin');
        Storage::disk('local')->put($path, $file->getContent());

        $mediaId = $this->whatsapp->uploadMedia($file->getContent(), (string) $file->getMimeType(), (string) $file->getClientOriginalName());

        $result = $mediaId === null
            ? SendResult::failed('Meta did not accept the file.')
            : $this->whatsapp->sendMedia((string) $conversation->external_number, $type->value, $mediaId, $caption !== '' ? $caption : null, $type === MessageType::Document ? (string) $file->getClientOriginalName() : null);

        return DB::transaction(function () use ($conversation, $type, $caption, $user, $result, $path, $file): Message {
            /** @var Message $message */
            $message = $conversation->messages()->create([
                'direction' => MessageDirection::Out,
                'type' => $type,
                'body' => $caption !== '' ? $caption : null,
                'user_id' => $user?->id,
                'external_id' => $result->messageId,
                'status' => $result->ok ? MessageStatus::Sent : MessageStatus::Failed,
                'error_message' => $result->ok ? null : $result->error,
                'sent_at' => $result->ok ? now() : null,
                'media_disk' => 'local',
                'media_path' => $path,
                'media_mime' => (string) $file->getMimeType(),
                'media_name' => (string) $file->getClientOriginalName(),
                'meta' => ['sent' => true],
            ]);

            if ($result->ok) {
                $conversation->touchLastMessage(MessageDirection::Out, $this->mediaPreview($type, $caption));
                $this->clearReplyClock($conversation);
            }

            return $message;
        });
    }

    /** WhatsApp takes four kinds of media; the studio sends three of them on purpose (no stickers). */
    private function mediaType(UploadedFile $file): MessageType
    {
        $mime = strtolower((string) $file->getMimeType());

        $type = match (true) {
            str_starts_with($mime, 'image/') => MessageType::Image,
            str_starts_with($mime, 'audio/') => MessageType::Audio,
            str_starts_with($mime, 'video/') => MessageType::Video,
            $mime === 'application/pdf' => MessageType::Document,
            default => null,
        };

        if ($type === null) {
            throw ValidationException::withMessages([
                'attachment' => 'WhatsApp takes images, PDFs, audio and video — convert the file first ('.$mime.').',
            ]);
        }

        $limit = match ($type) {
            MessageType::Image => 5_000_000,
            MessageType::Document => 100_000_000,
            default => 16_000_000,
        };

        if ($file->getSize() > $limit) {
            throw ValidationException::withMessages([
                'attachment' => 'That file is '.number_format($file->getSize() / 1_000_000, 1).' MB; WhatsApp allows '.number_format($limit / 1_000_000).' MB for '.$type->value.'s.',
            ]);
        }

        return $type;
    }

    /** The words the conversation list shows for a media message — the same vocabulary the inbox uses. */
    private function mediaPreview(MessageType $type, string $caption): string
    {
        $word = match ($type) {
            MessageType::Image => 'Photograph',
            MessageType::Document => 'Document',
            MessageType::Audio => 'Voice note',
            MessageType::Video => 'Video',
            default => 'Media',
        };

        return $caption !== '' ? $word.' — '.$caption : $word;
    }

    /** A web_chat reply: stored directly, marked sent, shown by the widget's polling. */
    private function storeWebChatReply(Conversation $conversation, string $body, ?User $user): Message
    {
        $body = trim($body);

        if ($body === '') {
            throw new \InvalidArgumentException('An empty message is not sent.');
        }

        return DB::transaction(function () use ($conversation, $body, $user): Message {
            /** @var Message $message */
            $message = $conversation->messages()->create([
                'direction' => MessageDirection::Out,
                'type' => MessageType::Text,
                'body' => $body,
                'user_id' => $user?->id,
                'status' => MessageStatus::Sent,
                'sent_at' => now(),
            ]);

            $conversation->touchLastMessage(MessageDirection::Out, $body);
            $conversation->lead()->whereNull('first_response_at')->update([
                'first_response_at' => now(), 'last_activity_at' => now(),
            ]);

            return $message;
        });
    }

    /**
     * An approved template, the only thing Meta allows outside the window (WAP-004/WAP-005).
     *
     * @param  list<array<string, mixed>>  $components
     */
    public function template(Conversation $conversation, string $name, array $components = [], string $language = 'en', ?User $user = null): Message
    {
        $conversation = $this->authorizedConversation($conversation, $user);
        $this->assertConsent($conversation, template: true);
        $result = $this->whatsapp->sendTemplate((string) $conversation->external_number, $name, $language, $components);

        return DB::transaction(function () use ($conversation, $name, $components, $language, $user, $result): Message {
            /** @var Message $message */
            $message = $conversation->messages()->create([
                'direction' => MessageDirection::Out,
                'type' => MessageType::Template,
                'body' => $name,
                'user_id' => $user?->id,
                'external_id' => $result->messageId,
                'status' => $result->ok ? MessageStatus::Sent : MessageStatus::Failed,
                'error_message' => $result->ok ? null : $result->error,
                'meta' => ['template' => $name, 'language' => $language, 'components' => $components],
                'sent_at' => $result->ok ? now() : null,
            ]);

            if ($result->ok) {
                $conversation->touchLastMessage(MessageDirection::Out, 'Template: '.$name);
                $this->clearReplyClock($conversation);
            }

            return $message;
        });
    }

    /** An internal note: written for the team, never sent (CNV-003). @mentions notify the teammate (CNV-003b). */
    public function note(Conversation $conversation, string $body, User $user): Message
    {
        $conversation = $this->authorizedConversation($conversation, $user);
        /** @var Message $message */
        $message = $conversation->messages()->create([
            'direction' => MessageDirection::Out,
            'type' => MessageType::Note,
            'body' => trim($body),
            'user_id' => $user->id,
            'status' => MessageStatus::Sent,
            'sent_at' => now(),
        ]);

        $this->notifyMentions($message, $user);

        return $message;
    }

    /**
     * "@Sara", "@Sara Khan" or "@sara.khan" in a note: the same matcher the CRM notes use
     * (App\Domain\Crm\Actions\NotifyMentions), applied to the inbox. The author is never notified.
     */
    private function notifyMentions(Message $message, User $author): void
    {
        $body = (string) $message->body;

        if (! str_contains($body, '@')) {
            return;
        }

        $mentioned = User::query()
            ->with('telegramLink')
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $user->id !== $author->id && NotifyMentions::mentions($body, $user))
            ->values();

        if ($mentioned->isEmpty()) {
            return;
        }

        $message->forceFill(['meta' => array_merge($message->meta ?? [], ['mentions' => $mentioned->modelKeys()])])->save();
        app(AlertRouter::class)->send('conversation.mentioned', $mentioned, new ConversationMentionedNotification($message, $author));
    }

    /**
     * CNV-007: a staff reply stops the reply clock; the next inbound message arms a fresh one.
     */
    private function clearReplyClock(Conversation $conversation): void
    {
        $conversation->forceFill([
            'reply_due_at' => null,
            'reply_sla_breached_at' => null,
            'reply_sla_escalated_at' => null,
        ])->save();
    }

    private function authorizedConversation(Conversation $conversation, ?User $user): Conversation
    {
        $user = $user === null ? null : User::query()->find($user->id);
        if ($user === null || ! $user->is_active) {
            throw new AuthorizationException;
        }
        $conversation = Conversation::query()->findOrFail($conversation->id);
        Gate::forUser($user)->authorize('reply', $conversation);

        return $conversation;
    }

    private function assertConsent(Conversation $conversation, bool $template = false): void
    {
        $contact = $conversation->contact_id === null ? null : Contact::query()->find($conversation->contact_id);
        if ($contact === null || $contact->opted_out_at !== null || ($template && ! $contact->consent_whatsapp)) {
            throw new ConsentRequired;
        }
    }
}
