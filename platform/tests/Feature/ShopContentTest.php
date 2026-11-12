<?php

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Series;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\SeriesContent;
use App\Domain\Commerce\Services\ShopContent;
use App\Livewire\Admin\Commerce\ShopEditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->editor = $this->userWithRole('admin');
    $this->actingAs($this->editor);
    $this->service = app(ShopContent::class);
    $this->root = Category::create(['name' => 'Shop root', 'slug' => 'shop-root']);
    $this->child = Category::create(['name' => 'Shop child', 'slug' => 'shop-child', 'parent_id' => $this->root->id]);
    $catalogue = app(ProductCatalogue::class);
    $this->product = $catalogue->save(null, array_replace($catalogue->defaults(), ['name' => 'Published Shop chair', 'slug' => 'published-shop-chair', 'category_ids' => [$this->child->id], 'primary_category_id' => $this->child->id, 'is_hot' => true, 'is_featured' => true]), 0, $this->editor);
    $this->product->update(['published_revision_id' => DB::table('commerce_product_revisions')->where('product_id', $this->product->id)->value('id')]);
    $this->content = array_replace($this->service->defaults(), ['sections' => [array_replace($this->service->section(), ['heading' => 'Actual manual products', 'product_ids' => [$this->product->id]])]]);
});

it('saves private immutable configuration and requires exact user preview and explicit publication confirmation', function () {
    $component = Livewire::test(ShopEditor::class)->set('form', $this->content)->call('saveDraft')->assertHasNoErrors();
    $id = $this->service->state()->draft_revision_id;
    $component->call('publish')->assertHasErrors('publish')->set('confirmPublish', true)->call('publish')->assertHasErrors('publish');
    $this->get(route('admin.commerce.shop-content.preview', $id))->assertOk()->assertSee('Published Shop chair')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect(fn () => $this->service->publish($id, 1, $this->userWithRole('admin')))->toThrow(ValidationException::class);
    $component->set('form.heading', 'Unsaved Shop change')->call('publish')->assertHasErrors('publish')->set('form.heading', $this->content['heading'])->call('publish')->assertHasNoErrors()->assertSet('version', 2);
    $component->set('form.heading', 'Confidential Shop draft')->call('saveDraft')->assertHasNoErrors();
    expect((int) $this->service->state()->published_revision_id)->toBe($id)->and(json_decode($this->service->revision($id)->content, true)['heading'])->toBe('Shop');
    $private = $this->service->state()->draft_revision_id;
    $this->actingAs($this->userWithRole('sales'));
    Livewire::test(ShopEditor::class)->assertSet('form', [])->assertSet('revisionId', null)->assertDontSee('Confidential Shop draft')->call('addSection')->assertForbidden();
    $this->get(route('admin.commerce.shop-content.preview', $private))->assertForbidden();
    $this->get(route('admin.commerce.shop-content.preview', $id))->assertOk()->assertDontSee('Confidential Shop draft');
});

it('projects category descendants flags and owned published Series without matching newer private product or collection drafts', function () {
    $series = Series::firstOrFail();
    $collections = app(SeriesContent::class);
    $seriesId = $collections->save($series, array_replace($collections->defaults($series), ['product_ids' => [$this->product->id]]), 0, $this->editor);
    $this->get(route('admin.commerce.series-content.preview', $seriesId))->assertOk();
    $collections->publish($series->fresh(), $seriesId, 1, $this->editor);
    $collections->save($series->fresh(), array_replace($collections->defaults($series), ['heading' => 'Private Series heading', 'product_ids' => []]), 2, $this->editor);
    app(ProductCatalogue::class)->save($this->product, array_replace($this->product->draft, ['name' => 'Private product name', 'is_hot' => false, 'is_featured' => false, 'category_ids' => [], 'primary_category_id' => null]), $this->product->version, $this->editor);
    $sections = [];
    foreach (['category', 'hot', 'featured', 'series'] as $type) {
        $sections[] = array_replace($this->service->section(), ['heading' => $type, 'type' => $type, 'category_id' => $this->root->id, 'series_id' => $series->id]);
    }
    $content = $this->service->validate(array_replace($this->content, ['sections' => $sections]));
    foreach ($this->service->project($content) as $section) {
        expect(array_column($section['products'], 'name'))->toBe(['Published Shop chair']);
    }
    $this->product->update(['archived_at' => now()]);
    foreach ($this->service->project($content) as $section) {
        expect($section['products'])->toBe([]);
    }
});

