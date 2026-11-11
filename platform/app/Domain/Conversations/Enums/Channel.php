<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Enums;

/**
 * Where a conversation happens (CNV-001). WhatsApp and the website chat widget arrive in P4; Messenger,
 * Instagram and LinkedIn comments join the same inbox in P7 without changing a line here.
 */
enum Channel: string
{
    case Whatsapp = 'whatsapp';
    case WebChat = 'web_chat';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Whatsapp => 'WhatsApp',
            self::WebChat => 'Website chat',
            self::Email => 'Email',
        };
    }
}
