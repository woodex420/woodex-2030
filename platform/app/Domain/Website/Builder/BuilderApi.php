<?php

declare(strict_types=1);

namespace App\Domain\Website\Builder;

use App\Domain\Website\Actions\SaveHtmlFile;
use App\Domain\Website\Support\HtmlGuard;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Laravel side of the Visual Page Builder.
 *
 * The builder was written against one PHP endpoint with an `action` field. Woodex OS 1 keeps that contract and
 * changes what is behind it: a page is an HTML file under `public/` — the file the web server serves — not a
 * database row. `BuilderFile` is the only place that touches the disk; `SaveHtmlFile` is the only place that
 * writes a page, and it carries the conflict check, the script guard and the version snapshot.
 */
final class BuilderApi
{
    public function __construct(
        private readonly SaveHtmlFile $save,
        private readonly ThemeBridge $theme,
        private readonly BuilderMedia $media,
    ) {}

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function handle(string $action, array $in, ?User $user): array
    {
        return match ($action) {
            'status' => ['ok' => true, 'loggedIn' => true, 'adminOnly' => true, 'needsSetup' => false],
            'pages' => ['ok' => true, 'pages' => self::pageList()],
            'load' => $this->load($in),
            'save' => $this->save($in, $user),
            'backups' => $this->backups($in),
            'backup_get' => $this->backupGet($in),
            'restore' => $this->restore($in, $user),
            'page_new' => $this->pageNew($in, $user),
            'media' => ['ok' => true, 'media' => $this->media->urls()],
            'media_usage' => ['ok' => true, 'used' => []],
            'theme_get' => ['ok' => true, 'vars' => $this->theme->vars()],
            'theme' => $this->saveTheme($in, $user),
            'logout' => ['ok' => true],
            'login', 'setup', 'password' => ['ok' => false, 'error' => 'Sign in through Woodex OS — the builder has no password of its own.'],
            'media_delete', 'import_url', 'fetch_page', 'appr_apply' => $this->later($action),
            // The owner's own sections (docs/audit/21 §5.2): the builder's "My sections" panel is the screen,
            // and this is the server it has been asking for. A global section stays linked to every page that
            // carries its `data-wx-global` marker, and `blocks_sync` replaces those copies at once.
            'blocks_list' => ['ok' => true, 'blocks' => $this->library($user)->all()],
            'blocks_usage' => ['ok' => true, 'usage' => $this->library($user)->usage()],
            'blocks_save' => $this->library($user)->save($in),
            'blocks_delete' => $this->library($user)->delete((int) ($in['id'] ?? 0), (bool) ($in['force'] ?? false)),
            'blocks_sync' => $this->library($user)->sync((int) ($in['id'] ?? 0)),
            'blocks_import' => $this->library($user)->import(array_values((array) ($in['blocks'] ?? []))),
            default => ['ok' => false, 'error' => 'Unknown builder action: '.$action],
        };
    }

    /** The section library, acting as the staff member who is signed in (for the page snapshots it makes). */
    private function library(?User $user): BlockLibrary
    {
        return new BlockLibrary($this->save, $user);
    }

    /**
     * The page picker, as the builder expects it: a path, the URL it answers, a title, a preview link.
     *
     * @return list<array{path: string, url: string, title: string, preview_url: string}>
     */
    public static function pageList(): array
    {
        return array_map(static fn (array $page): array => [
            'path' => $page['path'],
            'url' => $page['url'],
            'title' => $page['title'],
            'preview_url' => $page['url'],
        ], BuilderFile::pages());
    }

