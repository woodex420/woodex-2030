<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram;

use App\Models\User;

/**
 * One Telegram command (TGM-004). Implemented once, registered in the TelegramCommandBus, and reached only
 * through a linked, active staff account — the webhook controller never calls a command directly.
 *
 * A command answers with plain text and never sends anything itself: the controller escapes and sends, so a
 * command cannot forget escaping and cannot spam a chat by accident.
 */
interface TelegramCommand
{
    /** The command as typed, with the slash and without arguments, e.g. '/leads'. */
    public function name(): string;

    /** How it is written in /help, including its arguments, e.g. '/leads [new|mine|unassigned]'. */
    public function usage(): string;

    /** One line for /help. */
    public function description(): string;

    /** The permission a linked user needs; null when every linked staff member may run it. */
    public function permission(): ?string;

    /**
     * The reply for this command. Throw to report a fault: the bus catches it and answers with one honest
     * line instead of failing the webhook (Meta retries a 500 forever).
     */
    public function handle(User $user, string $argument): string;
}
