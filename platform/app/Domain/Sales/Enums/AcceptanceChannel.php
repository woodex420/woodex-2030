<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

use App\Support\Concerns\EnumHelpers;

/**
 * How a client's answer to a quotation reached the business when a member of staff records it. The client's own
 * page records its answers itself (channel "link"); these are the other ways a client says yes.
 */
enum AcceptanceChannel: string
{
    use EnumHelpers;

    case Phone = 'phone';
    case InPerson = 'in_person';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case SignedCopy = 'signed_copy';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'By phone',
            self::InPerson => 'In person',
            self::WhatsApp => 'On WhatsApp',
            self::Email => 'By e-mail',
            self::SignedCopy => 'On a signed copy',
        };
    }

    /** The end of a sentence on the timeline: "… accepted WF-10050 by phone." */
    public function phrase(): string
    {
        return match ($this) {
            self::Phone => 'by phone',
            self::InPerson => 'in person',
            self::WhatsApp => 'on WhatsApp',
            self::Email => 'by e-mail',
            self::SignedCopy => 'on a signed copy',
        };
    }
}