    /**
     * @return array<string, mixed>
     */
    public function upload(?UploadedFile $file, ?User $user): array
    {
        return $this->media->upload($file, $user);
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function load(array $in): array
    {
        $path = (string) ($in['path'] ?? '');
        $html = BuilderFile::read($path);

        return [
            'ok' => true,
            'html' => $html,
            'mtime' => BuilderFile::mtime($path),
            'hash' => sha1($html),
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function save(array $in, ?User $user): array
    {
        $path = (string) ($in['path'] ?? '');

        // Resolve before the write so the guard's error names a page the builder can show.
        BuilderFile::resolve($path);

        $state = ($this->save)(
            $path,
            (string) ($in['html'] ?? ''),
            isset($in['mtime']) ? (int) $in['mtime'] : null,
            isset($in['hash']) ? (string) $in['hash'] : null,
            (bool) ($in['force'] ?? false),
            $user,
        );

        return ['ok' => true, 'mtime' => $state['mtime'], 'hash' => $state['hash']];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function backups(array $in): array
    {
        return [
            'ok' => true,
            'backups' => BuilderFile::versions((string) ($in['path'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function backupGet(array $in): array
    {
        return [
            'ok' => true,
            'html' => BuilderFile::version((string) ($in['path'] ?? ''), (string) ($in['file'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function restore(array $in, ?User $user): array
    {
        $path = (string) ($in['path'] ?? '');
        $html = BuilderFile::version($path, (string) ($in['file'] ?? ''));

        if (trim($html) === '') {
            throw ValidationException::withMessages(['file' => 'That version has no page HTML to restore.']);
        }

        // A restore is a save: the current state gets its own snapshot first, so it can be undone.
        $state = ($this->save)($path, $html, BuilderFile::mtime($path), null, true, $user);

        return ['ok' => true, 'mtime' => $state['mtime'], 'hash' => $state['hash']];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function pageNew(array $in, ?User $user): array
    {
        $folder = trim((string) ($in['folder'] ?? ''), '/');
        $slug = Str::slug((string) ($in['slug'] ?? ''));
        $html = (string) ($in['html'] ?? '');

        if ($slug === '') {
            throw ValidationException::withMessages(['slug' => 'Enter an address for the new page.']);
        }

        if (stripos($html, '<html') === false || stripos($html, '</html>') === false) {
            throw ValidationException::withMessages(['html' => 'That is not a complete page document.']);
        }

        $path = BuilderFile::normalise(($folder !== '' ? $folder.'/' : '').$slug.'/index.html');

        if (BuilderFile::exists($path)) {
            throw ValidationException::withMessages(['slug' => 'A page already answers '.BuilderFile::urlOf($path)]);
        }

        // A new page is usually a copy of an existing one, so every script it carries is already in use. A
        // document with code the site has never seen is the owner's to add, exactly as on save.
        if (! $user?->can('website.raw_html')) {
            $site = array_map(
                static fn (array $page): string => BuilderFile::read($page['path']),
                array_filter(BuilderFile::pages(), static fn (array $page): bool => $page['path'] !== $path),
            );

            HtmlGuard::assertNoNewCode($html, '', array_values($site));
        }

        BuilderFile::write($path, trim($html)."\n");

        return ['ok' => true, 'path' => $path, 'url' => BuilderFile::urlOf($path)];
    }

    /**
     * The Theme tab saves the whole panel at once. The site theme is an owner decision, so it needs
     * `theme.manage` — the same permission the standalone Theme Builder requires.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function saveTheme(array $in, ?User $user): array
    {
        if (! $user?->can('theme.manage')) {
            return ['ok' => false, 'error' => 'Only the owner can change the site theme.'];
        }

        $this->theme->save((array) ($in['vars'] ?? []));

        return ['ok' => true];
    }

    /** @return array<string, mixed> */
    private function later(string $action): array
    {
        $slice = match ($action) {
            'media_delete' => 'the media slice',
            'import_url', 'fetch_page' => 'a later slice',
            'appr_apply' => 'a later slice',
            default => 'the section-library slice',
        };

        return ['ok' => false, 'error' => str_replace('_', ' ', ucfirst($action)).' arrives with '.$slice.'.'];
    }
}
