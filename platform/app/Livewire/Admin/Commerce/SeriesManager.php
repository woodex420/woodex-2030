<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Commerce;

use App\Domain\Commerce\Models\Series;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts::admin')]
final class SeriesManager extends Component
{
    use InteractsWithAdmin;

    public function mount(): void
    {
        abort_unless($this->user()->can('commerce.catalog.view'), 403);
    }

    public function reorder(int $id, int $target): void
    {
        abort_unless($this->user()->can('commerce.catalog.view') && $this->user()->can('commerce.catalog.manage'), 403);
        DB::transaction(function () use ($id, $target): void {
            $rows = Series::orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $ids = $rows->pluck('id')->all();
            abort_unless(in_array($id, $ids, true) && in_array($target, $ids, true), 422);
            $from = array_search($id, $ids, true);
            $to = array_search($target, $ids, true);
            array_splice($ids, $from, 1);
            array_splice($ids, $to, 0, [$id]);
            foreach ($ids as $position => $key) {
                $rows->find($key)->forceFill(['position' => $position])->save();
            }
            activity('commerce')->causedBy($this->user())->withProperties(['ordered_ids' => $ids])->log('Series collections reordered');
        });
    }

    public function render()
    {
        abort_unless($this->user()->can('commerce.catalog.view'), 403);
        $references = json_decode(file_get_contents(resource_path('data/catalogue-reference.json')), true, flags: JSON_THROW_ON_ERROR)['items'];

        return view('livewire.admin.commerce.series-manager', ['collections' => Series::orderBy('position')->orderBy('id')->get(), 'references' => $references]);
    }
}
