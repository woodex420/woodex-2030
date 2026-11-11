<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Enums;

/** Where a conversation stands in the inbox (CNV-001). */
enum ConversationStatus: string
{
    case Open = 'open';
    case Pending = 'pending'; // waiting on the client
    case Snoozed = 'snoozed';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Pending => 'Pending',
            self::Snoozed => 'Snoozed',
            self::Closed => 'Closed',
        };
    }
}
