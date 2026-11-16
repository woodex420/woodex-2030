<?php

declare(strict_types=1);

namespace App\Domain\Website\Builder;

use App\Domain\Website\Support\HtmlGuard;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/**
 * Change one piece of text on every page at once (docs/audit/21 §5.1).
 *
 * The website is 142 `index.html` files, and the phone number, email and address are written into each one
 * as ordinary text — 399 occurrences of the phone number alone. The builder edits one page at a time, so a
 * new phone number meant opening every page. This is the site-wide edit it was missing: count first (the dry
 * run the screen shows), then write every page that contains the text, keeping the before-image of each file
 * so the whole run can be put back in one press.
 *
 * The replacement is plain text, not a pattern: `str_replace` semantics, byte for byte, so nobody has to
 * escape anything. It touches every occurrence in the document — the visible text, the `tel:`/`mailto:` links
 * and the structured data — because a phone number that changes in one of those and not the others is worse
 * than one that did not change at all.
 */
final class SiteWideReplace
{
    /** The folders a run can be limited to. Everything else is "the site" and searched when no area is chosen. */
    public const array SECTIONS = [
        'insights' => 'Insights',
        'projects' => 'Project studies',
        'lahore' => 'Lahore areas',
    ];

    /** How many past runs keep their before-images. Older runs are pruned, so the disk cannot fill. */
    private const int KEEP_RUNS = 10;

    public function __construct(private readonly ?User $user = null) {}

    /**
     * The dry run: what would change, and where.
     *
     * @param  list<string>  $sections  empty = the whole site
     * @return array{scanned: int, total: int, pages: list<array{path: string, url: string, count: int, sample: string}>}
     */
    public function preview(string $find, array $sections = [], bool $matchCase = false): array
    {
        $pages = $this->pagesIn($sections);
        $rows = [];
        $total = 0;

        foreach ($pages as $page) {
            $html = BuilderFile::read($page['path']);
            $count = self::count($html, $find, $matchCase);

            if ($count === 0) {
                continue;
            }

            $total += $count;
            $rows[] = [
                'path' => $page['path'],
                'url' => $page['url'],
                'count' => $count,
                'sample' => self::context($html, $find, $matchCase),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['url'], $b['url']));

        return ['scanned' => count($pages), 'total' => $total, 'pages' => $rows];
    }

    /**
     * Write the change to every page that has it.
     *
     * The whole run is planned in memory first: if any page would fail the script guard, nothing is written.
     * A half-applied site-wide edit is the one outcome that must not happen.
     *
     * @param  list<string>  $sections
     * @return array{run: string, files: int, total: int}
     */
    public function apply(string $find, string $replace, array $sections = [], bool $matchCase = false): array
    {
        if (trim($find) === '') {
            throw ValidationException::withMessages(['find' => 'Enter the text to find.']);
        }

        if ($find === $replace) {
            throw ValidationException::withMessages(['replace' => 'The replacement is the same as the search — nothing would change.']);
        }

        /** @var list<array{path: string, old: string, new: string, count: int}> $plan */
        $plan = [];

        foreach ($this->pagesIn($sections) as $page) {
            $html = BuilderFile::read($page['path']);
            $count = self::count($html, $find, $matchCase);

            if ($count === 0) {
                continue;
            }

            $new = $matchCase ? str_replace($find, $replace, $html) : str_ireplace($find, $replace, $html);

            if ($new === $html) {
                continue;
            }

            // The same rule as saving in the builder: an editor may change the words, not add code.
            if (! $this->user?->can('website.raw_html')) {
                HtmlGuard::assertNoNewCode($new, $html);
            }

            $plan[] = ['path' => $page['path'], 'old' => $html, 'new' => $new, 'count' => $count];
        }

        if ($plan === []) {
            throw ValidationException::withMessages(['find' => 'No page contains that text — nothing was changed.']);
        }

        $run = now()->format('Ymd-His').'-'.bin2hex(random_bytes(3));
        $directory = $this->runsRoot().'/'.$run;
        File::ensureDirectoryExists($directory);

        $files = 0;
        $total = 0;

        foreach ($plan as $item) {
            // The before-image first: if the write is interrupted, undo has something to put back.
            $kept = $directory.'/'.$item['path'];
            File::ensureDirectoryExists(dirname($kept));
            File::put($kept, $item['old']);

            BuilderFile::snapshot($item['path'], $this->user, 'find-replace');
            BuilderFile::write($item['path'], $item['new']);

            $files++;
            $total += $item['count'];
        }

        File::put($directory.'/manifest.json', $this->manifest([
            'find' => $find,
            'replace' => $replace,
            'match_case' => $matchCase,
            'sections' => array_values($sections),
            'files' => $files,
            'total' => $total,
            'applied_at' => now()->toIso8601String(),
            'undone_at' => null,
        ]));

        $this->prune();

        return ['run' => $run, 'files' => $files, 'total' => $total];
    }

