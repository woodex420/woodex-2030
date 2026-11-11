<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Enums;

/** What a message carries (WAP-003). `note` is an internal note: written by staff, never sent (CNV-003). */
enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Document = 'document';
    case Audio = 'audio';
    case Video = 'video';
    case Location = 'location';
    case Contact = 'contact';
    case Interactive = 'interactive';
    case Reaction = 'reaction';
    case Template = 'template';
    case Note = 'note';
}
