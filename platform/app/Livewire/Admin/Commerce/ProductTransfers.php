<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Commerce;

use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\ProductTransfer;
use App\Livewire\Concerns\InteractsWithAdmin;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts::admin')]
final class ProductTransfers extends Component
{
    use InteractsWithAdmin, WithFileUploads;

    public $package = null;

    #[Locked]
    public string $packageHash = '';

    #[Locked]
    public array $summary = [];

    public bool $confirm = false;

    public function mount(): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
    }

    public function previewPackage(): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $this->validate(['package' => ['required', 'file', 'max:25600']]);
        $json = file_get_contents($this->package->getRealPath());
        $data = app(ProductTransfer::class)->parsePackage($json);
        $this->packageHash = hash('sha256', $json);
        $this->summary = ['total' => count($data['items']), 'new' => count(array_filter($data['items'], fn ($r) => ! $r['skip'])), 'assets' => count($data['assets'])];
        $this->confirm = false;
    }

    public function importPackage(): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $this->validate(['package' => ['required', 'file', 'max:25600']]);
        $json = file_get_contents($this->package->getRealPath());
        if (! $this->confirm || ! $this->packageHash || ! hash_equals($this->packageHash, hash('sha256', $json))) {
            $this->addError('import', 'Preview this exact package and confirm the draft import first.');

            return;
        }
        $count = app(ProductTransfer::class)->importPackage($json, $this->user());
        $this->summary = [];
        $this->packageHash = '';
        $this->package = null;
        $this->confirm = false;
        $this->toast($count.' products imported as drafts. Existing records were skipped.');
    }

    public function importSource(): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        if (! $this->confirm) {
            $this->addError('import', 'Review the source audit and confirm the draft import.');

            return;
        }
        $count = app(ProductTransfer::class)->importSource($this->user());
        $this->confirm = false;
        $this->toast($count.' repository products imported as drafts, with review warnings.');
    }

    public function render()
    {
        app(ProductCatalogue::class)->authorize($this->user());

        return view('livewire.admin.commerce.product-transfers', ['source' => app(ProductTransfer::class)->sourceSummary(), 'sample' => array_slice(app(ProductTransfer::class)->source()['items'], 0, 5)]);
    }
}
