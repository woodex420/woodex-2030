<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Commerce;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\Series;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts::admin')]
final class CommerceWorkspace extends Component
{
    use InteractsWithAdmin;

    #[Locked]
    public string $section = 'E-commerce';

    public function mount(): void
    {
        abort_unless($this->user()->can('commerce.catalog.view'), 403);
        $this->section = match (request()->route()?->getName()) {
            'admin.commerce.shop' => 'Shop customization', 'admin.commerce.series' => 'Series', default => 'E-commerce'
        };
    }

    public function render()
    {
        abort_unless($this->user()->can('commerce.catalog.view'), 403);

        $products = Product::whereNull('archived_at')->when(! $this->user()->can('commerce.catalog.manage'), fn ($q) => $q->whereNotNull('published_revision_id'))->latest('updated_at')->limit(6)->get();
        if (! $this->user()->can('commerce.catalog.manage')) {
            foreach ($products as $product) {
                $revision = DB::table('commerce_product_revisions')->where('product_id', $product->id)->find($product->published_revision_id);
                if ($revision) {
                    $data = json_decode($revision->content, true);
                    $product->draft = $data;
                    $product->name = $data['name'];
                }
            }
        }

        return view('livewire.admin.commerce.workspace', ['recentProducts' => $products, 'counts' => ['Native products' => Product::whereNull('archived_at')->count(), 'Published native products' => Product::whereNull('archived_at')->whereNotNull('published_revision_id')->count(), 'Categories' => Category::count(), 'Series collections' => Series::count()]]);
    }
}