it('invalidates approval when displayed product publication changes and retains history on withdrawal', function () {
    $id = $this->service->save($this->content, 0, $this->editor);
    $this->get(route('admin.commerce.shop-content.preview', $id))->assertOk();
    $product = app(ProductCatalogue::class)->save($this->product, array_replace($this->product->draft, ['name' => 'Updated public chair']), $this->product->version, $this->editor);
    $product->update(['published_revision_id' => DB::table('commerce_product_revisions')->where('product_id', $product->id)->max('id')]);
    expect(fn () => $this->service->publish($id, 1, $this->editor))->toThrow(ValidationException::class);
    $this->get(route('admin.commerce.shop-content.preview', $id))->assertOk()->assertSee('Updated public chair');
    $this->service->publish($id, 1, $this->editor);
    Livewire::test(ShopEditor::class)->call('withdraw')->assertHasErrors('publish')->set('confirmWithdraw', true)->call('withdraw')->assertHasNoErrors();
    expect($this->service->state()->published_revision_id)->toBeNull()->and($this->service->revision($id)->id)->toBe($id);
});

it('orders removes and clears sections and rejects stale tabs and invalid nested configuration', function () {
    $first = Livewire::test(ShopEditor::class);
    $stale = Livewire::test(ShopEditor::class);
    $first->call('addSection')->set('form.sections.0.heading', 'First')->call('addSection')->set('form.sections.1.heading', 'Second')
        ->call('moveSection', 1, -1)->assertSet('form.sections.0.heading', 'Second')->call('removeSection', 1)->call('clearProducts', 0)->call('saveDraft')->assertHasNoErrors();
    $stale->call('saveDraft')->assertHasErrors('conflict');
    foreach ([['type' => 'sql'], ['limit' => 25], ['product_ids' => [$this->product->id, $this->product->id]], ['product_ids' => [999999]], ['type' => 'category', 'category_id' => 999999], ['type' => 'series', 'series_id' => 999999]] as $invalid) {
        expect(fn () => $this->service->validate(array_replace($this->content, ['sections' => [array_replace($this->content['sections'][0], $invalid)]])))->toThrow(ValidationException::class);
    }
});

it('supports empty safe-text drafts and denies publication after permission revocation', function () {
    $id = $this->service->save(array_replace($this->service->defaults(), ['heading' => '<script>unsafe</script>']), 0, $this->editor);
    $this->get(route('admin.commerce.shop-content.preview', $id))->assertOk()->assertDontSee('<script>', false)->assertSee('&lt;script&gt;', false)->assertSee('No Shop sections configured');
    $this->editor->roles->each(fn ($role) => $role->revokePermissionTo('commerce.catalog.publish'));
    $this->actingAs($this->editor->fresh());
    Livewire::test(ShopEditor::class)->set('confirmPublish', true)->call('publish')->assertForbidden();
});

it('fails closed on missing or foreign collection sources and bounds section counts without changing publication', function () {
    $series = Series::firstOrFail();
    $other = Series::where('id', '!=', $series->id)->firstOrFail();
    $collections = app(SeriesContent::class);
    $foreign = $collections->save($other, array_replace($collections->defaults($other), ['product_ids' => [$this->product->id]]), 0, $this->editor);
    $series->update(['published_revision_id' => $foreign]);
    $invalid = array_replace($this->service->section(), ['heading' => 'Foreign Series', 'type' => 'series', 'series_id' => $series->id]);
    expect($this->service->project(array_replace($this->content, ['sections' => [$invalid]]))[0]['products'])->toBe([]);
    expect(fn () => $this->service->validate(array_replace($this->content, ['sections' => [$invalid]])))->toThrow(ValidationException::class);
    expect(fn () => $this->service->validate(array_replace($this->content, ['sections' => array_fill(0, 13, $this->content['sections'][0])])))->toThrow(ValidationException::class);
    $valid = $this->service->validate(array_replace($this->content, ['sections' => [array_replace($this->content['sections'][0], ['untrusted_url' => 'https://example.org', 'unexpected' => ['bad']])]]));
    expect($valid['sections'][0])->not->toHaveKeys(['untrusted_url', 'unexpected'])->and($this->service->state()->published_revision_id)->toBeNull();
});
