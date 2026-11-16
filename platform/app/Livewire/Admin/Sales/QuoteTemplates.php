<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Data\QuoteBlueprint;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Enums\TemplateKind;
use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Domain\Sales\Services\DocumentStyles;
use App\Domain\Sales\Services\FurnitureDocument;
use App\Domain\Sales\Services\TemplatePackImporter;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use App\Support\Html;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use JsonException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Quote templates (QUO-002): the 16 Woodex templates and any saved from quotations. Details are edited here;
 * sections and lines are edited by building a quotation and using "Save as template" → "Update a template".
 */
#[Layout('layouts::admin')]
#[Title('Quote templates')]
class QuoteTemplates extends Component
{
    use InteractsWithAdmin, WithFileUploads;

    public ?TemporaryUploadedFile $importFile = null;

    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: false)]
    public bool $showInactive = false;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public bool $showPreview = false;

    #[Locked]
    public ?int $previewId = null;

    public string $formName = '';

    public string $formDescription = '';

    public string $formLine = 'interior';

    public string $formKind = 'execution';

    public string $formPlan = 'execution_50_40_10';

    public string $formTerms = '';

    public string $formNotes = '';

    public string $formScope = '';

    public bool $formActive = true;

    public function mount(): void
    {
        $this->authorize('viewAny', Quote::class);
    }

    public function openEdit(int $id): void
    {
        $this->authorize('manageCatalog', Quote::class);
        $template = QuoteTemplate::query()->findOrFail($id);
        $this->resetValidation();
        $this->editingId = $template->id;
        $this->formName = $template->name;
        $this->formDescription = (string) $template->description;
        $this->formLine = $template->business_line->value;
        $this->formKind = $template->kind->value;
        $this->formPlan = $template->milestone_plan->value;
        $this->formTerms = (string) $template->terms;
        $this->formNotes = (string) $template->notes;
        $this->formScope = Html::toPlainText($template->scope_html);
        $this->formActive = (bool) $template->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manageCatalog', Quote::class);

        $this->validate([
            'formName' => ['required', 'string', 'max:120'],
            'formDescription' => ['nullable', 'string', 'max:500'],
            'formLine' => ['required', Rule::enum(BusinessLine::class)],
            'formKind' => ['required', Rule::enum(TemplateKind::class)],
            'formPlan' => ['required', Rule::enum(MilestonePlanKey::class)],
            'formTerms' => ['nullable', 'string', 'max:10000'],
            'formNotes' => ['nullable', 'string', 'max:5000'],
            'formScope' => ['nullable', 'string', 'max:20000'],
            'formActive' => ['boolean'],
        ], [], ['formName' => 'name', 'formLine' => 'line', 'formKind' => 'type', 'formPlan' => 'milestone plan']);

        QuoteTemplate::query()->findOrFail($this->editingId)->update([
            'name' => trim($this->formName),
            'description' => trim($this->formDescription) !== '' ? trim($this->formDescription) : null,
            'business_line' => $this->formLine,
            'kind' => $this->formKind,
            'milestone_plan' => $this->formPlan,
            'terms' => trim($this->formTerms) !== '' ? trim($this->formTerms) : null,
            'notes' => trim($this->formNotes) !== '' ? trim($this->formNotes) : null,
            'scope_html' => Html::fromPlainText($this->formScope),
            'is_active' => $this->formActive,
        ]);

        $this->showForm = false;
        $this->toast('Template saved. New quotations use it from now on.');
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('manageCatalog', Quote::class);
        $template = QuoteTemplate::query()->findOrFail($id);
        $template->update(['is_active' => ! $template->is_active]);
        $this->toast($template->is_active ? "\"{$template->name}\" is available again." : "\"{$template->name}\" hidden from new quotations.");
    }

    public function preview(int $id): void
    {
        $this->previewId = QuoteTemplate::query()->findOrFail($id)->id;
        $this->showPreview = true;
    }

    public function exportTemplate(int $id): StreamedResponse
    {
        $this->authorize('manageCatalog', Quote::class);
        $template = QuoteTemplate::query()->findOrFail($id);

        $json = json_encode([
            'format' => 'woodex-quote-template',
            'version' => 1,
            'name' => $template->name,
            'description' => $template->description,
            'business_line' => $template->business_line->value,
            'kind' => $template->kind->value,
            'milestone_plan' => $template->milestone_plan->value,
            'terms' => $template->terms,
            'notes' => $template->notes,
            'scope_html' => $template->scope_html,
            'blueprint' => $template->blueprint,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return response()->streamDownload(static function () use ($json): void {
            echo $json;
        }, $template->slug.'.woodex-template.json', ['Content-Type' => 'application/json']);
    }

    public function importTemplate(): void
    {
        $this->authorize('manageCatalog', Quote::class);
        $this->validate(['importFile' => ['required', 'file', 'max:512']], [], ['importFile' => 'template file']);

        try {
            $decoded = json_decode((string) file_get_contents((string) $this->importFile->getRealPath()), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        if (! is_array($decoded)) {
            $this->addError('importFile', 'That file is not valid JSON.');

            return;
        }

        // A v2.1 template pack ({templates: [...]}) imports as several templates at once.
        if (isset($decoded['templates']) && is_array($decoded['templates'])) {
            $summary = $this->packSummary(app(TemplatePackImporter::class)->import($decoded));
            $this->importFile = null;
            $this->toast($summary);

            return;
        }

        $validator = Validator::make($decoded, [
            'format' => ['required', 'in:woodex-quote-template'],
            'version' => ['required', 'integer', 'in:1'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'business_line' => ['required', Rule::enum(BusinessLine::class)],
            'kind' => ['required', Rule::enum(TemplateKind::class)],
            'milestone_plan' => ['required', Rule::enum(MilestonePlanKey::class)],
            'terms' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'scope_html' => ['nullable', 'string', 'max:20000'],
            'blueprint' => ['required', 'array'],
            'blueprint.sections' => ['present', 'array', 'max:50'],
            'blueprint.sections.*.items' => ['present', 'array', 'max:200'],
        ]);

        if ($validator->fails()) {
            $this->addError('importFile', 'Not a Woodex template file: '.$validator->errors()->first());

            return;
        }

        // validated() would strip unruled blueprint sub-keys (section names/notes); the payload passed validation, so use it whole.
        $data = $decoded;

        // Normalize through the same reader quotations use, and drop catalog links this install cannot resolve.
        $sections = QuoteBlueprint::sections($data['blueprint']);
        $catalogIds = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                if ($item['catalog_item_id'] !== null) {
                    $catalogIds[] = $item['catalog_item_id'];
                }
            }
        }
        $known = $catalogIds === [] ? [] : CatalogItem::query()->whereIn('id', array_unique($catalogIds))->pluck('id')->all();
        foreach ($sections as $si => $section) {
            foreach ($section['items'] as $ii => $item) {
                if ($item['catalog_item_id'] !== null && ! in_array($item['catalog_item_id'], $known, true)) {
                    $sections[$si]['items'][$ii]['catalog_item_id'] = null;
                }
            }
        }

        $base = Str::slug((string) $data['name']) ?: 'template';
        $slug = $base;
        $n = 2;
        while (QuoteTemplate::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        $presentation = is_array($data['blueprint']['presentation'] ?? null) ? $data['blueprint']['presentation'] : [];
        if (! isset($presentation['quote_layout']) && $data['business_line'] === 'furniture') {
            $presentation['quote_layout'] = 'furniture';
        }

        $template = DB::transaction(fn (): QuoteTemplate => QuoteTemplate::query()->create([
            'name' => trim((string) $data['name']),
            'slug' => $slug,
            'description' => trim((string) ($data['description'] ?? '')) !== '' ? trim((string) $data['description']) : null,
            'business_line' => $data['business_line'],
            'kind' => $data['kind'],
            'milestone_plan' => $data['milestone_plan'],
            'terms' => trim((string) ($data['terms'] ?? '')) !== '' ? trim((string) $data['terms']) : null,
            'notes' => trim((string) ($data['notes'] ?? '')) !== '' ? trim((string) $data['notes']) : null,
            'scope_html' => Html::sanitize(isset($data['scope_html']) ? (string) $data['scope_html'] : null),
            'blueprint' => ['sections' => $sections, 'furniture' => [...FurnitureDocument::text($data['blueprint']['furniture'] ?? null), 'advance_bp' => max(0, min(10000, (int) ($data['blueprint']['furniture']['advance_bp'] ?? 5000)))], 'presentation' => [
                'quote_layout' => DocumentStyles::quoteLayout($presentation['quote_layout'] ?? null),
                'pdf_theme' => DocumentStyles::theme($presentation['pdf_theme'] ?? null),
                'quote_blocks' => DocumentStyles::blocks('quote', $presentation['quote_blocks'] ?? DocumentStyles::quoteLayoutBlocks(DocumentStyles::quoteLayout($presentation['quote_layout'] ?? null), true), true),
            ]],
            'is_active' => true,
            'position' => (int) QuoteTemplate::query()->max('position') + 1,
        ]));

        $this->importFile = null;
        $this->toast("\"{$template->name}\" imported as a new template.");
    }

    /** The v2.1 "Import Woodex starter templates" button: the bundled pack, existing names skipped. */
    public function importStarters(): void
    {
        $this->authorize('manageCatalog', Quote::class);

        $decoded = TemplatePackImporter::starterContents();

        if ($decoded === null) {
            $this->toast('The starter template file is missing from this install.', 'error');

            return;
        }

        $this->toast($this->packSummary(app(TemplatePackImporter::class)->import($decoded)));
    }

    /** @param  array{imported: list<string>, skipped: list<string>}  $result */
    private function packSummary(array $result): string
    {
        $imported = count($result['imported']);
        $skipped = count($result['skipped']);

        if ($imported === 0) {
            return $skipped > 0
                ? 'All templates in that pack are already in your library.'
                : 'No usable templates were found in that pack.';
        }

        return "{$imported} ".str('template')->plural($imported).' imported'.($skipped > 0 ? " ({$skipped} skipped — already exist)" : '').'.';
    }

    public function render(): View
    {
        $previewTemplate = $this->showPreview && $this->previewId !== null ? QuoteTemplate::query()->find($this->previewId) : null;

        return view('livewire.admin.sales.quote-templates', [
            'templates' => QuoteTemplate::query()
                ->withCount('quotes')
                ->when(! $this->showInactive, fn (Builder $q) => $q->where('is_active', true))
                ->when($this->kind !== '', fn (Builder $q) => $q->where('kind', $this->kind))
                ->orderBy('position')
                ->get(),
            'kinds' => TemplateKind::options(),
            'lines' => BusinessLine::options(),
            'plans' => MilestonePlanKey::options(),
            'previewTemplate' => $previewTemplate,
            'previewSections' => $previewTemplate !== null ? QuoteBlueprint::sections($previewTemplate->blueprint) : [],
            'canEdit' => $this->user()->can('manageCatalog', Quote::class),
            'canCreateQuote' => $this->user()->can('create', Quote::class),
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }
}
