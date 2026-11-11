<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Notifications\MentionedNotification;
use App\Domain\System\Alerts\AlertRouter;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * @mentions in notes (GLB-005): "@Sara", "@Sara Khan" or "@sara.khan" (email name) notify that
 * teammate in-app and on Telegram. The author is never notified about their own mention.
 */
final class NotifyMentions
{
    /**
     * @return Collection<int, User> the people who were notified
     */
    public function handle(Activity $activity, ?User $author = null): Collection
    {
        $body = (string) $activity->body;

        if (! str_contains($body, '@')) {
            return new Collection;
        }

        $mentioned = User::query()
            ->with('telegramLink')
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $user->id !== $author?->id && self::mentions($body, $user))
            ->values();

        if ($mentioned->isEmpty()) {
            return $mentioned;
        }

        $activity->forceFill(['data' => array_merge($activity->data ?? [], ['mentions' => $mentioned->modelKeys()])])->save();
        $activity->loadMissing(['lead.contact', 'contact']);

        app(AlertRouter::class)->send('crm.mentioned', $mentioned, new MentionedNotification($activity, $author));

        return $mentioned;
    }

    public static function mentions(string $body, User $user): bool
    {
        $names = array_filter([
            $user->name,
            Str::before($user->email, '@'),
            Str::before($user->name, ' '),
        ], fn (string $name): bool => mb_strlen($name) >= 2);

        $alternatives = implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), $names));

        return (bool) preg_match('/(?:^|[\s(,])@(?:'.$alternatives.')(?![\p{L}\p{N}])/iu', $body);
    }

    /**
     * Escaped HTML with @mentions emphasised, for timelines.
     */
    public static function highlight(?string $body): string
    {
        return (string) preg_replace(
            '/(^|[\s(,])(@[\p{L}\p{N}][\p{L}\p{N}._-]*)/u',
            '$1<span class="font-medium text-navy-700 dark:text-white">$2</span>',
            e((string) $body),
        );
    }
}
