<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

use App\Support\Concerns\EnumHelpers;

enum TemplateKind: string
{
    use EnumHelpers;

    case Execution = 'execution';
    case Design = 'design';
    case Scope = 'scope';
}
