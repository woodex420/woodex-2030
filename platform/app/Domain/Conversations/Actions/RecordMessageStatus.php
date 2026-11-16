<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Actions;

use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Meta telling us what happened to a message we sent (WAP-004): delivered, read, or failed with a reason.
 *
 * Statuses only ever move forward — a message that has been read is not moved back to delivered when an old
 * webhook arrives late — and a failure keeps its code, so the inbox can say why.
 */
final class RecordMessageStatus
{
    private const array ORDER = [
        MessageStatus::Pending->value => 0,
        MessageStatus::Sent->value => 1,
        MessageStatus::Delivered->value => 2,
        MessageStatus::Read->value => 3,
        MessageStatus::Failed->value => 1,
    ];

    /**
     * @param  list<array<string, mixed>>|null  $errors  Meta's error entries, when it sends any
     */
    public function handle(string $externalId, string $status, ?array $errors = null): ?Message
    {
        $next = MessageStatus::tryFrom($status);

        if ($next === null) {
            return null;
        }

        return DB::transaction(function () use ($externalId, $next, $errors): ?Message {
            $message = Message::query()->where('external_id', $externalId)->where('direction', MessageDirection::Out)->lockForUpdate()->first();

            if ($message === null) {
                return null;
            }

            if (self::ORDER[$next->value] < self::ORDER[$message->status->value]
                || ($message->status === MessageStatus::Failed && $next === MessageStatus::Sent)) {
                return $message; // an older status arriving late changes nothing
            }

            $message->update([
                'status' => $next,
                'delivered_at' => $next === MessageStatus::Delivered ? ($message->delivered_at ?? now()) : $message->delivered_at,
                'read_at' => $next === MessageStatus::Read ? ($message->read_at ?? now()) : $message->read_at,
                'error_code' => $errors === null ? $message->error_code : (string) ($errors[0]['code'] ?? null),
                'error_message' => $errors === null ? $message->error_message : (string) ($errors[0]['title'] ?? null),
            ]);

            return $message;
        });
    }
}
