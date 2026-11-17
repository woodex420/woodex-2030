<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Models;

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Models\User;
use App\Support\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Database\Factories\Conversations\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thread with one contact on one channel (CNV-001).
 *
 * @property int $id
 * @property string $public_id
 * @property Channel $channel
 * @property ?int $contact_id
 * @property ?int $lead_id
 * @property ?int $assignee_id
 * @property ?string $subject
 * @property ConversationStatus $status
 * @property ?string $external_number
 * @property int $unread_count
 * @property ?CarbonImmutable $window_expires_at
 * @property ?CarbonImmutable $last_message_at
 * @property ?string $last_direction
 * @property ?string $last_preview
 * @property ?CarbonImmutable $snoozed_until
 * @property ?CarbonImmutable $closed_at
 * @property ?array<int, string> $labels
 * @property Collection<int, Message> $messages
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected static function newFactory(): ConversationFactory
    {
        return ConversationFactory::new();
    }

    use HasPublicId;

    /** @param Builder<self> $query */
    public function scopeAwaitingService(Builder $query): void
    {
        $query->where(fn (Builder $state) => $state->where('widget_state', 'asked_service')
            ->orWhere('widget_state', 'like', 'asked_service|%'));
    }

    protected $fillable = [
        'channel', 'contact_id', 'lead_id', 'assignee_id', 'subject', 'status', 'external_number',
        'unread_count', 'window_expires_at', 'last_message_at', 'last_direction', 'last_preview',
        'snoozed_until', 'closed_at', 'closed_by', 'labels', 'widget_token', 'widget_state',
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => ConversationStatus::class,
            'unread_count' => 'integer',
            'labels' => 'array',
            'window_expires_at' => 'immutable_datetime',
            'last_message_at' => 'immutable_datetime',
            'snoozed_until' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'reply_due_at' => 'immutable_datetime',
            'reply_sla_breached_at' => 'immutable_datetime',
            'reply_sla_escalated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Meta's rule and nothing else: a client's message opens 24 hours of free-form replies (CNV-005, WAP-004).
     * Outside the window the composer must offer an approved template instead.
     */
    public function isInWindow(?CarbonImmutable $at = null): bool
    {
        return $this->window_expires_at !== null && $this->window_expires_at->gt($at ?? now());
    }

    /**
     * Whether the team may type a free-form reply right now: inside Meta's window on WhatsApp,
     * always on the studio's own website chat (P8.5) — there is no window on a thread the
     * visitor opened themselves.
     */
    public function allowsFreeFormReply(?CarbonImmutable $at = null): bool
    {
        return $this->channel === Channel::WebChat || $this->isInWindow($at);
    }

    /** How long the free-form window is still open, in minutes (0 when it is not). */
    public function windowMinutesLeft(?CarbonImmutable $at = null): int
    {
        if (! $this->isInWindow($at)) {
            return 0;
        }

        return max(0, (int) floor(($at ?? now())->diffInMinutes($this->window_expires_at, false)));
    }

    public function markRead(): void
    {
        $this->update(['unread_count' => 0]);
    }

    /** After a turn is written: the preview the list shows, and the counters the filters use. */
    public function touchLastMessage(MessageDirection $direction, string $preview, ?CarbonImmutable $at = null): void
    {
        $at ??= now();

        $this->update([
            'last_message_at' => $at,
            'last_direction' => $direction->value,
            'last_preview' => mb_substr($preview, 0, 240),
            'unread_count' => $direction === MessageDirection::In ? ((int) $this->unread_count) + 1 : (int) $this->unread_count,
        ]);

        $this->contact?->forceFill(['last_activity_at' => $at])->save();
    }
}
