<?php

declare(strict_types=1);

namespace App\Domain\Website\Builder;

use RuntimeException;

/**
 * The page changed after the builder loaded it (someone else saved it, or a restore ran). Nothing is
 * written; the builder asks the editor to confirm an overwrite, exactly like the mtime check in v2.1.
 */
final class StalePageException extends RuntimeException
{
    public static function since(string $title): self
    {
        return new self('“'.$title.'” was changed elsewhere since you opened it. Reload, or save again to overwrite.');
    }
}
