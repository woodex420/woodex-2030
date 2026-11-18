<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Actions;

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Enums\MessageType;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Crm\Models\Contact;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One inbound WhatsApp message (WAP-003).
 *
 * The number is matched to a contact first: a client who has already been quoted keeps their history, and a
 * stranger becomes a contact with a conversation of their own. A client's message also re-opens Meta's 24-hour
 * window — that is the rule, not a setting — and re-opens a closed conversation, because a client writing again
 * after handover is the start of something, not a reply to nothing.
 */
final class ReceiveWhatsAppMessage
{
    /** Words that mean "stop messaging me" (WAP-006). Matched on the trimmed, uppercased body. */
    private const array OPT_OUT = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'بند کریں'];

    public function __construct(
        private readonly WhatsAppGateway $whatsapp,
        private readonly SetConversationReplyDue $armReplyClock,
    ) {}

    /**
     * @param  array<string, mixed>  $value  The `entry[0].changes[0].value` part of Meta's webhook payload
     */
    public function handle(array $value, ?string $externalId = null): ?Message
    {
        $inbound = $externalId === null ? ($value['messages'][0] ?? null)
            : collect((array) ($value['messages'] ?? []))->first(fn (mixed $message): bool => is_array($message) && ($message['id'] ?? null) === $externalId);

        if (! is_array($inbound)) {
            return null;
        }

        $from = Phone::normalize((string) ($inbound['from'] ?? '')) ?? (string) ($inbound['from'] ?? '');

        if ($from === '') {
            return null;
        }

        return DB::transaction(function () use ($inbound, $from, $value, $externalId): Message {
            $id = $inbound['id'] ?? $externalId;
            if (is_string($id) && $id !== '') {
                $existing = Message::query()->where('external_id', $id)->where('direction', MessageDirection::In)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
            $timestamp = filter_var($inbound['timestamp'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            // Queued or replayed messages never grant a fresh 24 hours from the retry time.
            $receivedAt = $timestamp === false ? CarbonImmutable::now() : CarbonImmutable::createFromTimestampUTC($timestamp)->min(now());
            $contact = $this->contact($from, $value);
            $conversation = $this->conversation($contact, $from, $receivedAt);

            $type = $this->type((string) ($inbound['type'] ?? 'text'));
            $body = $this->body($inbound, $type);
            $media = $this->media($inbound, $type);

            /** @var Message $message */
            $message = $conversation->messages()->create([
                'direction' => MessageDirection::In,
                'type' => $type,
                'body' => $body,
                'external_id' => is_string($inbound['id'] ?? null) ? $inbound['id'] : $externalId,
                'status' => MessageStatus::Delivered, // it arrived: that is what delivered means for inbound
                ...$media,
            ]);

            $conversation->update([
                'status' => $conversation->status === ConversationStatus::Closed ? ConversationStatus::Open : $conversation->status,
                'closed_at' => null,
                'window_expires_at' => $conversation->window_expires_at?->max($receivedAt->addHours(24)) ?? $receivedAt->addHours(24),
            ]);

            if ($conversation->last_message_at === null || $receivedAt->gte($conversation->last_message_at)) {
                $conversation->touchLastMessage(MessageDirection::In, $this->preview($type, $body), $receivedAt);
            } else {
                $conversation->increment('unread_count');
            }

            $this->optOut($contact, $body);

            // CNV-007: the reply clock starts (or re-arms) with every inbound message.
            $this->armReplyClock->handle($conversation);

            return $message;
        });
    }

    /**
     * The contact behind the number: an existing client keeps their history, a stranger gets a fresh record.
     *
     * @param  array<string, mixed>  $value
     */
    private function contact(string $from, array $value): Contact
    {
        $digits = Phone::digits($from);

        $contact = Contact::query()
            ->where(fn ($query) => $query->where('whatsapp', $from)->orWhere('phone', $from))
            ->lockForUpdate()->first();

        if ($contact === null && $digits !== '') {
            $contact = Contact::query()
                ->whereRaw("REPLACE(REPLACE(REPLACE(COALESCE(whatsapp, ''), ' ', ''), '-', ''), '+', '') LIKE ?", ['%'.$digits])
                ->lockForUpdate()->first();
        }

        if ($contact !== null) {
            return $contact;
        }

        $profile = collect((array) ($value['contacts'] ?? []))->first(fn (mixed $item): bool => is_array($item) && Phone::normalize((string) ($item['wa_id'] ?? '')) === $from);
        $name = (string) ($profile['profile']['name'] ?? '');

        return Contact::create([
            'name' => $name !== '' ? $name : 'WhatsApp '.$from,
            'phone' => $from,
            'whatsapp' => $from,
            'source' => 'whatsapp',
            'consent_whatsapp' => true, // they wrote to us first: that is the consent Meta requires for replies
        ]);
    }

    private function conversation(Contact $contact, string $from, ?CarbonImmutable $receivedAt = null): Conversation
    {
        // The 24-hour window starts when the client actually wrote, not when this job happened to run —
        // otherwise a recovery replay of yesterday's message would reopen a closed window.
        $windowStart = $receivedAt ?? CarbonImmutable::now();

        return Conversation::query()->firstOrCreate(
            ['contact_id' => $contact->id, 'channel' => Channel::Whatsapp->value],
            ['external_number' => $from, 'status' => ConversationStatus::Open, 'window_expires_at' => $windowStart->addHours(24), 'unread_count' => 0],
        );
    }

    private function type(string $type): MessageType
    {
        return MessageType::tryFrom($type) ?? MessageType::Text;
    }

    /** @param  array<string, mixed>  $inbound */
    private function body(array $inbound, MessageType $type): ?string
    {
        return match ($type) {
            MessageType::Text => (string) ($inbound['text']['body'] ?? ''),
            MessageType::Image => (string) ($inbound['image']['caption'] ?? ''),
            MessageType::Document => (string) ($inbound['document']['caption'] ?? $inbound['document']['filename'] ?? ''),
            MessageType::Video => (string) ($inbound['video']['caption'] ?? ''),
            MessageType::Location => (string) ($inbound['location']['name'] ?? $inbound['location']['address'] ?? ''),
            MessageType::Reaction => (string) ($inbound['reaction']['emoji'] ?? ''),
            MessageType::Interactive => (string) ($inbound['interactive']['button_reply']['title']
                ?? $inbound['interactive']['list_reply']['title']
                ?? ''),
            MessageType::Contact => (string) ($inbound['contacts'][0]['name']['formatted_name'] ?? ''),
            default => null,
        };
    }

    /**
     * Inbound media is fetched from Meta (their links expire) and stored like any other upload, so a photograph
     * a client sent is still there when the job is quoted next year.
     *
     * @param  array<string, mixed>  $inbound
     * @return array{media_disk: string|null, media_path: string|null, media_mime: string|null, media_name: string|null, meta: array<string, mixed>|null}
     */
    private function media(array $inbound, MessageType $type): array
    {
        $empty = ['media_disk' => null, 'media_path' => null, 'media_mime' => null, 'media_name' => null, 'meta' => null];

        if ($type === MessageType::Location) {
            return [...$empty, 'meta' => [
                'latitude' => $inbound['location']['latitude'] ?? null,
                'longitude' => $inbound['location']['longitude'] ?? null,
            ]];
        }

        $mediaId = is_string($inbound[$type->value]['id'] ?? null) ? $inbound[$type->value]['id'] : null;

        if ($mediaId === null) {
            return $empty;
        }

        $descriptor = $this->whatsapp->media($mediaId);

        if ($descriptor === null) {
            return [...$empty, 'meta' => ['media_id' => $mediaId, 'download' => 'failed']];
        }

        try {
            $contents = Http::timeout(10)->connectTimeout(5)->get($descriptor['url'])->body();
        } catch (Throwable) {
            $contents = '';
        }

        if ($contents === '') {
            return [...$empty, 'meta' => ['media_id' => $mediaId, 'download' => 'failed']];
        }

        $extension = match (true) {
            str_contains($descriptor['mime_type'], 'jpeg'), str_contains($descriptor['mime_type'], 'jpg') => 'jpg',
            str_contains($descriptor['mime_type'], 'png') => 'png',
            str_contains($descriptor['mime_type'], 'pdf') => 'pdf',
            str_contains($descriptor['mime_type'], 'ogg'), str_contains($descriptor['mime_type'], 'opus') => 'ogg',
            str_contains($descriptor['mime_type'], 'mp4') => 'mp4',
            default => 'bin',
        };

        $path = 'conversations/'.now()->format('Y/m').'/'.$mediaId.'.'.$extension;
        Storage::disk('local')->put($path, $contents);

        return [
            'media_disk' => 'local',
            'media_path' => $path,
            'media_mime' => $descriptor['mime_type'],
            'media_name' => $inbound[$type->value]['filename'] ?? ($type->value.'-'.$mediaId.'.'.$extension),
            'meta' => ['media_id' => $mediaId, 'sha256' => $descriptor['sha256']],
        ];
    }

    private function preview(MessageType $type, ?string $body): string
    {
        if ($body !== null && $body !== '') {
            return $body;
        }

        return match ($type) {
            MessageType::Image => 'Photograph',
            MessageType::Document => 'Document',
            MessageType::Audio => 'Voice note',
            MessageType::Video => 'Video',
            MessageType::Location => 'Location',
            MessageType::Contact => 'Contact card',
            MessageType::Reaction => 'Reaction',
            default => 'Message',
        };
    }

    /** A client who says stop is heard: the whole point of an opt-out keyword is that it works at once (WAP-006). */
    private function optOut(Contact $contact, ?string $body): void
    {
        if ($body === null) {
            return;
        }

        $word = mb_strtoupper(trim($body));

        if (! in_array($word, self::OPT_OUT, true)) {
            return;
        }

        $contact->forceFill([
            'opted_out_at' => $contact->opted_out_at ?? now(),
            'consent_whatsapp' => false,
            'consent_marketing' => false,
        ])->save();
    }
}
