<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Website;

use App\Domain\Website\Builder\BuilderFile;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\Redirect;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The 301 map from the 142 URLs of the previous website (docs/09 §3). Every old URL keeps its visitors: an old
 * link that answers 404 loses the ranking it earned over years.
 */
#[Layout('layouts::admin')]
#[Title('Redirects')]
class RedirectsIndex extends Component
{
    use InteractsWithAdmin;

    public string $search = '';

    public string $fromPath = '';

    public string $toPath = '';

    public int $code = 301;

    public string $note = '';

    public string $csv = '';

    public string $result = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
    }

    public function add(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);

        $this->validate([
            'fromPath' => ['required', 'string', 'max:190', 'starts_with:/', 'not_regex:#\s#'],
            'toPath' => ['required', 'string', 'max:500', 'starts_with:/'],
            'code' => ['required', 'in:301,302'],
        ], [], ['fromPath' => 'old URL', 'toPath' => 'new URL']);

        $from = Redirect::normalize($this->fromPath);
        $to = Redirect::normalize($this->toPath);

        if ($from === $to || $from === '/') {
            $this->result = 'That redirect would point at itself — nothing to add.';
            $this->toast($this->result, 'error');

            return;
        }

        // A file answers before the app does: a redirect from a URL that is still a page would never fire, so
        // the row is not stored and the screen says why instead of claiming a redirect that does nothing.
        if (BuilderFile::answers($from)) {
            $this->result = $from.' is still a page: its file answers first, so a redirect from it would never '
                .'fire. Delete the page in All pages, then add the redirect.';
            $this->toast($this->result, 'error');

            return;
        }

        $this->store($from, $to, $this->code, $this->note);

        $this->fromPath = '';
        $this->toPath = '';
        $this->note = '';
        $this->result = BuilderFile::answers($to)
            ? 'Redirect added. It is live.'
            : 'Redirect added, but there is no page file at '.$to.' — check the new URL.';
        $this->toast('Redirect added.');
    }

    public function remove(int $redirectId): void
    {
        abort_unless($this->user()->can('website.edit'), 403);

        Redirect::query()->findOrFail($redirectId)->delete();
        $this->toast('Redirect removed.');
    }

    /**
     * Import a CSV of old,new[,code] lines — the export of the previous sitemap, or a spreadsheet column pair.
     * Existing rows are updated, so re-running an import is safe.
     */
    public function importCsv(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);

        $lines = preg_split('/\r\n|\r|\n/', trim($this->csv)) ?: [];
        $added = 0;
        $skipped = 0;
        $dangling = 0;
        $stillPages = [];

        foreach ($lines as $line) {
            // A spreadsheet copy is tab-separated, a CSV is comma-separated; both are accepted.
            $row = str_getcsv(trim($line), str_contains($line, "\t") ? "\t" : ',');

            if (count($row) < 2) {
                $skipped++;

                continue;
            }

            [$from, $to] = array_map(fn (string $cell): string => trim($cell), [$row[0], $row[1]]);

            if (! str_starts_with($from, '/') || ! str_starts_with($to, '/')) {
                $skipped++;

                continue;
            }

            if (str_starts_with(strtolower($from), '/http')) {
                $skipped++;

                continue;
            }

            $from = Redirect::normalize($from);
            $to = Redirect::normalize($to);

            if ($from === $to || $from === '/') {
                $skipped++;

                continue;
            }

            // The old URL is still a page: its file answers first, so this row would never fire.
            if (BuilderFile::answers($from)) {
                $skipped++;
                $stillPages[] = $from;

                continue;
            }

            if (! BuilderFile::answers($to)) {
                $dangling++;
            }

            $code = isset($row[2]) && (int) $row[2] === 302 ? 302 : 301;
            $this->store($from, $to, $code, $row[3] ?? null);
            $added++;
        }

        $this->csv = '';
        $this->result = $added.' redirect(s) imported.';

        if ($stillPages !== []) {
            $this->result .= ' '.count($stillPages).' skipped because the old URL is still a page ('
                .implode(', ', array_slice($stillPages, 0, 3)).(count($stillPages) > 3 ? '…' : '').').';
        }

        if ($skipped > count($stillPages)) {
            $this->result .= ' '.($skipped - count($stillPages)).' line(s) unreadable.';
        }

        if ($dangling > 0) {
            $this->result .= ' '.$dangling.' point at an address with no page file — check them.';
        }

        $this->toast($this->result);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless($this->user()->can('website.edit'), 403);

        $rows = Redirect::query()->orderBy('from_path')->get();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['old_url', 'new_url', 'code', 'hits', 'note']);

            foreach ($rows as $redirect) {
                fputcsv($out, [$redirect->from_path, $redirect->to_path, $redirect->code, $redirect->hits, $redirect->note]);
            }

            fclose($out);
        }, 'woodex-redirects-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render(): View
    {
        // Rows that predate the file check (or were imported straight into the table) can name a URL that is
        // still a page; those never fire, and the screen says so rather than counting them as working 301s.
        $notFiring = Redirect::query()->get(['from_path'])->filter(
            fn (Redirect $redirect): bool => BuilderFile::answers((string) $redirect->from_path),
        );

        return view('livewire.admin.website.redirects-index', [
            'redirects' => Redirect::query()
                ->when($this->search !== '', fn ($query) => $query->where(fn ($q) => $q
                    ->where('from_path', 'like', '%'.$this->search.'%')
                    ->orWhere('to_path', 'like', '%'.$this->search.'%')))
                ->orderByDesc('hits')
                ->orderBy('from_path')
                ->limit(500)
                ->get(),
            'total' => Redirect::query()->count(),
            'visits' => (int) Redirect::query()->sum('hits'),
            'notFiring' => $notFiring->count(),
            'inert' => $notFiring->map(fn (Redirect $redirect): string => (string) $redirect->from_path)->all(),
            'pages' => Page::query()->orderBy('slug')->get(['id', 'slug', 'title']),
        ]);
    }

    private function store(string $from, string $to, int $code, ?string $note): void
    {
        if ($from === $to || $from === '/') {
            return;
        }

        DB::table('redirects')->updateOrInsert(
            ['from_path' => $from],
            ['to_path' => $to, 'code' => $code, 'note' => Str::limit((string) $note, 255, ''), 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
