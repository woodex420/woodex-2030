<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

use App\Support\Concerns\EnumHelpers;

/**
 * Where a WhatsApp template stands with Meta (WAP-005).
 *
 * Meta's own statuses, plus one that is ours: **Deleted** means a template we once synced is no longer in the
 * answer from Meta. It is never removed from our table, because a mapping or a log may still point at it, and a
 * template that vanishes from a business account is something the studio needs to see.
 */
enum WhatsAppTemplateStatus: string
{
    use EnumHelpers;

    case Approved = 'approved';
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Paused = 'paused';
    case Disabled = 'disabled';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Pending => 'In review',
            self::Rejected => 'Rejected',
            self::Paused => 'Paused',
            self::Disabled => 'Disabled',
            self::Deleted => 'Removed from Meta',
        };
    }

    /** TailAdmin badge colour, shared by the templates screen and the Integrations card. */
    public function color(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Pending => 'warning',
            self::Rejected, self::Disabled => 'error',
            self::Paused, self::Deleted => 'gray',
        };
    }

    /** Can a message be sent with this template right now? */
    public function usable(): bool
    {
        return $this === self::Approved;
    }

    /** Meta answers in capitals (APPROVED); everything else becomes our lowercase value. */
    public static function fromMeta(?string $status): self
    {
        return self::tryFrom(strtolower(trim((string) $status))) ?? self::Pending;
    }
}
