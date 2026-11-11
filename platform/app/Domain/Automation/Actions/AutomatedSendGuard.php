<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Settings\SalesSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * AUT-006: the limits every automated message passes before it goes out.
 *
 * No automated message between quiet hours (default 21:00–09:00) — unless the client wrote
 * first, because answering someone who just wrote is a reply, not marketing. And never more
 * than N automated messages to one client per day, sequences combined. Returns the moment the
 * send may happen: now, or the next legal morning. The caller reschedules and logs why.
 */
final class AutomatedSendGuard
{
    /** A client's message inside this window makes any hour a fair hour to answer. */
    private const int REPLY_GRACE_MINUTES = 60;

    public function __construct(private readonly SalesSettings $settings) {}

    /**
     * When an automated message to this conversation may go out. `now()` when it may go now.
     */
    public function earliestAllowed(Conversation $conversation): CarbonInterface
    {
        $now = CarbonImmutable::now();

        if ($this->clientWroteRecently($conversation, $now)) {
            return $now;
        }

        $start = $this->clock($this->settings->automation_quiet_start, '21:00');
        $end = $this->clock($this->settings->automation_quiet_end, '09:00');

        if ($this->inQuietHours($now, $start, $end)) {
            // Hold until the morning the studio reopens for automated sends, with a small buffer.
            $morning = $now->setTimeFromTimeString($end->format('H:i'))->addMinutes(5);

            return $morning->lessThanOrEqualTo($now) ? $morning->addDay() : $morning;
        }

        return $now;
    }

    /**
     * How many automated messages (no staff author, actually sent) reached this client today.
     */
    public function sentToday(Conversation $conversation): int
    {
        return Message::query()
            ->join('conversations as c', 'c.id', '=', 'messages.conversation_id')
            ->where('c.contact_id', $conversation->contact_id)
            ->whereIn('c.channel', [Channel::Whatsapp->value])
            ->where('messages.direction', MessageDirection::Out->value)
            ->whereNull('messages.user_id')
            ->where('messages.type', '!=', 'note')
            ->where('messages.status', '!=', MessageStatus::Failed->value)
            ->whereBetween('messages.created_at', [now()->startOfDay(), now()->endOfDay()])
            ->count();
    }

    /**
     * True when the daily cap is already used up for this client.
     */
    public function dailyCapReached(Conversation $conversation): bool
    {
        $limit = $this->settings->automation_daily_contact_limit;

        return $limit > 0 && $this->sentToday($conversation) >= $limit;
    }

    /**
     * The next morning slot for a send held by the cap — tomorrow, after quiet hours end.
     */
    public function nextDailySlot(): CarbonInterface
    {
        $end = $this->clock($this->settings->automation_quiet_end, '09:00');

        return CarbonImmutable::now()->addDay()->setTimeFromTimeString($end->format('H:i'))->addMinutes(5);
    }

    private function clientWroteRecently(Conversation $conversation, CarbonImmutable $now): bool
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::In->value)
            ->where('created_at', '>=', $now->subMinutes(self::REPLY_GRACE_MINUTES))
            ->exists();
    }

    private function inQuietHours(CarbonImmutable $now, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        // A window that wraps midnight (21:00 → 09:00) versus one inside a day (00:00 → 06:00).
        return $start->greaterThan($end)
            ? $now->format('Hi') >= $start->format('Hi') || $now->format('Hi') < $end->format('Hi')
            : $now->format('Hi') >= $start->format('Hi') && $now->format('Hi') < $end->format('Hi');
    }

    private function clock(string $value, string $fallback): CarbonImmutable
    {
        $value = trim($value);

        if (! preg_match('/^\d{1,2}:\d{2}$/', $value)) {
            $value = $fallback;
        }

        [$hour, $minute] = array_map('intval', explode(':', $value));

        return CarbonImmutable::now()->setTime($hour, $minute);
    }
}
