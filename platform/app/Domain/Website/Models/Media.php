<?php

declare(strict_types=1);

namespace App\Domain\Website\Models;

use App\Models\User;
use Database\Factories\Website\MediaFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image in the media library (docs/09 §2.1). Alt text is part of the record: the editor refuses to publish a
 * page whose images have none.
 *
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property string $alt
 * @property ?string $title
 * @property ?int $width
 * @property ?int $height
 * @property ?string $folder
 */
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    protected static function newFactory(): MediaFactory
    {
        return MediaFactory::new();
    }

    /** Spatie's media library already owns the `media` table (admin uploads). */
    protected $table = 'website_media';

    protected $fillable = ['disk', 'path', 'alt', 'title', 'width', 'height', 'folder', 'uploaded_by'];

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The public URL of this image. Served by the app (see Site\WebsiteMediaController) and versioned by the
     * time it last changed, so a browser can cache it forever and still see an edit at once.
     */
    public function url(): string
    {
        // A theme file (`disk` = site) is served by the web server from public/: the row points at the file the
        // pages already load, so there is nothing to stream through the app.
        if ($this->disk === 'site') {
            return '/'.ltrim($this->path, '/');
        }

        return route('website.media.file', [
            'media' => $this->id,
            'name' => basename($this->path),
            'v' => $this->updated_at->timestamp,
        ]);
    }

    /**
     * The pages that show this image: the ones with it in a section, and the ones that use it as their share
     * image. Editing alt text changes what those published pages say, so their cached copies must go.
     *
     * @return Collection<int, Page>
     */
    public function pagesUsing(): Collection
    {
        $candidates = PageBlock::query()
            ->where('data', 'like', '%'.$this->id.'%')
            ->get(['id', 'page_id', 'data']);

        $pageIds = $candidates
            ->filter(fn (PageBlock $block): bool => in_array($this->id, self::idsIn((array) $block->data), true))
            ->pluck('page_id')
            ->all();

        /** @var Collection<int, Page> $pages */
        $pages = Page::query()
            ->whereKey($pageIds)
            ->orWhere('featured_image_id', $this->id)
            ->orWhere('seo->og_image_id', $this->id)
            ->get();

        return $pages;
    }

    /**
     * Every media id a section points at. The SQL above matches on the raw JSON, which is deliberately loose
     * (the id 3 also occurs in 13 and 30), so each candidate is checked properly here.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private static function idsIn(array $data): array
    {
        $ids = [];

        if (isset($data['image_id'])) {
            $ids[] = (int) $data['image_id'];
        }

        foreach (['images', 'logos'] as $key) {
            foreach ((array) ($data[$key] ?? []) as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $ids[] = (int) $item['id'];
                }
            }
        }

        return $ids;
    }
}