    /**
     * The runs still on disk, newest first, so the screen can offer an undo.
     *
     * @return list<array{id: string, find: string, replace: string, files: int, total: int, at: string, undone: bool}>
     */
    public function runs(): array
    {
        $rows = [];

        foreach (glob($this->runsRoot().'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $manifest = $this->readManifest($directory);

            if ($manifest === null) {
                continue;
            }

            $rows[] = [
                'id' => basename($directory),
                'find' => (string) $manifest['find'],
                'replace' => (string) $manifest['replace'],
                'files' => (int) $manifest['files'],
                'total' => (int) $manifest['total'],
                'at' => (string) $manifest['applied_at'],
                'undone' => ($manifest['undone_at'] ?? null) !== null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($b['id'], $a['id']));

        return $rows;
    }

    /**
     * Put every page of one run back as it was.
     *
     * @return array{files: int, missing: int}
     */
    public function undo(string $run): array
    {
        $directory = $this->runsRoot().'/'.basename($run);
        $manifest = $this->readManifest($directory);

        if ($manifest === null) {
            throw ValidationException::withMessages(['run' => 'That run is not on disk any more.']);
        }

        if (($manifest['undone_at'] ?? null) !== null) {
            throw ValidationException::withMessages(['run' => 'That run was already undone.']);
        }

        $files = 0;
        $missing = 0;

        foreach (File::allFiles($directory) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));

            if ($relative === 'manifest.json' || ! str_ends_with($relative, '.html')) {
                continue;
            }

            $path = BuilderFile::normalise($relative);

            if (! BuilderFile::exists($path)) {
                $missing++;

                continue;
            }

            BuilderFile::snapshot($path, $this->user, 'find-replace-undo');
            BuilderFile::write($path, (string) file_get_contents($file->getPathname()));
            $files++;
        }

        $manifest['undone_at'] = now()->toIso8601String();
        $manifest['undo_files'] = $files;
        File::put($directory.'/manifest.json', $this->manifest($manifest));

        return ['files' => $files, 'missing' => $missing];
    }

    /** How many times the text appears in one document, with the same case rule the replacement uses. */
    public static function count(string $html, string $find, bool $matchCase = false): int
    {
        if ($find === '' || $html === '') {
            return 0;
        }

        if ($matchCase) {
            return substr_count($html, $find);
        }

        return (int) preg_match_all('~'.preg_quote($find, '~').'~i', $html);
    }

    /**
     * A short look at the first match, so the screen shows what will change rather than only a number.
     *
     * Read from the page's visible text, not the raw document: a window cut out of the HTML lands inside the
     * structured data, and a wall of JSON tells the owner nothing about the sentence being changed. When the
     * text is only in an attribute (a `tel:` link, a meta tag), the raw document is used instead — stripped
     * of tags — so there is still something to look at.
     */
    public static function context(string $html, string $find, bool $matchCase = false, int $width = 60): string
    {
        $withoutCode = preg_replace('~<(script|style)\b.*?</\1>~is', ' ', $html) ?? $html;
        $text = trim(preg_replace('~\s+~', ' ', html_entity_decode(strip_tags($withoutCode), ENT_QUOTES | ENT_HTML5)) ?? '');

        $snippet = self::window($text, $find, $matchCase, $width);

        if ($snippet !== '') {
            return $snippet;
        }

        $raw = trim(preg_replace('~\s+~', ' ', strip_tags($html)) ?? '');

        return self::window($raw, $find, $matchCase, $width);
    }

    /** The match with `$width` characters on either side, marked at the edges when it is cut. */
    private static function window(string $haystack, string $find, bool $matchCase, int $width): string
    {
        $at = $matchCase ? mb_strpos($haystack, $find) : mb_stripos($haystack, $find);

        if ($at === false) {
            return '';
        }

        $start = max(0, $at - $width);
        $snippet = mb_substr($haystack, $start, mb_strlen($find) + $width * 2);

        return ($start > 0 ? '…' : '').$snippet.(($start + mb_strlen($snippet)) < mb_strlen($haystack) ? '…' : '');
    }

    /**
     * The pages a run covers: the whole site, or one of the named areas.
     *
     * @param  list<string>  $sections
     * @return list<array{path: string, url: string, title: string, mtime: int, size: int}>
     */
    private function pagesIn(array $sections): array
    {
        $pages = BuilderFile::pages();

        if ($sections === []) {
            return $pages;
        }

        $wanted = array_values(array_intersect(array_keys(self::SECTIONS), $sections));

        if ($wanted === []) {
            throw ValidationException::withMessages(['sections' => 'Choose at least one area, or untick them all to search the whole site.']);
        }

        return array_values(array_filter(
            $pages,
            static fn (array $page): bool => in_array(explode('/', $page['path'])[0], $wanted, true),
        ));
    }

    /** @param array<string, mixed> $data */
    private function manifest(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed>|null */
    private function readManifest(string $directory): ?array
    {
        $path = $directory.'/manifest.json';

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /** Keep the newest runs and delete the rest, before-image folders included. */
    private function prune(): void
    {
        $runs = glob($this->runsRoot().'/*', GLOB_ONLYDIR) ?: [];
        rsort($runs);

        foreach (array_slice($runs, self::KEEP_RUNS) as $old) {
            File::deleteDirectory($old);
        }
    }

    private function runsRoot(): string
    {
        return storage_path('app/private/find-replace');
    }
}
