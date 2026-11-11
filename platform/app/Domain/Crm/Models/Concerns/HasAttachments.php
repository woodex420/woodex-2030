<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models\Concerns;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Files on CRM records (GLB-006): photos, PDFs, drawings (DWG/DXF), spreadsheets and documents up to 20 MB,
 * on the private disk, with a WebP thumbnail for images (queued, so uploads stay fast).
 */
trait HasAttachments
{
    use InteractsWithMedia;

    /** @var list<string> */
    public const ATTACHMENT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf', 'dwg', 'dxf', 'xlsx', 'xls', 'csv', 'docx', 'doc', 'pptx'];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('files')->useDisk((string) config('media-library.disk_name', 'local'));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->nonOptimized()
            ->performOnCollections('files')
            ->fit(Fit::Contain, 480, 480)
            ->format('webp');
    }
}
