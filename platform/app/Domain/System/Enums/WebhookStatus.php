<?php

declare(strict_types=1);

namespace App\Domain\System\Enums;

use App\Support\Concerns\EnumHelpers;

enum WebhookStatus: string
{
    use EnumHelpers;

    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
