<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Website;

use App\Domain\Website\Builder\PageImageAlt;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Services\Menus;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Livewire\WithPagination;

/**
 * Images of the public website (docs/09 §2.1). Alt text is required: it is what a screen reader reads aloud and
 * the only description of a photograph that search engines and AI assistants ever see (D-66).
 */
#[Layout('layouts::admin')]
#[Title('Media')]
class MediaLibrary extends Component
{
    use InteractsWithAdmin;
    use WithFileUploads;
    use WithPagination;

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public string $search = '';

    public bool $onlyMissingAlt = false;

    /** @var array<int, string> */
    public array $alt = [];

    /** @var array<int, string> */
    public array $titles = [];

    public function mount(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
    }

    public function save(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);

        $this->validate(['uploads.*' => ['file', 'mimes:jpg,jpeg,png,webp,avif', 'max:5120']], [
            'uploads.*.mimes' => 'Only JPG, PNG, WebP or AVIF images can be uploaded.',
            'uploads.*.max' => 'Each image must be smaller than 5 MB.',
        ]);

        $saved = 0;

        foreach ($this->uploads ?: [] as $upload) {
            $name = pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME);
            $path = 'website/'.now()->format('Y/m').'/'.Str::slug($name).'-'.Str::lower(Str::random(6)).'.'.$upload->extension();

            Storage::disk('public')->put($path, file_get_contents($upload->getRealPath()));
            [$width, $height] = @getimagesize($upload->getRealPath()) ?: [null, null];

            Media::query()->create([
                'disk' => 'public',
                'path' => $path,
                'alt' => Str::headline($name),
                'title' => Str::headline($name),
                'width' => $width,
                'height' => $height,
                'folder' => 'website/'.now()->format('Y/m'),
                'uploaded_by' => $this->user()->id,
            ]);

            $saved++;
        }

        $this->uploads = [];
        $this->resetPage();
        $this->toast($saved === 1 ? 'One image added. Give it a real description in the Alt text column.' : $saved.' images added. Give each one a real description.');
    }

    public function updateMeta(int $mediaId): void
    {
        abort_unless($this->user()->can('website.edit'), 403);

        $media = Media::query()->findOrFail($mediaId);
        $alt = trim($this->alt[$mediaId] ?? $media->alt);
        $title = trim($this->titles[$mediaId] ?? (string) $media->title);

        $media->fill(['alt' => $alt, 'title' => $title])->save();

        $stem = PageImageAlt::stem($media->path);

        // One photograph, one alt: the rows for its responsive variants (`-480`, `-960`) carry the same wording.
        Media::query()->get(['id', 'path', 'alt'])
            ->filter(fn (Media $other): bool => PageImageAlt::stem($other->path) === $stem)
            ->each(fn (Media $other): bool => $other->forceFill(['alt' => $alt])->save());

        // The pages are files, so the wording has to be written into them — a row change alone would show nothing.
        $written = PageImageAlt::write($stem, $alt);

        $this->toast($written['pages'] === 0
            ? 'Saved. No page shows this image yet, so the site itself has not changed.'
            : 'Saved. The alt text now appears on '.$written['pages'].' '.Str::plural('page', $written['pages']).'.');
    }

    public function delete(int $mediaId): void
    {
        abort_unless($this->user()->can('website.edit'), 403);

        $media = Media::query()->findOrFail($mediaId);
        $pages = PageImageAlt::pagesUsing(PageImageAlt::stem($media->path));

        if ($pages !== []) {
            $this->toast(
                'This image is used on '.count($pages).' '.Str::plural('page', count($pages)).'. Remove it from the page first.',
                'error',
            );

            return;
        }

        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
        Menus::flush();

        $this->toast('Image deleted.');
    }

    public function render(): View
    {
        $images = Media::query()
            ->when($this->search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('alt', 'like', '%'.$this->search.'%')
                ->orWhere('title', 'like', '%'.$this->search.'%')
                ->orWhere('path', 'like', '%'.$this->search.'%')))
            ->when($this->onlyMissingAlt, fn ($query) => $query->where('alt', ''))
            ->latest('id')
            ->paginate(24);

        foreach ($images as $image) {
            $this->alt[$image->id] ??= $image->alt;
            $this->titles[$image->id] ??= (string) $image->title;
        }

        return view('livewire.admin.website.media-library', [
            'images' => $images,
            'missingAlt' => Media::query()->where('alt', '')->count(),
            'total' => Media::query()->count(),
        ]);
    }
}
