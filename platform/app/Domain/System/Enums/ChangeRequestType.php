<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

use App\Support\Concerns\EnumHelpers;

enum ChangeRequestType: string
{
    use EnumHelpers;

    case Idea = 'idea';
    case Issue = 'issue';
    case Bug = 'bug';
    case Content = 'content';
}
