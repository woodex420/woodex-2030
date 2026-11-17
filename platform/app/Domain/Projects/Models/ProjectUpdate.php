<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use App\Domain\Crm\Models\Concerns\HasAttachments;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One post in the site diary (PRJ-004): what happened, the photographs of it, and whether the client may see it.
 *
 * Photographs live in their own collection so that "the pictures of this update" is one call, and so that
 * nothing but an image can ever be attached to something the client will be shown.
 */
class ProjectUpdate extends Model implements HasMedia
{
    use HasAttachments;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'client_visible' => 'boolean',
            'shared_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<ProjectMilestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos')
            ->useDisk((string) config('media-library.disk_name', 'local'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->nonOptimized()
            ->performOnCollections('photos')
            ->fit(Fit::Contain, 480, 480)
            ->format('webp');
    }
}
