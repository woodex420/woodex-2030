<?php

declare(strict_types=1);

namespace App\Domain\System\Import\OldSystem;

/** What the admin knows about a row of the old system. */
enum State: string
{
    /** Never brought over. */
    case New = 'new';

    /** Brought over, and the old row is as it was then. */
    case Same = 'same';

    /** Brought over, and the old row has changed since. */
    case Changed = 'changed';
}
