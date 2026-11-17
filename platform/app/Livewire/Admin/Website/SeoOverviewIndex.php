<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Website;

use App\Domain\Website\Seo\FileAudit;
use App\Domain\Website\Seo\SearchMetadataAudit;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Both read-only audits inspect published files. Editing stays in the native page builder. */
#[Layout('layouts::admin')]
#[Title('SEO')]
class SeoOverviewIndex extends Component
{
    use InteractsWithAdmin;

    public string $filter = 'issues';

    public string $search = '';

    #[Url(as: 'audit', except: 'listings')]
    public string $tab = 'listings';

    #[Url(as: 'finding', except: 'all')]
    public string $metadataGroup = 'all';

    private const METADATA_GROUPS = ['all', 'canonical', 'social', 'jsonld', 'indexing', 'unreadable'];

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'metadata' ? 'metadata' : 'listings';
    }

    public function mount(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
        $this->setTab($this->tab);
        $this->setMetadataGroup($this->metadataGroup);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter === 'all' ? 'all' : 'issues';
    }

    public function setMetadataGroup(string $group): void
    {
        $this->metadataGroup = in_array($group, self::METADATA_GROUPS, true) ? $group : 'all';
    }

    public function clearMetadataFilters(): void
    {
        $this->search = '';
        $this->metadataGroup = 'all';
    }

    public function render(): View
    {
        // Mount is not enough when permissions are revoked while an existing component is open.
        abort_unless($this->user()->can('website.edit'), 403);
        $needle = mb_strtolower(mb_substr(trim($this->search), 0, 160));
        $rows = collect();
        $metadata = null;
        $metadataRows = [];
        $counts = ['files' => 0, 'flagged' => 0, 'missing_description' => 0, 'missing_title' => 0];

        // Do not run the complete listing/link audit on every metadata-filter keystroke.
        if ($this->tab === 'metadata') {
            $metadata = app(SearchMetadataAudit::class)->handle();
            $group = in_array($this->metadataGroup, self::METADATA_GROUPS, true) ? $this->metadataGroup : 'all';
            $metadataRows = collect($metadata['entries'])
                ->when($this->filter === 'issues', fn ($entries) => $entries->where('issues', '!=', []))
                ->when($group !== 'all', fn ($entries) => $entries->filter(fn (array $entry): bool => array_any(
                    $entry['issues'], fn (array $issue): bool => str_starts_with($issue['code'], $group),
                )))
                ->when($needle !== '', fn ($entries) => $entries->filter(fn (array $entry): bool => str_contains(
                    mb_strtolower($entry['url'].' '.$entry['canonical'].' '.implode(' ', array_column($entry['issues'], 'message')).' '.implode(' ', array_column($entry['issues'], 'code'))),
                    $needle,
                )))
                ->sortBy('url')->values()->all();
        } else {
            $audit = app(FileAudit::class)->handle();
            $rows = collect($audit['entries'])
                ->when($this->filter === 'issues', fn ($entries) => $entries->where('issues', '!=', []))
                ->when($needle !== '', fn ($entries) => $entries->filter(fn (array $entry): bool => str_contains(
                    mb_strtolower($entry['url'].' '.$entry['title'].' '.$entry['description']), $needle,
                )))
                ->sortBy('url')->values();
            $counts = [
                'files' => $audit['counts']['files'],
                'flagged' => $audit['counts']['flagged'],
                'missing_description' => collect($audit['entries'])->where('description', '')->count(),
                'missing_title' => collect($audit['entries'])->where('title', '')->count(),
            ];
        }

        return view('livewire.admin.website.seo-overview-index', [
            'metadata' => $metadata,
            'metadataRows' => $metadataRows,
            'rows' => $rows,
            'counts' => $counts,
            'filter' => $this->filter,
        ]);
    }
}
