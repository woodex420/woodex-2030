<?php

declare(strict_types=1);

namespace App\Domain\Website\Actions;

use App\Domain\Website\Builder\BuilderFile;
use App\Domain\Website\Builder\StalePageException;
use App\Domain\Website\Support\HtmlGuard;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Saves a page document straight into the site's HTML file (Woodex OS 1).
 *
 * The public site is static files under `public/`, and the web server answers those files before Laravel is
 * asked. So a save here is a save to the live site — no publish step, no cache to clear, no second copy.
 *
 * The rules are the ones the v2.1 builder had, and the ones the database-backed builder had before it: exact
 * document checks, no editor markup left behind, a conflict check against the file as it was opened, the
 * script guard for everyone below the owner, and a snapshot of the previous HTML before every write.
 */
final class SaveHtmlFile
{
    /** The same ceiling the old builder had (MAX_HTML). */
    public const int MAX_BYTES = 3 * 1024 * 1024;

    /**
     * @return array{mtime: int, hash: string} the file as it is after the write
     */
    public function __invoke(string $path, string $html, ?int $mtime, ?string $hash, bool $force, ?User $user): array
    {
        $html = trim($html);

        if ($html === '') {
            throw ValidationException::withMessages(['html' => 'The page is empty — nothing was saved.']);
        }

        if (strlen($html) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['html' => 'The page is larger than 3 MB — nothing was saved.']);
        }

        if (stripos($html, '<html') === false || stripos($html, '</html>') === false) {
            throw ValidationException::withMessages(['html' => 'That is not a complete page document — nothing was saved.']);
        }

        // If the builder's own markers reach the file, the next load would start from editor state.
        if (preg_match('~<[^>]+\s(contenteditable|data-wx-ed)[\s=>]~i', $html) === 1) {
            throw ValidationException::withMessages(['html' => 'Editor markup detected — save aborted to protect the page.']);
        }

        $before = BuilderFile::exists($path) ? BuilderFile::read($path) : '';

        $this->assertFresh($path, $before, $mtime, $hash, $force);

        if (! $user?->can('website.raw_html')) {
            HtmlGuard::assertNoNewCode($html, $before, $this->otherDocuments($path));
        }

        BuilderFile::snapshot($path, $user);

        BuilderFile::write($path, $html."\n");

        return ['mtime' => BuilderFile::mtime($path), 'hash' => BuilderFile::hash($path)];
    }

    /**
     * Has the file changed since the builder opened it?
     *
     * The hash is exact; the mtime is the fallback for a client that did not send one. A file the builder has
     * never seen is not stale — it is a page being created.
     */
    private function assertFresh(string $path, string $before, ?int $mtime, ?string $hash, bool $force): void
    {
        if ($force || $before === '') {
            return;
        }

        $changed = $hash !== null && $hash !== ''
            ? ! hash_equals(sha1($before), $hash)
            : ($mtime !== null && $mtime !== BuilderFile::mtime($path));

        if ($changed) {
            throw StalePageException::since(BuilderFile::titleOf($before));
        }
    }

    /**
     * Every other HTML file on the site, so a script the site already uses stays allowed for an editor.
     *
     * @return list<string>
     */
    private function otherDocuments(string $path): array
    {
        $documents = [];

        foreach (BuilderFile::pages() as $page) {
            if ($page['path'] === $path) {
                continue;
            }

            $documents[] = BuilderFile::read($page['path']);
        }

        return $documents;
    }
}
