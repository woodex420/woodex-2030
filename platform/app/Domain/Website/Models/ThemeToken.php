<?php

declare(strict_types=1);

namespace App\Domain\Website\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One design token of the public site (V2-1, D-116): v1's own palette as data. Printed by ThemeCss as
 * CSS custom properties; the Theme Builder edits them. Values are the site's look — validate before save.
 */
class ThemeToken extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }
}
