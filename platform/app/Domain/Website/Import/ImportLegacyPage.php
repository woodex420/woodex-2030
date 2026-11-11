<?php

declare(strict_types=1);

namespace App\Domain\Website\Import;

use App\Domain\System\Models\LegacyRef;
use App\Domain\Website\Actions\SavePage;
use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Page;
use App\Models\User;

/**
 * Turns one page of the previous website into a page in the CMS (docs/09 §13).
 *
 * The page is stored as a draft first: nothing reaches the live site until a person has read it (D-70). The import
 * is idempotent through `legacy_refs` — a second run updates pages whose file changed and leaves the rest alone,
 * so the owner's edits are never overwritten by a re-import.
 */
final class ImportLegacyPage
{
    private const TABLE = 'website-page';

    public function __construct(
        private readonly SavePage $save,
        private readonly ImportLegacyMedia $media,
    ) {}

    /**
     * @param  string  $root  the folder that holds the old site (the one with index.html in it)
     * @param  string  $source  the HTML file, relative to that folder
     * @return array{slug: string, status: string, blocks: int, images: int, issues: list<string>}
     */
    public function __invoke(
        string $root,
        string $source,
        string $slug,
        PageType $type,
        User $user,
        bool $publish = false,
        bool $force = false,
    ): array {
        $file = rtrim($root, '/').'/'.ltrim($source, '/');
        $result = ['slug' => $slug, 'status' => 'missing', 'blocks' => 0, 'images' => 0, 'issues' => []];

        if (! is_file($file)) {
            $result['issues'][] = 'the file '.$source.' is not there';

            return $result;
        }

        $checksum = (string) hash_file('sha256', $file);
        $ref = $this->ref($slug);

        if ($ref !== null && $ref['checksum'] === $checksum && ! $force) {
            $result['status'] = 'unchanged';

            return $result;
        }

        $html = new LegacyHtml((string) file_get_contents($file));
        $alts = [];

        // Images the old site did not describe are named after the page they stand on.
        $context = (string) $html->title();

        foreach ($html->images() as $image) {
            $alts[$image['src']] = $image['alt'];
        }

        $blocks = [];
        $images = 0;

        foreach ($html->blocks() as $block) {
            $data = $block['data'];

            if (isset($data['image']) && is_string($data['image'])) {
                $id = ($this->media)($root, $data['image'], $alts[$data['image']] ?? '', $context);
                $images += $id === null ? 0 : 1;
                $data['image_id'] = $id;
                unset($data['image']);
            }

            if (isset($data['images']) && is_array($data['images'])) {
                $data['images'] = array_values(array_filter(array_map(
                    function (mixed $image) use ($root, &$images, $context): ?array {
                        if (! is_array($image) || ! isset($image['src']) || ! is_string($image['src'])) {
                            return null;
                        }

                        $id = ($this->media)($root, $image['src'], (string) ($image['alt'] ?? ''), $context);

                        if ($id === null) {
                            return null;
                        }

                        $images++;

                        return ['id' => $id, 'caption' => (string) ($image['caption'] ?? ''), 'alt' => (string) ($image['alt'] ?? '')];
                    },
                    $data['images'],
                )));
            }

            $blocks[] = ['type' => $block['type']->value, 'data' => $data, 'visible' => true];
        }

        $seo = [
            'title' => $html->title(),
            'description' => $html->metaDescription(),
            'robots' => 'index,follow',
            'focus_keyword' => null,
            'og_image_id' => null,
            'last_reviewed' => now()->toDateString(),
        ];

        $page = $ref !== null
            ? Page::query()->find($ref['model_id'])
            : Page::query()->firstWhere('slug', $slug);

        if ($page === null) {
            $page = new Page(['slug' => Page::normalizeSlug($slug)]);
            $page->status = PageStatus::Draft;
        }

        $result['status'] = $page->exists ? 'updated' : 'created';

        $page = ($this->save)(
            $page,
            [
                'type' => $type,
                'slug' => $slug,
                'title' => $this->pageTitle($html->title()),
                'excerpt' => $this->excerpt($html, $blocks),
                'seo' => $seo,
                'schema_type' => null,
                'sort' => 0,
            ],
            $blocks,
            $publish,
            $user,
        );

        LegacyRef::query()->updateOrCreate(
            ['legacy_table' => self::TABLE, 'legacy_id' => $slug],
            [
                'model_type' => Page::class,
                'model_id' => $page->id,
                'checksum' => $checksum,
                'imported_at' => now(),
            ],
        );

        $result['blocks'] = count($blocks);
        $result['images'] = $images;

        if ($html->metaDescription() === null) {
            $result['issues'][] = 'no meta description on the old page';
        }

        return $result;
    }

    /**
     * @return array{model_id: int, checksum: ?string}|null
     */
    private function ref(string $slug): ?array
    {
        $ref = LegacyRef::query()
            ->where('legacy_table', self::TABLE)
            ->where('legacy_id', $slug)
            ->first();

        if ($ref === null) {
            return null;
        }

        return ['model_id' => (int) $ref->model_id, 'checksum' => $ref->checksum !== null ? (string) $ref->checksum : null];
    }

    /**
     * The title shown in the admin: the old `<title>` without the brand at the end
     * ("Interior Design Lahore | Rooms, Kitchens, Offices | Woodex" -> "Interior Design Lahore | Rooms, Kitchens, Offices").
     */
    private function pageTitle(string $title): string
    {
        $clean = (string) preg_replace('/\s*[|–—-]\s*Woodex.*$/i', '', $title);

        return trim($clean) !== '' ? trim($clean) : trim($title);
    }

    /**
     * The summary: the line under the H1 where there is one, otherwise the old meta description.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function excerpt(LegacyHtml $html, array $blocks): string
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === BlockType::Hero->value) {
                $text = trim((string) ($block['data']['text'] ?? ''));

                if ($text !== '') {
                    return mb_substr($text, 0, 240);
                }
            }
        }

        return mb_substr((string) $html->metaDescription(), 0, 240);
    }
}
