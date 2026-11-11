<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Enums\Unit;
use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Sales\Models\DescriptionTemplate;
use App\Domain\Sales\Models\Quote;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use App\Support\NumberInput;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Rate book (QUO-001): the catalogue the builder copies lines from. Everyone who quotes can look up rates;
 * managers (quotes.catalog) change them. Changing a rate never changes existing quotations.
 */
#[Layout('layouts::admin')]
#[Title('Rate book')]
class RateBook extends Component
{
    use InteractsWithAdmin, WithFileUploads, WithPagination;

    /** @var array<string, string> */
    public const FINISHES = ['essential' => 'Essential', 'standard' => 'Standard', 'premium' => 'Premium'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: 'active')]
    public string $state = 'active';

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $formCategory = '';

    public string $formDescription = '';

    public string $formUnit = 'sft';

    public string $formRate = '';

    public string $formCost = '';

    public string $formFinish = '';

    public string $formLine = '';

    public bool $formActive = true;

    /** @var TemporaryUploadedFile|null */
    public $formImage = null;

    /** The picture already stored on the item being edited (kept unless replaced or removed). */
    public ?string $formImagePath = null;

    public bool $formRemoveImage = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Quote::class);
        $descriptionId = request()->integer('description');
        if ($descriptionId > 0) {
            $this->authorize('manageCatalog', Quote::class);
            $description = DescriptionTemplate::query()->where('is_active', true)->findOrFail($descriptionId);
            $this->openCreate();
            $this->formDescription = $description->body;
            $this->formCategory = $description->section;
            $this->formLine = $description->business_line->value;
        }
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'category', 'state'], true)) {
            $this->resetPage();
        }
    }

    public function openCreate(): void
    {
        $this->authorize('manageCatalog', Quote::class);
        $this->resetForm();
        $this->formCategory = $this->category;
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $this->authorize('manageCatalog', Quote::class);
        $item = CatalogItem::query()->findOrFail($id);
        $this->resetForm();
        $this->editingId = $item->id;
        $this->formCategory = $item->category;
        $this->formDescription = $item->description;
        $this->formUnit = $item->unit;
        $this->formRate = $item->rate_minor > 0 ? NumberInput::fromMinor((int) $item->rate_minor) : '';
        $this->formCost = $item->internal_cost_minor !== null ? NumberInput::fromMinor((int) $item->internal_cost_minor) : '';
        $this->formFinish = (string) $item->finish;
        $this->formLine = $item->business_line->value ?? '';
        $this->formActive = (bool) $item->is_active;
        $this->formImagePath = $item->image_path;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manageCatalog', Quote::class);

        $this->validate([
            'formCategory' => ['required', 'string', 'max:80'],
            'formDescription' => ['required', 'string', 'max:500'],
            'formUnit' => ['required', Rule::enum(Unit::class)],
            'formRate' => ['nullable', 'string', 'max:20'],
            'formCost' => ['nullable', 'string', 'max:20'],
            'formFinish' => ['nullable', Rule::in(array_keys(self::FINISHES))],
            'formLine' => ['nullable', Rule::enum(BusinessLine::class)],
            'formActive' => ['boolean'],
            'formImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [], [
            'formCategory' => 'category', 'formDescription' => 'description', 'formUnit' => 'unit',
            'formFinish' => 'finish', 'formLine' => 'line',
        ]);

        $rate = $this->money('formRate', $this->formRate);
        $cost = trim($this->formCost) === '' ? null : $this->money('formCost', $this->formCost);

        if ($rate === null || ($cost === null && trim($this->formCost) !== '')) {
            return;
        }

        $values = [
            'category' => trim($this->formCategory),
            'description' => trim($this->formDescription),
            'image_path' => $this->resolveImagePath(),
            'unit' => $this->formUnit,
            'rate_minor' => $rate,
            'internal_cost_minor' => $cost,
            'finish' => $this->formFinish !== '' ? $this->formFinish : null,
            'business_line' => $this->formLine !== '' ? $this->formLine : null,
            'is_active' => $this->formActive,
        ];

        if ($this->editingId !== null) {
            CatalogItem::query()->findOrFail($this->editingId)->update($values);
            $this->toast('Rate book item updated. Existing quotations keep their rates.');
        } else {
            CatalogItem::create($values + ['position' => (int) CatalogItem::query()->max('position') + 1]);
            $this->toast('Rate book item added.');
        }

        $this->showForm = false;
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('manageCatalog', Quote::class);
        $item = CatalogItem::query()->findOrFail($id);
        $item->update(['is_active' => ! $item->is_active]);
        $this->toast($item->is_active ? 'Item is available in the builder again.' : 'Item hidden from the builder.');
    }

    public function render(): View
    {
        $term = trim($this->search);

        $items = CatalogItem::query()
            ->when($this->state === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($this->state === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->when($this->category !== '', fn (Builder $q) => $q->where('category', $this->category))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('description', 'like', "%{$term}%")->orWhere('category', 'like', "%{$term}%")))
            ->orderBy('category')
            ->orderBy('position')
            ->paginate(50);

        return view('livewire.admin.sales.rate-book', [
            'items' => $items,
            'categories' => CatalogItem::query()->distinct()->orderBy('category')->pluck('category'),
            'units' => Unit::options(),
            'finishes' => self::FINISHES,
            'lines' => BusinessLine::options(),
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }

    private function money(string $field, string $value): ?int
    {
        if (trim($value) === '') {
            return 0;
        }

        try {
            return NumberInput::rupees($value);
        } catch (InvalidArgumentException $exception) {
            $this->addError($field, $exception->getMessage());

            return null;
        }
    }

    /**
     * A new upload wins; an explicit remove clears; otherwise the stored picture is kept —
     * so editing a rate never silently drops the photo quotations already show.
     */
    private function resolveImagePath(): ?string
    {
        if ($this->formImage !== null) {
            return $this->formImage->store('catalog', 'public');
        }

        if ($this->formRemoveImage) {
            return null;
        }

        return $this->formImagePath;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'formCategory', 'formDescription', 'formUnit', 'formRate', 'formCost', 'formFinish', 'formLine', 'formActive', 'formImage', 'formImagePath', 'formRemoveImage']);
        $this->resetValidation();
    }
}
