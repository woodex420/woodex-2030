<?php

declare(strict_types=1);

namespace App\Domain\Website\Import;

use App\Domain\System\Models\LegacyRef;
use App\Domain\Website\Models\Media;
use Illuminate\Support\Facades\Storage;

/**
 * Copies one image of the old site into the media library (docs/09 §13 step 4).
 *
 * Images are deduplicated by their content: the same photograph appears on many old pages, and importing it once
 * keeps the library (and the disk) small. The old alt text is kept exactly as it was; when the old site gave none,
 * the page and the section it stands in name it instead of its file name, because the editor refuses to publish a
 * page whose images have no description.
 */
final class ImportLegacyMedia
{
    /** No page shows a photograph wider than this. */
    private const MAX_WIDTH = 1600;

    private const QUALITY = 82;

    /** @var array<string, int|null> sha1 => media id, so a file used twice on a page is read from disk once */
    private array $seen = [];

    /**
     * @param  string  $path  relative to the old site root, as it appeared in the HTML (`assets/img/foo.webp`)
     * @param  string  $alt  the alt text the old HTML carried, when it carried one
     * @param  string  $context  what the page is about ("Interior design in Lahore"), used when there is no alt
     */
    public function __invoke(string $root, string $path, string $alt = '', string $context = ''): ?int
    {
        $file = rtrim($root, '/').'/'.ltrim($path, '/');

        if (! is_file($file)) {
            return null;
        }

        // The hash is the content we are going to store, so an image imported from an export is recognised as the
        // same file even after it has been scaled down.
        $hash = hash('sha256', $this->resize((string) file_get_contents($file)));

        if (array_key_exists($hash, $this->seen)) {
            return $this->seen[$hash];
        }

        $existing = LegacyRef::query()
            ->where('legacy_table', self::TABLE)
            ->where('legacy_id', $hash)
            ->value('model_id');

        if ($existing !== null) {
            // A ref can outlive the photograph it points at: the media library was emptied (`woodex:reset-website`)
            // but the bookkeeping was not. Trusting the id then leaves every block pointing at nothing, so the
            // row is checked before the ref is believed, and a dangling one is repaired with the file in hand.
            if (Media::query()->whereKey($existing)->exists()
                && Storage::disk('public')->exists((string) Media::query()->whereKey($existing)->value('path'))) {
                return $this->seen[$hash] = (int) $existing;
            }

            LegacyRef::query()
                ->where('legacy_table', self::TABLE)
                ->where('legacy_id', $hash)
                ->delete();
        }

        $target = 'website/imported/'.date('Y/m').'/'.basename($file);
        $target = $this->uniquePath($target);

        Storage::disk('public')->put($target, $this->resize((string) file_get_contents($file)));
        $contents = (string) Storage::disk('public')->get($target);

        $size = @getimagesizefromstring($contents);
        [$width, $height] = $size === false ? $this->dimensions($file) : [$size[0], $size[1]];

        $media = Media::query()->create([
            'disk' => 'public',
            'path' => $target,
            'alt' => $this->altFor($alt, $context, $file),
            'title' => null,
            'width' => $width,
            'height' => $height,
            'folder' => 'imported',
            'uploaded_by' => null,
        ]);

        LegacyRef::query()->create([
            'legacy_table' => self::TABLE,
            'legacy_id' => $hash,
            'model_type' => Media::class,
            'model_id' => $media->id,
            'checksum' => $hash,
            'imported_at' => now(),
        ]);

        return $this->seen[$hash] = $media->id;
    }

    /**
     * A photograph no wider than a page can show it, re-encoded as WebP when that makes it smaller (docs/09 §2.3).
     *
     * The old site shipped 2240px hero images; 1600px at quality 82 is what the layout actually uses.
     */
    private function resize(string $contents): string
    {
        if (! extension_loaded('gd')) {
            return $contents;
        }

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            return $contents;
        }

        $width = imagesx($source);
        $scaled = $width > self::MAX_WIDTH
            ? imagescale($source, self::MAX_WIDTH, (int) round(imagesy($source) * self::MAX_WIDTH / $width))
            : $source;

        if ($scaled !== false) {
            ob_start();
            imagewebp($scaled, null, self::QUALITY);
            $encoded = (string) ob_get_clean();

            if ($encoded !== '' && strlen($encoded) < strlen($contents)) {
                $contents = $encoded;
            }

            if ($scaled !== $source) {
                imagedestroy($scaled);
            }
        }

        imagedestroy($source);

        return $contents;
    }

    private const TABLE = 'website-image';

    /** Two old folders can hold different photographs with the same file name. */
    private function uniquePath(string $target): string
    {
        if (! Storage::disk('public')->exists($target)) {
            return $target;
        }

        $suffix = 2;

        while (Storage::disk('public')->exists($target)) {
            $candidate = preg_replace('/(\.\w+)$/', '-'.$suffix.'$1', $target);
            $target = is_string($candidate) ? $candidate : $target.'-'.$suffix;
            $suffix++;
        }

        return $target;
    }

    /** @return array{0: int|null, 1: int|null} */
    private function dimensions(string $file): array
    {
        $size = @getimagesize($file);

        if ($size === false) {
            return [null, null];
        }

        return [$size[0], $size[1]];
    }

    /** The old alt text, then what the page is about, then the file name as a last resort. */
    private function altFor(string $alt, string $context, string $file): string
    {
        return match (true) {
            trim($alt) !== '' => trim($alt),
            trim($context) !== '' => trim($context),
            default => $this->altFromName($file),
        };
    }

    /** A last resort for an image with no alt text: a readable sentence from its file name. */
    private function altFromName(string $file): string
    {
        $name = pathinfo($file, PATHINFO_FILENAME);

        return trim((string) preg_replace('/[-_]+/', ' ', $name));
    }
}
