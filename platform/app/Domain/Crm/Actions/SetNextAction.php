<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Enums\NextActionType;
use App\Domain\Crm\Models\Lead;
use Carbon\CarbonInterface;

/**
 * Every open lead has a next action with a due time (CRM-006).
 */
final class SetNextAction
{
    public function handle(Lead $lead, NextActionType $type, CarbonInterface $at): Lead
    {
        $lead->forceFill([
            'next_action_type' => $type,
            'next_action_at' => $at,
        ])->save();

        return $lead;
    }
}
