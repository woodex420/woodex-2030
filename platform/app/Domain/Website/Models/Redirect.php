<?php

declare(strict_types=1);

namespace App\Domain\Website\Models;

use Database\Factories\Website\RedirectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One 301 (or 302) from an old URL of the previous website (docs/09 §3). Hits are counted so we can see which
 * redirected URLs still receive visitors — those become real pages again.
 *
 * @property int $id
 * @property string $from_path
 * @property string $to_path
 * @property int $code
 * @property int $hits
 * @property ?string $note
 */
class Redirect extends Model
{
    /** @use HasFactory<RedirectFactory> */
    use HasFactory;

    protected static function newFactory(): RedirectFactory
    {
        return RedirectFactory::new();
    }

    protected $fillable = ['from_path', 'to_path', 'code', 'hits', 'note'];

    public static function normalize(string $path): string
    {
        return Page::normalizeSlug($path);
    }
}
