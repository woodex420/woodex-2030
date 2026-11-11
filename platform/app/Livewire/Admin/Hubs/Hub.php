<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * P10.1: the workflow hub base (docs/27) — one page per workflow, tabs inside the page. The hub
 * mounts the existing full-page screens unchanged; the old URLs redirect to the right tab so
 * bookmarks, notifications and the Telegram buttons keep working. Tab state lives in the URL
 * (?tab=…), so tabs are linkable and back-button safe. A tab the user cannot open is hidden from
 * the bar and a directly requested one aborts 403 — the same promise the old route middleware made.
 */
#[Layout('layouts::admin')]
abstract class Hub extends Component
{
    /** The active tab (?tab=…). */
    #[Url(as: 'tab')]
    public string $tab = '';

    /**
     * Hub config: title, description and tabs in working sequence.
     *
     * @return array{title: string, description: string, breadcrumbs: array<string, string|null>, tabs: list<array{id: string, label: string, component: class-string<Component>, permission: string}>}
     */
    abstract protected function hub(): array;

    public function mount(): void
    {
        $config = $this->hub();
        $this->tab = trim($this->tab);

        if ($this->tab === '') {
            foreach ($config['tabs'] as $candidate) {
                if ($this->tabVisible($candidate)) {
                    $this->tab = $candidate['id'];

                    break;
                }
            }

            abort_if($this->tab === '', 403, 'No tab of this page is available to you.');

            return;
        }

        $active = $this->activeTab();

        if ($active === null) {
            $this->tab = '';
            $this->mount();

            return;
        }

        abort_unless($this->tabVisible($active), 403, 'That tab is not available to you.');
    }

    public function render(): View
    {
        $config = $this->hub();
        $active = $this->activeTab() ?? $config['tabs'][0];
        abort_unless($this->tabVisible($active), 403, 'That tab is not available to you.');

        $tabs = array_map(function (array $tab): array {
            $tab['visible'] = $this->tabVisible($tab);

            return $tab;
        }, $config['tabs']);

        return view('livewire.admin.hub', [
            'title' => $config['title'],
            'description' => $config['description'],
            'breadcrumbs' => $config['breadcrumbs'],
            'tabs' => $tabs,
            'active' => $active,
            'activeAlias' => $this->alias($active['component']),
            'control' => $this->control(),
        ])->title($config['title']);
    }

    /**
     * @return array{id: string, label: string, component: class-string<Component>, permission: string}|null
     */
    protected function activeTab(): ?array
    {
        foreach ($this->hub()['tabs'] as $tab) {
            if ($tab['id'] === $this->tab) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * @param  array{id: string, label: string, component: class-string<Component>, permission: string}  $tab
     */
    protected function tabVisible(array $tab): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can($tab['permission']);
    }

    /**
     * Optional control strip above the tabs — the six numbers the studio runs on, read from the
     * same queries the dashboard uses (deep QA slice D). Hubs that do not want it return [].
     *
     * @return list<array{key: string, label: string, display: string, hint: string, href: string, icon: string, tone: string, count: int}>
     */
    protected function control(): array
    {
        return [];
    }

    /**
     * Livewire alias of a component class (App\Livewire\Admin\Crm\LeadsIndex → admin.crm.leads-index).
     *
     * @param  class-string<Component>  $class
     */
    protected function alias(string $class): string
    {
        $relative = str_starts_with($class, 'App\\Livewire\\') ? substr($class, strlen('App\\Livewire\\')) : $class;

        return collect(explode('\\', $relative))->map(static fn (string $part): string => Str::kebab($part))->implode('.');
    }
}
