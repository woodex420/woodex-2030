<?php

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Services\CategoryImpact;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Livewire\Admin\Commerce\Categories;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->editor = $this->userWithRole('admin');
    $this->actingAs($this->editor);
    $this->root = Category::create(['name' => 'Impact root', 'slug' => 'impact-root', 'is_active' => true]);
    $this->leaf = Category::create(['name' => 'Impact leaf', 'slug' => 'impact-leaf', 'parent_id' => $this->root->id, 'is_active' => true]);
    $this->destination = Category::create(['name' => 'Inactive destination', 'slug' => 'impact-destination', 'is_active' => false]);
    $service = app(ProductCatalogue::class);
    $this->data = array_replace($service->defaults(), ['name' => 'Impact product', 'slug' => 'impact-product', 'category_ids' => [$this->root->id, $this->leaf->id], 'primary_category_id' => $this->leaf->id]);
    $this->product = $service->save(null, $this->data, 0, $this->editor);
    $this->product->update(['published_revision_id' => DB::table('commerce_product_revisions')->where('product_id', $this->product->id)->value('id')]);
});

it('counts distinct branch products and separates saved drafts from owned public snapshots', function () {
    $service = app(CategoryImpact::class);
    $counts = $service->counts(Category::all(), true);
    expect($counts[$this->root->id])->toBe(['direct_published' => 1, 'branch_published' => 1, 'direct_draft' => 1, 'branch_draft' => 1]);
    app(ProductCatalogue::class)->save($this->product, array_replace($this->data, ['category_ids' => [$this->destination->id], 'primary_category_id' => $this->destination->id]), $this->product->version, $this->editor);
    $this->actingAs($this->userWithRole('sales'));
    Livewire::test(Categories::class)->assertDontSee('Saved draft products:')->assertViewHas('categoryCounts', fn ($counts) => $counts[$this->root->id] === ['direct_published' => 1, 'branch_published' => 1] && $counts[$this->destination->id]['branch_published'] === 0)
        ->call('previewMove')->assertForbidden();
    $this->product->update(['archived_at' => now()]);
    expect($service->counts(Category::all(), true)[$this->root->id]['branch_published'])->toBe(0);
});

it('requires a preview and explicit confirmation then retains assignments and publication while moving the branch', function () {
    $draft = $this->product->fresh()->draft;
    $pointer = $this->product->published_revision_id;
    $component = Livewire::test(Categories::class)->call('edit', $this->root->id)->set('form.parent_id', $this->destination->id)
        ->call('save')->assertHasErrors('move')->call('previewMove')->assertSet('moveImpact.descendants', 1)->assertSet('moveImpact.draft_products', 1)
        ->assertSet('moveImpact.published_products', 1)->assertSet('moveImpact.inactive_destination', true)->assertSee('Inactive destination')
        ->call('save')->assertHasErrors('move')->set('confirmMove', true)->call('save')->assertHasNoErrors()->assertSet('showForm', false);
    expect($this->root->fresh()->parent_id)->toBe($this->destination->id)->and($this->leaf->fresh()->parent_id)->toBe($this->root->id)
        ->and($this->product->fresh()->draft)->toBe($draft)->and($this->product->fresh()->published_revision_id)->toBe($pointer);
    Livewire::test(Categories::class)->call('edit', $this->root->id)->set('form.parent_id', '')->call('previewMove')->set('confirmMove', true)->call('save')->assertHasNoErrors();
    expect($this->root->fresh()->parent_id)->toBeNull();
});

it('invalidates confirmation when the destination or assignment count changes', function () {
    $component = Livewire::test(Categories::class)->call('edit', $this->root->id)->set('form.parent_id', $this->destination->id)->call('previewMove')->set('confirmMove', true);
    app(ProductCatalogue::class)->save(null, array_replace($this->data, ['slug' => 'another-impact-product']), 0, $this->editor);
    $component->call('save')->assertHasErrors('move')->call('previewMove')->assertSet('confirmMove', false)->assertSet('moveImpact.draft_products', 2)
        ->set('confirmMove', true)->set('form.parent_id', '')->assertSet('confirmMove', false)->assertSet('moveImpact', []);
    expect($this->root->fresh()->parent_id)->toBeNull();
});

it('rejects stale quick edits and stale moves without partial tree writes', function () {
    $stale = Livewire::test(Categories::class)->call('edit', $this->root->id)->set('form.parent_id', $this->destination->id)->call('previewMove')->set('confirmMove', true);
    Livewire::test(Categories::class)->call('edit', $this->root->id)->set('form.name', 'Changed elsewhere')->call('save')->assertHasNoErrors();
    $stale->call('save')->assertHasErrors('tree');
    expect($this->root->fresh()->name)->toBe('Changed elsewhere')->and($this->root->fresh()->parent_id)->toBeNull();
    $stale->call('previewMove')->assertHasErrors('tree');
});

it('excludes descendants from destinations and rejects forged cyclic destinations', function () {
    Livewire::test(Categories::class)->call('edit', $this->root->id)->assertViewHas('excludedParents', fn ($ids) => in_array($this->leaf->id, $ids, true))
        ->set('form.parent_id', $this->leaf->id)->call('previewMove')->assertHasErrors('form.parent_id')->set('confirmMove', true)->call('save')->assertHasErrors('form.parent_id');
    expect($this->root->fresh()->parent_id)->toBeNull();
});

it('ignores foreign publication pointers in public counts', function () {
    $other = app(ProductCatalogue::class)->save(null, array_replace($this->data, ['slug' => 'foreign-impact-product', 'category_ids' => [$this->destination->id], 'primary_category_id' => $this->destination->id]), 0, $this->editor);
    $other->update(['published_revision_id' => $this->product->published_revision_id]);
    expect(app(CategoryImpact::class)->counts(Category::all(), false)[$this->root->id]['branch_published'])->toBe(1);
});
