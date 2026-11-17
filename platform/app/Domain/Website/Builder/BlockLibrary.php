<?php

declare(strict_types=1);

namespace App\Domain\Website\Builder;

use App\Domain\Website\Actions\SaveHtmlFile;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The owner's own sections, saved from the builder and put back (docs/audit/21 §5.2).
 *
 * The builder has shipped the whole screen for this — the "My sections" panel, the 💾 dialog with a category,
 * tags and a **Global section** switch, drag-to-insert — against a server that answered `blocks_list` with an
 * empty list and refused every write. This is the server.
 *
 * The library is a file, not a table, for the same reason the pages are: the website is files, and a library
 * of sections belongs beside them. `storage/app/private/section-library.json` holds the master copy; a page
 * that uses a global section carries a copy marked `data-wx-global="<id>"`, and `blocks_sync` replaces that
 * copy on every page — the one thing a copy in a file cannot do on its own.
 */
final class BlockLibrary
{
    /** A library bigger than this is a mistake, not a habit; importing is capped per call. */
    public const int MAX_BLOCKS = 500;

    public const int MAX_IMPORT = 200;

    /** One section, not a page: the builder's own limit is 3 MB for a whole document. */
    public const int MAX_HTML = 400_000;

    public const int MAX_NAME = 60;

    public function __construct(
        private readonly SaveHtmlFile $save,
        private readonly ?User $user = null,
    ) {}

    /**
     * Every saved section, in the order they were saved — so the newest is last, which is where the builder
     * looks for the section it has just created.
     *
     * @return list<array{id: int, name: string, kind: string, cat: string, tags: list<string>, global: bool, html: string, created_at: string, updated_at: string}>
     */
    public function all(): array
    {
        $blocks = $this->read()['blocks'];
        usort($blocks, static fn (array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));

        return array_map(self::shape(...), $blocks);
    }

    /**
     * Create a section, or update one that is already there.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function save(array $in): array
    {
        $rawId = $in['id'] ?? null;
        $id = is_numeric($rawId) && (int) $rawId > 0 ? (int) $rawId : null;
        $name = trim((string) ($in['name'] ?? ''));
        $html = trim((string) ($in['html'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Give the section a name so you can find it again.']);
        }

        if (mb_strlen($name) > self::MAX_NAME) {
            throw ValidationException::withMessages(['name' => 'A section name is at most '.self::MAX_NAME.' characters.']);
        }

        if ($html === '') {
            throw ValidationException::withMessages(['html' => 'There is nothing to save — the section is empty.']);
        }

        if (strlen($html) > self::MAX_HTML) {
            throw ValidationException::withMessages(['html' => 'That section is larger than 400 KB — nothing was saved.']);
        }

        $unsafe = self::unsafeReason($html);

        if ($unsafe !== null) {
            throw ValidationException::withMessages(['html' => 'A saved section cannot carry '.$unsafe
                .'. Put code in the page itself, not in the library.']);
        }

        if (! self::singleRoot($html)) {
            throw ValidationException::withMessages(['html' => 'A saved section has to be one element (one <section>, <div> or other block). Select one section, not several.']);
        }

        $data = $this->read();
        $index = null;

        foreach ($data['blocks'] as $position => $block) {
            if ($id !== null && (int) $block['id'] === $id) {
                $index = $position;

                break;
            }
        }

        if ($id !== null && $index === null) {
            throw ValidationException::withMessages(['id' => 'That section is not in the library any more — save it as a new one.']);
        }

        if ($index === null && count($data['blocks']) >= self::MAX_BLOCKS) {
            throw ValidationException::withMessages(['blocks' => 'The library holds '.self::MAX_BLOCKS.' sections. Delete one before saving another.']);
        }

        $kind = (string) ($in['kind'] ?? 'section');

        $record = [
            'id' => $index === null ? $data['next'] : $id,
            'name' => $name,
            'kind' => in_array($kind, ['section', 'element'], true) ? $kind : 'section',
            'cat' => mb_substr(trim((string) ($in['cat'] ?? '')) ?: 'Custom', 0, 40),
            'tags' => self::tags($in['tags'] ?? []),
            'global' => filter_var($in['global'] ?? false, FILTER_VALIDATE_BOOL),
            'html' => $html,
            'created_at' => $index === null ? now()->toIso8601String() : (string) $data['blocks'][$index]['created_at'],
            'updated_at' => now()->toIso8601String(),
        ];

        if ($index === null) {
            $data['blocks'][] = $record;
            $data['next'] = ((int) $data['next']) + 1;
        } else {
            $data['blocks'][$index] = $record;
        }

        $this->write($data);

        return ['ok' => true, 'block' => self::shape($record), 'blocks' => $this->all()];
    }

    /**
     * Remove a section. A global section that pages still use is refused unless the caller insists — the
     * copies stay on those pages as ordinary sections, they simply stop being linked.
     *
     * @return array<string, mixed>
     */
    public function delete(int $id, bool $force): array
    {
        $data = $this->read();
        $index = null;

        foreach ($data['blocks'] as $position => $block) {
            if ((int) $block['id'] === $id) {
                $index = $position;

                break;
            }
        }

        if ($index === null) {
            throw ValidationException::withMessages(['id' => 'That section is not in the library any more.']);
        }

        $used = $this->usageOf($id);

        if ($used !== [] && ! $force) {
            return [
                'ok' => false,
                'used' => true,
                'pages' => $used,
                'error' => sprintf(
                    '“%s” is on %d page(s): %s. Deleting it leaves those copies on the pages, unsynced.',
                    (string) $data['blocks'][$index]['name'],
                    count($used),
                    implode(', ', array_slice(array_map(BuilderFile::urlOf(...), $used), 0, 3)).(count($used) > 3 ? '…' : ''),
                ),
            ];
        }

        array_splice($data['blocks'], $index, 1);
        $this->write($data);

        return ['ok' => true, 'deleted' => $id, 'blocks' => $this->all()];
    }

