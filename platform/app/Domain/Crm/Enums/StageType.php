<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

use App\Support\Concerns\EnumHelpers;

enum StageType: string
{
    use EnumHelpers;

    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';
}
