<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Website;

use App\Domain\Media\Services\MasterMedia;
use App\Domain\Website\Builder\PageImageAlt;
use App\Domain\Website\Import\ImportLegacyMedia;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Services\Menus;
use App\Domain\Website\Services\StorefrontMedia;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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

    #[Locked]
    public ?int $replacementId = null;

    #[Locked]
    public string $replacementPath = '';

    public $replacementUpload;

    public string $reuseId = '';

    public bool $showReplacement = false;

    public bool $publishReplacement = false;

    public bool $confirmReplacement = false;

    public function withdrawReplacement(int $id): void
    {
        abort_unless($this->user()->can('website.edit') && $this->user()->can('website.publish'), 403);
        $media = Media::findOrFail($id);
        $media->forceFill(['storefront_version_id' => null])->save();
        activity('website')->performedOn($media)->causedBy($this->user())->log('React media mapping withdrawn; bundled original restored');
        $this->toast('React replacement withdrawn. Connected storefronts return to the original image on refresh. Library and historical files retained.');
    }

    public function optimizeImage(int $id): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
        $media = Media::findOrFail($id);
        $version = app(MasterMedia::class)->register($media);
        $original = Storage::disk('local')->get(DB::table('master_media_versions')->where('id', $version)->value('path'));
        $optimized = app(ImportLegacyMedia::class)->optimize($original);
        if (strlen($optimized) >= strlen($original)) {
            $this->toast('No smaller output available. Original retained unchanged; encoder may be unavailable.');

            return;
        }
        app(StorefrontMedia::class)->replace($media, $optimized, $media->path, false, $this->user());
        $this->toast('Library optimized: '.number_format(strlen($original) / 1024, 1).' KB → '.number_format(strlen($optimized) / 1024, 1).' KB. Original retained. Use Replace / reuse to publish to React.');
    }

    public function importReactImages(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
        $result = app(StorefrontMedia::class)->import();
        $this->resetPage();
        $this->toast($result['added'].' React images added; '.$result['skipped'].' unsupported/missing files skipped. Existing images were not changed.');
    }

    public function chooseReplacement(int $id): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
        $media = Media::findOrFail($id);
        $this->showReplacement = true;
        $this->replacementId = $id;
        $this->replacementPath = $media->path;
        $this->replacementUpload = null;
        $this->reuseId = '';
        $this->publishReplacement = false;
        $this->confirmReplacement = false;
        $this->resetErrorBag();
    }

    public function replaceImage(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
        $this->validate(['confirmReplacement' => ['accepted']]);
        $media = Media::findOrFail($this->replacementId);
        if ($this->reuseId !== '') {
            $this->validate(['reuseId' => ['integer', 'exists:website_media,id']]);
            $source = Media::findOrFail($this->reuseId);
            $version = app(MasterMedia::class)->register($source);
            $path = DB::table('master_media_versions')->where('id', $version)->value('path');
            $bytes = Storage::disk('local')->get($path);
        } else {
            $this->validate(['replacementUpload' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=6000,max_height=6000']]);
            $bytes = file_get_contents($this->replacementUpload->getRealPath());
        }
        app(StorefrontMedia::class)->replace($media, $bytes, $this->replacementPath, $this->publishReplacement, $this->user());
        $this->showReplacement = false;
        $this->replacementId = null;
        $this->replacementUpload = null;
        $this->toast('Image replaced. Original retained. Published React mappings refresh on connected storefronts; pinned product revisions are unchanged.');
    }

    public string $search = '';

    public string $folder = '';

    public string $uploadFolder = 'website/general';

    public array $folders = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFolder(): void
    {
        $this->resetPage();
    }

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

        $this->validate(['uploadFolder' => ['required', 'string', 'max:80', 'regex:~^website(?:/[a-z0-9-]+)*$~'], 'uploads.*' => ['image', 'mimes:jpg,jpeg,png,webp,avif', 'max:5120', 'dimensions:max_width=6000,max_height=6000']], [
            'uploads.*.mimes' => 'Only JPG, PNG, WebP or AVIF images can be uploaded.',
            'uploads.*.max' => 'Each image must be smaller than 5 MB.',
        ]);

        $saved = 0;

        foreach ($this->uploads ?: [] as $upload) {
            $name = pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME);
            $path = $this->uploadFolder.'/'.Str::slug($name).'-'.Str::lower(Str::random(6)).'.'.$upload->extension();

            Storage::disk('public')->put($path, file_get_contents($upload->getRealPath()));
            [$width, $height] = @getimagesize($upload->getRealPath()) ?: [null, null];

            $media = Media::query()->create([
                'disk' => 'public',
                'path' => $path,
                'alt' => Str::headline($name),
                'title' => Str::headline($name),
                'width' => $width,
                'height' => $height,
                'folder' => $this->uploadFolder,
                'uploaded_by' => $this->user()->id,
            ]);

            app(MasterMedia::class)->register($media);
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

        $this->validate(['folders.'.$mediaId => ['nullable', 'string', 'max:80', 'regex:~^website(?:/[a-z0-9-]+)*$~']]);
        $media->fill(['alt' => $alt, 'title' => $title, 'folder' => $this->folders[$mediaId] ?? $media->folder])->save();

        DB::table('master_media_assets')->where('source_type', 'website')->where('source_id', $media->id)->update(['title' => $media->title ?? '', 'folder' => $media->folder ?? 'website/general', 'updated_at' => now()]);

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
        if ($media->storefront_key) {
            $this->toast('This image is connected to a React storefront key. Replace it instead of deleting it.', 'error');

            return;
        }
        $masterUses = DB::table('master_media_sources as sources')->join('master_media_versions as selected', 'selected.id', '=', 'sources.version_id')->join('master_media_versions as retained', 'retained.asset_id', '=', 'selected.asset_id')->join('master_media_usages as usages', 'usages.version_id', '=', 'retained.id')->where('sources.source_type', 'website')->where('sources.source_id', $media->id)->exists();
        if ($masterUses) {
            $this->toast('This master asset is used by saved product revisions. Retain it for those references.', 'error');

            return;
        }
        $pages = PageImageAlt::pagesUsing(PageImageAlt::stem($media->path));

        if ($pages !== [] || $media->pagesUsing()->isNotEmpty()) {
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
        abort_unless($this->user()->can('website.edit'), 403);
        $images = Media::query()
            ->when($this->folder !== '', fn ($query) => $query->where('folder', $this->folder))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('alt', 'like', '%'.$this->search.'%')
                ->orWhere('title', 'like', '%'.$this->search.'%')
                ->orWhere('path', 'like', '%'.$this->search.'%')))
            ->when($this->onlyMissingAlt, fn ($query) => $query->where('alt', ''))
            ->latest('id')
            ->paginate(24);

        foreach ($images as $image) {
            $this->folders[$image->id] ??= $image->folder ?? 'website/general';
            $this->alt[$image->id] ??= $image->alt;
            $this->titles[$image->id] ??= (string) $image->title;
        }

        return view('livewire.admin.website.media-library', [
            'images' => $images,
            'sourceReferences' => $this->showReplacement && $this->replacementId ? app(StorefrontMedia::class)->sourceReferences(Media::find($this->replacementId)) : [],
            'replacementMedia' => $this->showReplacement && $this->replacementId ? Media::find($this->replacementId) : null,
            'reuseImages' => $this->replacementId ? Media::orderBy('title')->limit(500)->get(['id', 'title', 'path']) : collect(),
            'folderOptions' => Media::whereNotNull('folder')->distinct()->orderBy('folder')->pluck('folder'),
            'missingAlt' => Media::query()->where('alt', '')->count(),
            'total' => Media::query()->count(),
        ]);
    }
}
