<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard\Widgets;

use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\Figure;
use App\Domain\System\Dashboard\Widget;
use App\Models\User;

/**
 * The conversations that wait in the inbox: those with something unread, and those that are open. It belongs
 * to the optional Inbox module, so on an installation where that module is switched off this widget is absent
 * (config/modules.php; the Furniture installation starts with it off).
 */
final class Inbox extends Widget
{
    public function key(): string
    {
        return 'inbox';
    }

    public function title(): string
    {
        return 'Conversations';
    }

    public function description(): string
    {
        return 'What waits in the inbox now';
    }

    public function module(): ?string
    {
        return 'inbox';
    }

    public function visibleTo(User $user): bool
    {
        return $user->can('inbox.view');
    }

    public function width(): string
    {
        return 'narrow';
    }

    public function data(DashboardContext $context): array
    {
        return [
            // The addresses are the inbox's own filters: "Unread" is every status with something unread, and
            // the inbox opens on the open conversations.
            'unread' => new Figure('inbox.unread', 'Unread', Conversation::query()->where('unread_count', '>', 0)->count(),
                route('admin.inbox', ['status' => 'all', 'unreadOnly' => 'true']), noun: ['conversation', 'conversations'], tone: 'warning'),
            'open' => new Figure('inbox.open', 'Open', Conversation::query()->where('status', ConversationStatus::Open->value)->count(),
                route('admin.inbox'), noun: ['conversation', 'conversations']),
        ];
    }

    public function isEmpty(array $data): bool
    {
        return $data['unread']->count === 0 && $data['open']->count === 0;
    }

    public function emptyWords(array $data = []): string
    {
        return 'No conversation is open and nothing is unread.';
    }
}
