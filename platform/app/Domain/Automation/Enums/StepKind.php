<?php

declare(strict_types=1);

namespace App\Domain\Automation\Enums;

use App\Support\Concerns\EnumHelpers;

/**
 * What one step of a sequence does (AUT-001). Waits are steps too, so the timeline reads
 * "waited three days, then wrote" instead of hiding the delay in a cron.
 */
enum StepKind: string
{
    use EnumHelpers;

    case Wait = 'wait';
    case SendTemplate = 'send_template';
    case CreateTask = 'create_task';
    case ChangeStage = 'change_stage';

    public function label(): string
    {
        return match ($this) {
            self::Wait => 'Wait',
            self::SendTemplate => 'Send WhatsApp template',
            self::CreateTask => 'Create a task',
            self::ChangeStage => 'Move the pipeline stage',
        };
    }
}
