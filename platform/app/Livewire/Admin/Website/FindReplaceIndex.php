<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Website;

use App\Domain\Website\Builder\BuilderFile;
use App\Domain\Website\Builder\SiteWideReplace;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Find & replace across the site's page files (docs/audit/21 §5.1).
 *
 * The phone number, the email and the address are written into all 142 pages. This is the screen that changes
 * them together: a dry run that lists every page and its count, then one write that keeps a before-image of
 * each file so the whole run can be undone. It never claims a change it did not make — a stale preview is
 * refused, and a search that matches nothing writes nothing.
 */
#[Layout('layouts::admin')]
#[Title('Find & replace')]
class FindReplaceIndex extends Component
{
    use InteractsWithAdmin;

    public string $find = '';

    public string $replace = '';

    public bool $matchCase = false;

    /** @var list<string> */
    public array $sections = [];

    /** @var array{scanned: int, total: int, pages: list<array{path: string, url: string, count: int, sample: string}>}|null */
    public ?array $preview = null;

    /** The search the preview was run for. Replacing is refused unless it still matches. */
    public string $signature = '';

    public string $result = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
    }

    public function runPreview(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);

        if (! $this->valid()) {
            return;
        }

        $this->preview = $this->service()->preview($this->find, $this->sections, $this->matchCase);
        $this->signature = $this->signatureOf();
        $this->result = '';
    }

    public function apply(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);

        if (! $this->valid()) {
            $this->signature = '';

            return;
        }

        if ($this->preview === null || $this->signature !== $this->signatureOf()) {
            $this->signature = '';
            $this->result = 'Preview again: the search or the areas changed since the last preview, and nothing was replaced.';
            $this->toast($this->result, 'error');

            return;
        }

        // The site is files, and a builder save in another tab changes them. Recount before writing: a preview
        // that no longer describes the site is not permission to write it.
        $live = $this->service()->preview($this->find, $this->sections, $this->matchCase);

        if ($live['total'] !== $this->preview['total'] || count($live['pages']) !== count($this->preview['pages'])) {
            $this->preview = $live;
            $this->signature = '';
            $this->result = 'The pages changed since the preview — nothing was replaced. Check the new counts and press Replace again.';
            $this->toast($this->result, 'error');

            return;
        }

        try {
            $run = $this->service()->apply($this->find, $this->replace, $this->sections, $this->matchCase);
        } catch (ValidationException $exception) {
            $this->toastValidation($exception);

            return;
        }

        $this->preview = null;
        $this->signature = '';
        $this->result = 'Replaced '.number_format($run['total']).' match(es) in '.$run['files']
            .' page(s). Every page was kept as a version; Undo last run puts them all back.';
        $this->toast('Replaced in '.$run['files'].' page(s).');
    }

    public function undo(string $run): void
    {
        abort_unless($this->user()->can('website.edit'), 403);

        try {
            $restored = $this->service()->undo($run);
        } catch (ValidationException $exception) {
            $this->toastValidation($exception);

            return;
        }

        $this->result = 'Restored '.$restored['files'].' page(s) to how they were before that run.'
            .($restored['missing'] > 0 ? ' '.$restored['missing'].' page(s) no longer exist and were left alone.' : '');
        $this->toast('Restored '.$restored['files'].' page(s).');
    }

    public function render(): View
    {
        return view('livewire.admin.website.find-replace-index', [
            // Named apart from the `$sections` property: Livewire's public properties win over view data,
            // so a variable of the same name would arrive holding the chosen areas, not the options.
            'areaOptions' => SiteWideReplace::SECTIONS,
            'runs' => $this->service()->runs(),
            'pageCount' => count(BuilderFile::pages()),
        ]);
    }

    private function service(): SiteWideReplace
    {
        return new SiteWideReplace($this->user());
    }

    /** The search, exactly, so a preview cannot authorise a different change. */
    private function signatureOf(): string
    {
        $sections = $this->sections;
        sort($sections);

        return sha1((string) json_encode([$this->find, $this->replace, $this->matchCase, $sections]));
    }

    private function valid(): bool
    {
        try {
            $this->validate([
                'find' => ['required', 'string', 'min:3', 'max:500'],
                'replace' => ['nullable', 'string', 'max:500'],
                'matchCase' => ['boolean'],
                'sections' => ['array'],
                'sections.*' => ['string', 'in:'.implode(',', array_keys(SiteWideReplace::SECTIONS))],
            ], [], ['find' => 'the text to find', 'replace' => 'the replacement']);
        } catch (ValidationException $exception) {
            $this->toastValidation($exception);

            return false;
        }

        if ($this->find === $this->replace) {
            $this->toast('The search and the replacement are the same — nothing would change.', 'error');

            return false;
        }

        return true;
    }
}
