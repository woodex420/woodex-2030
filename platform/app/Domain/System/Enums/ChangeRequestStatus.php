<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

use App\Support\Concerns\EnumHelpers;

enum ChangeRequestStatus: string
{
    use EnumHelpers;

    case New = 'new';
    case Accepted = 'accepted';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Rejected = 'rejected';
}