    /**
     * Which page files carry each global section.
     *
     * @return array<int, list<string>> block id → page paths
     */
    public function usage(): array
    {
        $ids = array_values(array_map(
            static fn (array $block): int => (int) $block['id'],
            array_filter($this->read()['blocks'], static fn (array $block): bool => (bool) $block['global']),
        ));

        if ($ids === []) {
            return [];
        }

        $usage = [];

        foreach (BuilderFile::pages() as $page) {
            $html = BuilderFile::read($page['path']);

            foreach ($ids as $id) {
                if (GlobalSection::contains($html, $id)) {
                    $usage[$id][] = $page['path'];
                }
            }
        }

        return $usage;
    }

    /** @return list<string> */
    public function usageOf(int $id): array
    {
        return $this->usage()[$id] ?? [];
    }

    /**
     * Put the library's version of a global section onto every page that uses it.
     *
     * Each page is written through `SaveHtmlFile`, so it is snapshotted first and the script guard runs, the
     * same as any other write to a page. A page whose copy is already identical is left alone.
     *
     * @return array{ok: bool, changed: int, pages: list<string>}
     */
    public function sync(int $id): array
    {
        $block = null;

        foreach ($this->read()['blocks'] as $candidate) {
            if ((int) $candidate['id'] === $id) {
                $block = $candidate;

                break;
            }
        }

        if ($block === null) {
            throw ValidationException::withMessages(['id' => 'That section is not in the library any more.']);
        }

        if (! (bool) $block['global']) {
            throw ValidationException::withMessages(['id' => 'Only a global section is linked to pages. Tick “Global section” and save it first.']);
        }

        $replacement = GlobalSection::attribute((string) $block['html'], $id);
        $changed = [];
        $duplicates = [];

        foreach ($this->usageOf($id) as $path) {
            $before = BuilderFile::read($path);
            $result = GlobalSection::replace($before, $id, $replacement);

            if (GlobalSection::count($result['html'], $id) !== 1) {
                $duplicates[] = BuilderFile::urlOf($path);
            }

            if ($result['count'] === 0 || $result['html'] === $before) {
                continue;
            }

            ($this->save)($path, $result['html'], null, null, true, $this->user);
            $changed[] = $path;
        }

        return ['ok' => true, 'changed' => count($changed), 'pages' => $changed, 'duplicates' => $duplicates];
    }

