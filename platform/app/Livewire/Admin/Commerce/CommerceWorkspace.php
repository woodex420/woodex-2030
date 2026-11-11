<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Commerce;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\Series;
use App\Livewire\Concerns\InteractsWithAdmin;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts::admin')]
final class CommerceWorkspace extends Component
{
    use InteractsWithAdmin;

    #[Locked]
    public string $section = 'Commerce Overview';

    public function mount(): void
    {
        abort_unless($this->user()->can('commerce.catalog.view'), 403);
        $this->section = match (request()->route()?->getName()) {
            'admin.commerce.shop' => 'Shop customization', 'admin.commerce.series' => 'Series', default => 'Commerce Overview'
        };
    }

    public function render()
    {
        abort_unless($this->user()->can('commerce.catalog.view'), 403);

        return view('livewire.admin.commerce.workspace', ['counts' => ['Native products' => Product::count(), 'Published native products' => Product::whereNotNull('published_revision_id')->count(), 'Categories' => Category::count(), 'Series collections' => Series::count()]]);
    }
}
