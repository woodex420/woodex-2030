<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Models;

use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Enums\MessageType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\Conversations\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn in a conversation (CNV-002, WAP-003/004).
 *
 * @property int $id
 * @property int $conversation_id
 * @property MessageDirection $direction
 * @property MessageType $type
 * @property ?string $body
 * @property ?string $external_id
 * @property MessageStatus $status
 * @property ?string $error_code
 * @property ?string $error_message
 * @property ?int $user_id
 * @property ?string $media_disk
 * @property ?string $media_path
 * @property ?string $media_mime
 * @property ?string $media_name
 * @property ?array<string, mixed> $meta
 * @property ?CarbonImmutable $sent_at
 * @property ?CarbonImmutable $delivered_at
 * @property ?CarbonImmutable $read_at
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected static function newFactory(): MessageFactory
    {
        return MessageFactory::new();
    }

    protected $fillable = [
        'conversation_id', 'direction', 'type', 'body', 'external_id', 'status', 'error_code', 'error_message',
        'user_id', 'media_disk', 'media_path', 'media_mime', 'media_name', 'meta', 'sent_at', 'delivered_at', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'type' => MessageType::class,
            'status' => MessageStatus::class,
            'meta' => 'array',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** An internal note is written by the team for the team: it never leaves the building (CNV-003). */
    public function isNote(): bool
    {
        return $this->type === MessageType::Note;
    }

    public function excerpt(int $length = 90): string
    {
        $text = $this->body ?? '';

        return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, $length - 1).'…';
    }
}