    /**
     * Add sections from a library file (or the starter pack). Imported HTML is cleaned first: it comes from
     * outside this site, and a section is rendered in the admin as a preview.
     *
     * @param  list<mixed>  $items
     * @return array{ok: bool, added: int, skipped: int, blocks: list<array<string, mixed>>}
     */
    public function import(array $items): array
    {
        if (count($items) > self::MAX_IMPORT) {
            throw ValidationException::withMessages(['blocks' => 'Import at most '.self::MAX_IMPORT.' sections at a time.']);
        }

        $added = 0;
        $skipped = 0;

        foreach ($items as $item) {
            if (! is_array($item) || trim((string) ($item['html'] ?? '')) === '') {
                $skipped++;

                continue;
            }

            $html = self::sanitise((string) $item['html']);

            if ($html === '' || ! self::singleRoot($html)) {
                $skipped++;

                continue;
            }

            try {
                $this->save([
                    'name' => (string) ($item['name'] ?? 'Imported section'),
                    'kind' => (string) ($item['kind'] ?? 'section'),
                    'cat' => (string) ($item['cat'] ?? 'Custom'),
                    'tags' => $item['tags'] ?? [],
                    'global' => (bool) ($item['global'] ?? false),
                    'html' => $html,
                ]);
                $added++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        return ['ok' => true, 'added' => $added, 'skipped' => $skipped, 'blocks' => $this->all()];
    }

    /** Why a section may not be stored, or null when it may. The same tokens the builder's import removes. */
    public static function unsafeReason(string $html): ?string
    {
        if (preg_match('~<script\b~i', $html) === 1) {
            return 'a script';
        }

        if (preg_match('~<[^>]+\son[a-z]+\s*=~i', $html) === 1) {
            return 'an inline event handler (onclick and the like)';
        }

        if (preg_match('~<(object|embed|base|iframe)\b~i', $html) === 1) {
            return 'an embedded frame or object';
        }

        if (preg_match('~(href|src|action|formaction|xlink:href)\s*=\s*["\']?\s*(javascript|vbscript|data:text/html)~i', $html) === 1) {
            return 'a javascript: link';
        }

        return null;
    }

    /** Is the HTML one element (plus whitespace)? A section that is several cannot be linked to a page. */
    public static function singleRoot(string $html): bool
    {
        $loaded = self::fragment($html);

        if ($loaded === null) {
            return false;
        }

        $wrapper = $loaded[1];
        $elements = 0;

        foreach ($wrapper->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elements++;
            } elseif (trim((string) $child->textContent) !== '') {
                // Stray text beside the element ("Save 20%") is not a section.
                return false;
            }
        }

        return $elements === 1;
    }

    /**
     * Clean an imported section: no scripts, embeds, inline handlers or javascript: links.
     *
     * Serialising a *section* through the DOM is safe — it is one self-contained block, not a hand-written
     * page — and it is far more reliable than stripping tags with patterns.
     */
    public static function sanitise(string $html): string
    {
        $loaded = self::fragment($html);

        if ($loaded === null) {
            return '';
        }

        [$doc, $wrapper] = $loaded;
        $xpath = new DOMXPath($doc);

        foreach (iterator_to_array($xpath->query('.//script|.//object|.//embed|.//base|.//iframe', $wrapper) ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (iterator_to_array($xpath->query('.//*', $wrapper) ?: []) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = trim((string) $attribute->nodeValue);

                $dangerousUrl = in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href'], true)
                    && preg_match('~^(javascript|vbscript|data:text/html)~i', $value) === 1;

                if (str_starts_with($name, 'on') || $dangerousUrl) {
                    $node->removeAttribute($attribute->nodeName);
                }
            }
        }

        $out = '';

        foreach ($wrapper->childNodes as $child) {
            $out .= (string) $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * The fragment as a DOM: the document and the wrapper element, so one section can be read without a full
     * page. Both come from the same document — a node from one `DOMDocument` cannot be queried through another.
     *
     * @return array{0: DOMDocument, 1: DOMElement}|null
     */
    private static function fragment(string $html): ?array
    {
        if (trim($html) === '') {
            return null;
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8" ?><div data-wx-section>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return null;
        }

        $wrapper = (new DOMXPath($doc))->query('//*[@data-wx-section]')?->item(0);

        return $wrapper instanceof DOMElement ? [$doc, $wrapper] : null;
    }

    /**
     * @param  list<string>|string  $tags
     * @return list<string>
     */
    private static function tags(array|string $tags): array
    {
        $list = is_array($tags) ? $tags : explode(',', $tags);
        $out = [];

        foreach ($list as $tag) {
            $tag = mb_strtolower(trim((string) $tag));

            if ($tag !== '' && mb_strlen($tag) <= 24 && ! in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }

        return array_slice($out, 0, 8);
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{id: int, name: string, kind: string, cat: string, tags: list<string>, global: bool, html: string, created_at: string, updated_at: string}
     */
    private static function shape(array $block): array
    {
        return [
            'id' => (int) $block['id'],
            'name' => (string) $block['name'],
            'kind' => (string) ($block['kind'] ?? 'section'),
            'cat' => (string) ($block['cat'] ?? 'Custom'),
            'tags' => array_values((array) ($block['tags'] ?? [])),
            'global' => (bool) ($block['global'] ?? false),
            'html' => (string) $block['html'],
            'created_at' => (string) ($block['created_at'] ?? ''),
            'updated_at' => (string) ($block['updated_at'] ?? ''),
        ];
    }

    /** @return array{next: int, blocks: list<array<string, mixed>>} */
    private function read(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return ['next' => 1, 'blocks' => []];
        }

        $data = json_decode((string) file_get_contents($path), true);

        // A library that cannot be read is refused, never replaced by an empty one: the next save would
        // otherwise erase every section the owner saved.
        if (! is_array($data) || ! isset($data['blocks']) || ! is_array($data['blocks'])) {
            throw new RuntimeException('The section library file could not be read. It has not been changed — '
                .'check storage/app/private/section-library.json, or move it aside to start an empty library.');
        }

        $blocks = [];

        foreach ($data['blocks'] as $block) {
            if (is_array($block) && isset($block['id'], $block['name'], $block['html'])) {
                $blocks[] = $block;
            }
        }

        return ['next' => max(1, (int) ($data['next'] ?? 1)), 'blocks' => $blocks];
    }

    /** @param array{next: int, blocks: list<array<string, mixed>>} $data */
    private function write(array $data): void
    {
        $path = $this->path();
        File::ensureDirectoryExists(dirname($path));

        $temporary = $path.'.tmp-'.bin2hex(random_bytes(4));
        $json = (string) json_encode(
            ['version' => 1, 'next' => $data['next'], 'blocks' => array_values($data['blocks'])],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if (file_put_contents($temporary, $json) === false) {
            throw new RuntimeException('The section library could not be written — is storage/ writable?');
        }

        if (! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException('The section library could not be replaced.');
        }

        @chmod($path, 0o644);
    }

    private function path(): string
    {
        return storage_path('app/private/section-library.json');
    }
}
