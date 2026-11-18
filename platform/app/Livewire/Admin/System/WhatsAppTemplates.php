<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Domain\System\Enums\WhatsAppTemplateEvent;
use App\Domain\System\Enums\WhatsAppTemplateStatus;
use App\Domain\System\Models\WhatsAppTemplate;
use App\Integrations\WhatsApp\Actions\SubmitWhatsAppTemplate;
use App\Integrations\WhatsApp\Actions\SyncWhatsAppTemplates;
use App\Integrations\WhatsApp\WhatsAppCredentials;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The studio's WhatsApp templates, and which one carries which moment (WAP-005).
 *
 * Meta owns the wording; this screen owns the decision. A template is synced from Meta with its status, mapped to
 * a moment such as "quotation ready", and from then on the system sends that template without a template name
 * ever appearing in the code. Only approved templates can be mapped, because sending anything else fails.
 */
#[Layout('layouts::admin')]
#[Title('WhatsApp templates')]
class WhatsAppTemplates extends Component
{
    use InteractsWithAdmin;

    #[Url(except: '')]
    public string $status = '';

    public bool $showNew = false;

    /** @var array<string, mixed> */
    public array $new = [
        'name' => '',
        'language' => 'en',
        'category' => 'UTILITY',
        'body' => '',
        'examples' => [],
    ];

    public function mount(): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);
    }

    public function sync(SyncWhatsAppTemplates $sync): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);

        $result = $sync->handle();

        if (! $result['ok']) {
            $this->toast($result['error'] ?? 'Could not reach Meta.', 'warning');

            return;
        }

        $this->toast(sprintf(
            'Synced %d template%s: %d new, %d changed%s.',
            $result['synced'],
            $result['synced'] === 1 ? '' : 's',
            $result['created'],
            $result['updated'],
            $result['removed'] > 0 ? ', '.$result['removed'].' no longer in Meta' : '',
        ));
    }

    public function submit(SubmitWhatsAppTemplate $submit): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);

        $data = $this->validate([
            'new.name' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'new.language' => ['required', 'string', 'in:en,en_US,en_GB,ur,ar'],
            'new.category' => ['required', 'string', 'in:UTILITY,MARKETING'],
            'new.body' => ['required', 'string', 'max:1024'],
            'new.examples' => ['array'],
        ], [
            'new.name.regex' => 'Use lowercase letters, numbers and underscores only, e.g. quotation_ready.',
        ]);

        $result = $submit->handle(
            (string) $data['new']['name'],
            (string) $data['new']['language'],
            (string) $data['new']['category'],
            (string) $data['new']['body'],
            array_values((array) ($data['new']['examples'] ?? [])),
        );

        if (! $result['ok']) {
            $this->toast($result['error'] ?? 'Meta refused the template.', 'error');

            return;
        }

        $this->showNew = false;
        $this->resetNew();
        $this->toast('Sent to Meta for approval. It appears here as in review until Meta decides.');
    }

    /** Point a moment at a template. Empty means the moment has no template and nothing is sent for it. */
    public function map(int $id, ?string $event): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);

        $template = WhatsAppTemplate::query()->findOrFail($id);

        if ($event !== null && $event !== '' && ! $template->isUsable()) {
            $this->toast('Only an approved template can be mapped.', 'warning');

            return;
        }

        if ($event !== null && $event !== '') {
            WhatsAppTemplate::query()->where('event_key', $event)->where('id', '!=', $id)->update(['event_key' => null]);
        }

        $template->update(['event_key' => ($event === null || $event === '') ? null : $event]);

        $this->toast($event === '' || $event === null
            ? 'Mapping removed.'
            : 'Now used for '.(WhatsAppTemplateEvent::tryFrom($event)?->label() ?? $event).'.');
    }

    public function showNewForm(): void
    {
        $this->resetNew();
        $this->showNew = true;
    }

    /** Keep one example box per {{n}} in the body, so Meta is always shown what each variable holds. */
    public function updatedNewBody(): void
    {
        preg_match_all('/\{\{(\d+)\}\}/', (string) ($this->new['body'] ?? ''), $matches);

        $count = count(array_unique($matches[1] ?? []));
        $examples = array_values((array) ($this->new['examples'] ?? []));

        if (count($examples) > $count) {
            $examples = array_slice($examples, 0, $count);
        }

        while (count($examples) < $count) {
            $examples[] = '';
        }

        $this->new['examples'] = $examples;
    }

    public function render(): View
    {
        $templates = WhatsAppTemplate::query()
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->orderByRaw('CASE WHEN event_key IS NULL THEN 1 ELSE 0 END')
            ->orderBy('name')
            ->get();

        return view('livewire.admin.system.whatsapp-templates', [
            'templates' => $templates,
            'events' => WhatsAppTemplateEvent::options(),
            'statuses' => WhatsAppTemplateStatus::options(),
            'connected' => app(WhatsAppCredentials::class)->isConfigured(),
            'canManageTemplates' => app(WhatsAppCredentials::class)->canManageTemplates(),
            'counts' => [
                'all' => WhatsAppTemplate::query()->count(),
                'approved' => WhatsAppTemplate::query()->where('status', WhatsAppTemplateStatus::Approved->value)->count(),
            ],
        ]);
    }

    private function resetNew(): void
    {
        $this->new = ['name' => '', 'language' => 'en', 'category' => 'UTILITY', 'body' => '', 'examples' => []];
    }
}
