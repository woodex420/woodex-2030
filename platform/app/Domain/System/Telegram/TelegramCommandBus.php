<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram;

use App\Domain\System\Telegram\Commands\ApprovalsCommand;
use App\Domain\System\Telegram\Commands\ApproveCommand;
use App\Domain\System\Telegram\Commands\AssignCommand;
use App\Domain\System\Telegram\Commands\CollectCommand;
use App\Domain\System\Telegram\Commands\DueCommand;
use App\Domain\System\Telegram\Commands\FindCommand;
use App\Domain\System\Telegram\Commands\HealthCommand;
use App\Domain\System\Telegram\Commands\LeadCommand;
use App\Domain\System\Telegram\Commands\LeadsCommand;
use App\Domain\System\Telegram\Commands\MuteCommand;
use App\Domain\System\Telegram\Commands\NoteCommand;
use App\Domain\System\Telegram\Commands\QuoteCommand;
use App\Domain\System\Telegram\Commands\QuotesCommand;
use App\Domain\System\Telegram\Commands\ReportCommand;
use App\Domain\System\Telegram\Commands\StatementCommand;
use App\Domain\System\Telegram\Commands\TodayCommand;
use App\Domain\System\Telegram\Commands\VisitsCommand;
use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Routes a Telegram command to its handler (TGM-004, P5 step S1).
 *
 * The bus owns the three rules every command shares: it must be a known command, the chat must belong to a
 * linked active staff member, and that member's role must allow it. A command that throws is answered with one
 * honest line and reported — the webhook itself always returns 200, because Meta retries a failing URL forever.
 *
 * /help is generated from the registry, so it can never go stale; the commands the webhook controller answers
 * before the bus runs (/start, /link, /idea, /issue) are listed here too.
 */
final class TelegramCommandBus
{
    public const OWNER_ONLY_MESSAGE = 'Telegram commands are available only to the active owner. This chat is not linked to an authorized owner account.';

    /** @var list<class-string<TelegramCommand>> */
    private const COMMANDS = [
        TodayCommand::class,
        LeadsCommand::class,
        LeadCommand::class,
        FindCommand::class,
        ApprovalsCommand::class,
        ApproveCommand::class,
        AssignCommand::class,
        NoteCommand::class,
        QuotesCommand::class,
        QuoteCommand::class,
        DueCommand::class,
        CollectCommand::class,
        StatementCommand::class,
        VisitsCommand::class,
        ReportCommand::class,
        HealthCommand::class,
        MuteCommand::class,
    ];

    /** @var array<string, TelegramCommand> name => command */
    private array $commands = [];

    public function __construct(Container $container)
    {
        foreach (self::COMMANDS as $command) {
            $instance = $container->make($command);
            $this->commands[$instance->name()] = $instance;
        }

        ksort($this->commands, SORT_NATURAL);
    }

    public function knows(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * Run a command for the staff member linked to this chat (null when the chat is not linked).
     */
    public function dispatch(?User $user, string $name, string $argument): string
    {
        if ($user !== null && ! $user->canUseTelegram()) {
            return self::OWNER_ONLY_MESSAGE;
        }

        $command = $this->commands[$name] ?? null;

        if ($command === null) {
            return 'Unknown command. Send /help to see what the bot can do.';
        }

        if ($user === null) {
            return 'This chat is not linked to Woodex OS yet. Open your profile in the admin and tap "Link Telegram".';
        }

        if ($command->permission() !== null && ! $user->can($command->permission())) {
            return 'Your role does not include this command. Ask an admin if you need it.';
        }

        try {
            return $command->handle($user, $argument);
        } catch (Throwable $exception) {
            report($exception);

            return 'Something went wrong running this command. The team has been notified — try again in a moment.';
        }
    }

    /**
     * The bot's whole catalogue, generated so it can never lie.
     */
    public function help(): string
    {
        $lines = ['Woodex OS bot — run the studio from here:', ''];

        foreach ($this->commands as $command) {
            $lines[] = $command->usage().': '.$command->description();
        }

        $lines[] = '/idea <text>: suggest an improvement';
        $lines[] = '/issue <text>: report a problem';
        $lines[] = '/start CODE: link this chat to your account';
        $lines[] = '/help: this list';

        return implode("\n", $lines);
    }
}
