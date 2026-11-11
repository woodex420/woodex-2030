<?php

declare(strict_types=1);

namespace App\Domain\Automation\Enums;

use App\Support\Concerns\EnumHelpers;

/** One step's outcome (AUT-005). `skipped` is not an error: no conversation, no approved template, no consent. */
enum RunStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Running => 'Running',
            self::Done => 'Done',
            self::Skipped => 'Skipped',
            self::Failed => 'Failed',
        };
    }
}
