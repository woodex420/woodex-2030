<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Website;

use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SiteSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Where the owner pastes the analytics IDs (docs/09 §11.3, D-69, P-14). Nothing is loaded on the website until
 * an ID is filled in here, and the website never loads a script for signed-in staff.
 */
#[Layout('layouts::admin')]
#[Title('Website settings')]
class WebsiteSettings extends Component
{
    use InteractsWithAdmin;

    public ?string $ga4_id = null;

    public ?string $gtm_id = null;

    public ?string $google_site_verification = null;

    public ?string $clarity_id = null;

    public bool $tracking_enabled = true;

    public bool $chat_widget_enabled = false;

    public function mount(SiteSettings $settings): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $this->ga4_id = $settings->ga4_id;
        $this->gtm_id = $settings->gtm_id;
        $this->google_site_verification = $settings->google_site_verification;
        $this->clarity_id = $settings->clarity_id;
        $this->tracking_enabled = $settings->tracking_enabled;
        $this->chat_widget_enabled = $settings->chat_widget_enabled;

    }

    public function save(SiteSettings $settings): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $data = $this->validate([
            'ga4_id' => ['nullable', 'string', 'max:40', 'regex:/^(G-[A-Z0-9]+|UA-\d+-\d+)?$/'],
            'gtm_id' => ['nullable', 'string', 'max:40', 'regex:/^GTM-[A-Z0-9]+$/'],
            'google_site_verification' => ['nullable', 'string', 'max:120'],
            'clarity_id' => ['nullable', 'string', 'max:40'],
            'tracking_enabled' => ['boolean'],
            'chat_widget_enabled' => ['boolean'],
        ], [
            'ga4_id.regex' => 'A GA4 measurement ID looks like G-XXXXXXXXXX.',
            'gtm_id.regex' => 'A Google Tag Manager ID looks like GTM-XXXXXXX.',
        ]);

        $settings->fill([
            'ga4_id' => $data['ga4_id'] ?: null,
            'gtm_id' => $data['gtm_id'] ?: null,
            'google_site_verification' => $data['google_site_verification'] ?: null,
            'clarity_id' => $data['clarity_id'] ?: null,
            'tracking_enabled' => (bool) $data['tracking_enabled'],
            'chat_widget_enabled' => (bool) $data['chat_widget_enabled'],
        ]);
        $settings->save();

        $this->toast('Saved. The change is live on the next page view.');
    }

    public function render(SiteSettings $settings): View
    {
        return view('livewire.admin.website.website-settings', [
            'live' => [
                'ga4' => filled($settings->ga4_id),
                'gtm' => filled($settings->gtm_id),
                'clarity' => filled($settings->clarity_id),
                'gsc' => filled($settings->google_site_verification),
            ],
        ]);
    }
}
