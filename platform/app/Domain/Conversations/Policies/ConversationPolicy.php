<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Policies;

use App\Domain\Conversations\Models\Conversation;
use App\Models\User;

/**
 * Who may open the inbox and who may answer (docs/02 §7): `inbox.view` reads the threads, `inbox.reply` writes
 * to a client. Reading a client's messages is not the same as being allowed to answer them.
 */
final class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inbox.view');
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $user->can('inbox.view');
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $user->can('inbox.reply');
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $user->can('inbox.reply');
    }
}
