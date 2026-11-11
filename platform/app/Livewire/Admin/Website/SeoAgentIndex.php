<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Website;

use App\Domain\Website\Seo\FileAudit;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The SEO agent's worklist, read from the pages themselves (Woodex OS 1, step 6).
 *
 * Every finding is a plain rule a person can repeat by hand — a missing title, a description cut off in a
 * search result, an image a screen reader cannot describe. The screen used to offer one-click "fixes" that wrote
 * the `seo` column of a `pages` row; no file reads that column, so the button changed nothing on the site. The
 * fix now happens in the builder, on the file, and this screen points at it.
 */
#[Layout('layouts::admin')]
#[Title('SEO agent')]
class SeoAgentIndex extends Component
{
    use InteractsWithAdmin;

    public string $code = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
    }

    public function setCode(string $code): void
    {
        $this->code = $this->code === $code ? '' : $code;
    }

    public function render(): View
    {
        $audit = (new FileAudit)->handle();

        $pages = collect($audit['pages'])
            // A selected rule shows that rule only: the point of the filter is to work one finding at a time.
            ->when($this->code !== '', fn ($pages) => $pages->map(fn (array $page): array => array_replace($page, [
                'issues' => array_values(array_filter(
                    $page['issues'],
                    fn (array $issue): bool => $issue['code'] === $this->code,
                )),
            ]))->filter(fn (array $page): bool => $page['issues'] !== []))
            ->values();

        $byCode = collect($audit['pages'])
            ->flatMap(fn (array $page): array => collect($page['issues'])->map(fn (array $issue): array => $issue + ['url' => $page['url']])->all())
            ->countBy('code')
            ->sortDesc();

        return view('livewire.admin.website.seo-agent', [
            'pages' => $pages,
            'site' => $audit['site'],
            'byCode' => $byCode,
            'counts' => $audit['counts'],
            'code' => $this->code,
        ]);
    }
}
