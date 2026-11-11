<?php

declare(strict_types=1);

namespace App\Domain\Website\Models;

use App\Domain\Website\Contracts\LibraryEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member of the studio's team (V2-3 F7, docs/14 §5a): a name, a role, a short bio and a photo.
 * Managed once under Website → Team; a page's Team section picks members and shows them in the
 * studio's order — the same contract the shared content library uses.
 *
 * @property int $id
 * @property string $name
 * @property string $role
 * @property string|null $bio
 * @property int|null $media_id
 * @property int $position
 */
class TeamMember extends Model implements LibraryEntry
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'media_id' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Media, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function entryId(): int
    {
        return (int) $this->id;
    }

    public function label(): string
    {
        return $this->name;
    }

    /**
     * The member as the Team section renders it — the same row shape a manual entry on the page
     * uses, photo first like the gallery's rows.
     *
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return ['id' => $this->media_id, 'name' => $this->name, 'role' => $this->role, 'bio' => (string) $this->bio];
    }

    /**
     * @param  Builder<TeamMember>  $query
     * @return Builder<TeamMember>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position');
    }
}
