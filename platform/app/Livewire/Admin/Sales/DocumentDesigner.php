<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Sales\Actions\SaveDocumentDesign;
use App\Domain\Sales\Models\DocumentDesign;
use App\Domain\Sales\Services\DocumentStyles;
use App\Domain\Sales\Services\FurnitureDocument;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts::admin')]
#[Title('Document design')]
class DocumentDesigner extends Component
{
    use InteractsWithAdmin, WithFileUploads;

    public string $line = 'interior';

    #[Locked]
    public string $loadedLine = 'interior';

    #[Locked]
    public int $revision = 1;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<string, string> */
    public array $furnitureStyle = [];

    public ?TemporaryUploadedFile $logo = null;

    public bool $removeLogo = false;

    public string $pdfTheme = 'classic';

    /** @var list<array{key: string, visible: bool}> */
    public array $quoteBlocks = [];

    /** @var list<array{key: string, visible: bool}> */
    public array $invoiceBlocks = [];

    public function mount(): void
    {
        $this->authorize('settings.manage');
        $this->loadDesign();
    }

    public function updatedLine(): void
    {
        $this->authorize('settings.manage');
        $this->loadDesign();
    }

    public function save(): void
    {
        $this->authorize('settings.manage');
        $this->validate(['line' => ['required', 'in:interior,furniture'], 'form.brand_name' => ['required', 'string', 'max:35'], 'form.accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'form.header_text' => ['present', 'string', 'max:100'], 'form.footer_text' => ['present', 'string', 'max:120'], 'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:96', 'dimensions:min_width=8,min_height=8,max_width=600,max_height=240'], 'pdfTheme' => ['required', Rule::in(array_keys(DocumentStyles::THEMES))], 'quoteBlocks' => ['array', 'max:20'], 'quoteBlocks.*.key' => ['required', 'string', Rule::in(array_keys(DocumentStyles::QUOTE_BLOCKS))], 'quoteBlocks.*.visible' => ['required', 'boolean'], 'invoiceBlocks' => ['array', 'max:20'], 'invoiceBlocks.*.key' => ['required', 'string', Rule::in(array_keys(DocumentStyles::INVOICE_BLOCKS))], 'invoiceBlocks.*.visible' => ['required', 'boolean']]);
        abort_unless($this->line === $this->loadedLine, 409, 'Reload the selected brand before saving.');
        app(SaveDocumentDesign::class)->handle($this->user(), $this->loadedLine, [...$this->form, 'pdf_theme' => $this->pdfTheme, 'quote_blocks' => $this->quoteBlocks, 'invoice_blocks' => $this->invoiceBlocks, 'furniture_style' => $this->furnitureStyle], $this->revision, $this->logo, $this->removeLogo);
        $this->loadDesign();
        $this->toast('Design saved for new documents. Existing quotations keep their copied design.');
    }

    private function loadDesign(): void
    {
        abort_unless(in_array($this->line, ['interior', 'furniture'], true), 422);
        $design = DocumentDesign::query()->where('business_line', $this->line)->firstOrFail();
        $this->loadedLine = $this->line;
        $this->revision = $design->revision;
        $this->form = $design->only(['brand_name', 'accent_color', 'header_text', 'footer_text']);
        $this->furnitureStyle = FurnitureDocument::style($design->furniture_style);
        $this->pdfTheme = DocumentStyles::theme($design->pdf_theme ?? null);
        $this->quoteBlocks = $this->loadedLine === 'furniture' && $design->quote_blocks === null
            ? DocumentStyles::quoteLayoutBlocks('furniture', true)
            : DocumentStyles::blocks('quote', $design->quote_blocks, true);
        $this->invoiceBlocks = DocumentStyles::blocks('invoice', $design->invoice_blocks);
        $this->logo = null;
        $this->removeLogo = false;
        $this->resetValidation();
    }

    public function toggleBlock(string $kind, int $index): void
    {
        $this->authorize('settings.manage');
        $prop = $kind === 'invoice' ? 'invoiceBlocks' : 'quoteBlocks';
        if (isset($this->{$prop}[$index]) && ! ($kind === 'invoice' && in_array($this->{$prop}[$index]['key'], DocumentStyles::LOCKED_BLOCKS['invoice'], true))) {
            $this->{$prop}[$index]['visible'] = ! $this->{$prop}[$index]['visible'];
        }
    }

    public function moveBlock(string $kind, int $index, int $direction): void
    {
        $this->authorize('settings.manage');
        $prop = $kind === 'invoice' ? 'invoiceBlocks' : 'quoteBlocks';
        $target = $index + ($direction < 0 ? -1 : 1);

        if (! isset($this->{$prop}[$index], $this->{$prop}[$target])) {
            return;
        }

        [$this->{$prop}[$index], $this->{$prop}[$target]] = [$this->{$prop}[$target], $this->{$prop}[$index]];
        $this->{$prop} = array_values($this->{$prop});
    }

    public function resetBlocks(string $kind): void
    {
        $this->authorize('settings.manage');

        if ($kind === 'invoice') {
            $this->invoiceBlocks = DocumentStyles::blocks('invoice', null);
        } else {
            $this->quoteBlocks = DocumentStyles::quoteLayoutBlocks($this->loadedLine === 'furniture' ? 'furniture' : 'classic', true);
        }
    }

    public function render(): View
    {
        return view('livewire.admin.sales.document-designer', [
            'design' => DocumentDesign::query()->where('business_line', $this->loadedLine)->firstOrFail(),
            'themes' => DocumentStyles::THEMES,
            'themeDescriptions' => DocumentStyles::THEME_DESCRIPTIONS,
            'quoteBlockLabels' => DocumentStyles::QUOTE_BLOCKS,
            'invoiceBlockLabels' => DocumentStyles::INVOICE_BLOCKS,
            'lockedBlocks' => [...DocumentStyles::LOCKED_BLOCKS, 'quote' => []],
        ]);
    }
}
