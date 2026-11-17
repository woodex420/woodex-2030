<?php

use Spatie\MediaLibrary\Conversions\ImageGenerators\Image;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Webp;

/*
 * Only the keys Woodex changes; spatie/laravel-medialibrary merges its defaults for the rest.
 */
return [
    // CRM attachments are private: they are served only through the authorised admin route (GLB-006).
    'disk_name' => env('MEDIA_DISK', 'local'),

    // 20 MB per file (drawings and PDFs).
    'max_file_size' => 1024 * 1024 * 20,

    // Thumbnails for images only. PDF/video previews would need Ghostscript/FFmpeg, absent on shared hosting.
    'image_generators' => [
        Image::class,
        Webp::class,
    ],
];
