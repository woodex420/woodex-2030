<?php

declare(strict_types=1);

namespace App\Domain\Website\Models;

use Database\Factories\Website\NavigationItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An editable menu item (header, footer or the service menu), so menus stop being code.
 *
 * @property int $id
 * @property string $menu
 * @property string $label
 * @property string $url
 * @property ?int $parent_id
 * @property int $position
 * @property string $target
 * @property bool $is_mega
 */
class NavigationItem extends Model
{
    /** @use HasFactory<NavigationItemFactory> */
    use HasFactory;

    protected static function newFactory(): NavigationItemFactory
    {
        return NavigationItemFactory::new();
    }

    public const string HEADER = 'header';

    public const string FOOTER = 'footer';

    protected $fillable = ['menu', 'label', 'url', 'parent_id', 'position', 'target', 'is_mega'];

    protected function casts(): array
    {
        return ['is_mega' => 'boolean'];
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
