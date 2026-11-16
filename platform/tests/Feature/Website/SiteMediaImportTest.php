<?php

declare(strict_types=1);

use App\Domain\Website\Models\Media;
use Illuminate\Support\Facades\File;

/*
| The theme's own images (`/assets/img/...`) were invisible to the media library: 33 of the 95 images the pages
| show had no row, so the Media screen could not find or edit them. The import points rows at those files.
*/
it('adds the theme images the pages show to the library, once', function () {
    $site = sys_get_temp_dir().'/woodex-site-media-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($site.'/assets/img/clients');
    File::ensureDirectoryExists($site.'/about');

    File::put($site.'/index.html', '<html><body>'
        .'<img src="/assets/img/clients/pso.webp" alt="PSO" width="320" height="160">'
        .'<img src="/assets/img/img-abc.webp" alt="A photograph from the library">'
        .'</body></html>');
    File::put($site.'/about/index.html', '<html><body>'
        .'<img src="/assets/img/svc-1-kanal-house-design-480.webp" alt="1 Kanal House Design by Woodex Interior, Lahore">'
        .'</body></html>');

    File::put($site.'/assets/img/clients/pso.webp', 'file');
    File::put($site.'/assets/img/img-abc.webp', 'file');
    File::put($site.'/assets/img/svc-1-kanal-house-design-480.webp', 'file');

    $this->app->usePublicPath($site);

    expect($this->artisan('woodex:import-site-media'))->toBe(0);

    // Three images on the pages; the photograph the library already holds is not added twice.
    expect(Media::query()->count())->toBe(3);

    $pso = Media::query()->where('path', 'assets/img/clients/pso.webp')->sole();

    expect($pso->disk)->toBe('site')
        ->and($pso->alt)->toBe('PSO')
        ->and($pso->folder)->toBe('site/clients')
        ->and($pso->url())->toBe('/assets/img/clients/pso.webp');

    // A variant is filed under the name of the base file, and the alt comes from the page.
    $svc = Media::query()->where('path', 'like', '%svc-1-kanal%')->sole();

    expect($svc->path)->toBe('assets/img/svc-1-kanal-house-design-480.webp')
        ->and($svc->alt)->toBe('1 Kanal House Design by Woodex Interior, Lahore')
        ->and($svc->folder)->toBe('site/img');

    // Running it again changes nothing.
    expect($this->artisan('woodex:import-site-media'))->toBe(0);
    expect(Media::query()->count())->toBe(3);

    File::deleteDirectory($site);
});

it('does not add an image the library already holds under that name', function () {
    $site = sys_get_temp_dir().'/woodex-site-media-known-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($site.'/assets/img');
    File::put($site.'/index.html', '<html><body><img src="/assets/img/img-abc.webp" alt="Known"></body></html>');
    File::put($site.'/assets/img/img-abc.webp', 'file');
    $this->app->usePublicPath($site);

    Media::factory()->create(['path' => 'website/imported/2026/10/img-abc-960.webp', 'alt' => 'Known']);

    expect($this->artisan('woodex:import-site-media'))->toBe(0);

    expect(Media::query()->count())->toBe(1);

    File::deleteDirectory($site);
});
