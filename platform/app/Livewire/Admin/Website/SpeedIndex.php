<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Website;

use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageSpeedRun;
use App\Domain\Website\Speed\InternalSpeedAudit;
use App\Domain\Website\Speed\PageSpeed;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SpeedSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The speed dashboard (V2-3 F9, docs/14 §5a). Internal checks always run — image weight, image
 * formats, cached page size — no key, no third party (the owner's keyless-first call). Google's
 * PageSpeed runs behind the studio's own API key, saved right here, and every run is kept as
 * history so a page's score is a line over time, not one number.
 */
#[Layout('layouts::admin')]
#[Title('Speed')]
class SpeedIndex extends Component
{
    use InteractsWithAdmin;

    /** The new key typed into the settings card — written encrypted, never read back out. */
    public ?string $apiKey = null;

    public function mount(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
    }

    public function saveKey(SpeedSettings $settings): void
    {
        abort_unless($this->user()->can('website.edit'), 403);

        $this->validate(['apiKey' => ['required', 'string', 'max:200']]);

        $settings->google_api_key = trim((string) $this->apiKey);
        $settings->save();

        $this->apiKey = null;
        $this->toast('API key saved — the PageSpeed buttons are awake.');
    }

    public function clearKey(SpeedSettings $settings): void
    {
        abort_unless($this->user()->can('website.edit'), 403);

        $settings->google_api_key = null;
        $settings->save();

        $this->toast('API key removed — back to internal checks only.');
    }

    public function run(int $pageId, string $strategy, PageSpeed $pageSpeed): void
    {
        abort_unless($this->user()->can('website.edit'), 403);

        $page = Page::query()->findOrFail($pageId);

        if (! $page->isPublished()) {
            $this->toast('Publish the page first — Google cannot see drafts.');

            return;
        }

        $result = $pageSpeed->run($page->canonical(), $strategy);

        if ($result === null) {
            $this->toast('Google did not answer — try again in a moment.', 'error');

            return;
        }

        PageSpeedRun::query()->create([
            'url' => $page->canonical(),
            'strategy' => $strategy === 'desktop' ? 'desktop' : 'mobile',
            'score' => $result['score'],
            'metrics' => $result['metrics'],
        ]);

        $this->toast(sprintf('PageSpeed %s: %d/100.', $strategy, $result['score']));
    }

    public function render(): View
    {
        return view('livewire.admin.website.speed', [
            'audit' => (new InternalSpeedAudit)->handle(),
            'pageSpeedReady' => app(PageSpeed::class)->isConfigured(),
            'runs' => PageSpeedRun::query()->latest('id')->limit(8)->get(),
        ]);
    }
}
