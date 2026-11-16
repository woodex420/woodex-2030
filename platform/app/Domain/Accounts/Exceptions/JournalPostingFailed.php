<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Exceptions;

use RuntimeException;

/**
 * A journal could not be posted: unbalanced, one-sided line, unknown/inactive/group account,
 * missing or closed period, or a duplicate business source. The message is safe to show in the UI.
 */
final class JournalPostingFailed extends RuntimeException
{
    public static function unbalanced(int $debit, int $credit): self
    {
        return new self("The entry does not balance: debits {$debit} paisa against credits {$credit} paisa. Correct the lines before posting.");
    }

    public static function oneSidedLine(int $line): self
    {
        return new self("Line {$line} must carry exactly one side — a debit or a credit, never both and never neither.");
    }

    public static function notPostable(string $label): self
    {
        return new self("{$label} cannot receive postings. Choose an active leaf account, not a group.");
    }

    public static function noOpenPeriod(string $businessDate): self
    {
        return new self("No accounting period covers {$businessDate}. Create the period first, then post.");
    }

    public static function periodClosed(string $name): self
    {
        return new self("Period {$name} is closed. Corrections need a reviewed reversal or a controlled reopening.");
    }

    public static function duplicateSource(string $source): self
    {
        return new self("This business source ({$source}) has already been posted. Repeating the action never posts twice.");
    }
}
