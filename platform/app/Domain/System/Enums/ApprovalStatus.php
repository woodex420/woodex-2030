<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

use App\Support\Concerns\EnumHelpers;

enum ApprovalStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
