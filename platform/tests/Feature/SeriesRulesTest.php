<?php

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Series;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\SeriesContent;
use App\Livewire\Admin\Commerce\SeriesEditor;
use App\Livewire\Admin\Commerce\SeriesManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->editor = $this->userWithRole('admin');
    $this->actingAs($this->editor);
    $this->service = app(SeriesContent::class);
    $this->series = Series::firstOrFail();
    $this->root = Category::create(['name' => 'Rule root', 'slug' => 'rule-root', 'is_active' => true]);
    $this->child = Category::create(['name' => 'Rule child', 'slug' => 'rule-child', 'parent_id' => $this->root->id, 'is_active' => false]);
    $this->rules = array_replace($this->service->defaults($this->series), ['membership_mode' => 'automatic', 'rule_category_id' => $this->root->id, 'rule_hot' => true, 'rule_featured' => true, 'rule_sort' => 'name', 'rule_limit' => 2]);
});

function ruleProduct($test, string $slug, string $name, array $extra = [], bool $published = true)
{
    $service = app(ProductCatalogue::class);
    $product = $service->save(null, array_replace($service->defaults(), ['slug' => $slug, 'name' => $name, 'category_ids' => [$test->child->id], 'primary_category_id' => $test->child->id, 'is_hot' => true, 'is_featured' => true], $extra), 0, $test->editor);
    if ($published) {
        $product->update(['published_revision_id' => DB::table('commerce_product_revisions')->where('product_id', $product->id)->value('id')]);
    }

    return $product;
}

it('creates inactive collection identity plus initial draft atomically with no product or menu activation', function () {
    Livewire::test(SeriesManager::class)->call('openCreate')->set('identity.name', 'Reviewed collection')->set('identity.slug', 'REVIEWED-COLLECTION')->call('createCollection')->assertHasNoErrors();
    $series = Series::where('slug', 'reviewed-collection')->sole();
    expect($series->is_active)->toBeFalse()->and($series->draft_revision_id)->not->toBeNull()->and($series->published_revision_id)->toBeNull()->and($series->content_version)->toBe(1);
    Livewire::test(SeriesManager::class)->set('identity', ['name' => 'Duplicate', 'slug' => 'reviewed-collection'])->call('createCollection')->assertHasErrors('slug');
    Livewire::test(SeriesManager::class)->set('identity', ['name' => ' ', 'slug' => '../bad'])->call('createCollection')->assertHasErrors(['name', 'slug']);
    Livewire::test(SeriesManager::class)->set('identity', ['name' => ['forged'], 'slug' => ['forged']])->call('createCollection')->assertHasErrors(['name', 'slug']);
    $this->getJson('/api/v1/catalogue/categories')->assertOk()->assertJsonMissing(['id' => 'reviewed-collection']);
    $this->actingAs($this->userWithRole('sales'));
    Livewire::test(SeriesManager::class)->call('openCreate')->assertForbidden();
    Livewire::test(SeriesManager::class)->set('identity', ['name' => 'Forged', 'slug' => 'forged'])->call('createCollection')->assertForbidden();
    expect(Series::where('slug', 'forged')->exists())->toBeFalse();
});

it('matches all automatic conditions against published descendant assignments with stable ordering and limits', function () {
    $z = ruleProduct($this, 'rule-z', 'Zulu');
    $a = ruleProduct($this, 'rule-a', 'Alpha');
    $a2 = ruleProduct($this, 'rule-a2', 'Alpha');
    ruleProduct($this, 'rule-not-hot', 'A hidden by flag', ['is_hot' => false]);
    ruleProduct($this, 'rule-private', 'A private draft', [], false);
    ruleProduct($this, 'rule-wrong-branch', 'A different branch', ['category_ids' => [], 'primary_category_id' => null]);
    $archived = ruleProduct($this, 'rule-archived', 'A archived');
    $archived->update(['archived_at' => now()]);
    expect(array_column($this->service->products($this->rules), 'id'))->toBe([$a->id, $a2->id]);
    app(ProductCatalogue::class)->save($z, array_replace($z->draft, ['name' => 'A private rename', 'is_hot' => false]), $z->version, $this->editor);
    $all = $this->service->products(array_replace($this->rules, ['rule_limit' => 100]));
    expect(array_column($all, 'name'))->toBe(['Alpha', 'Alpha', 'Zulu']);
});

it('invalidates exact preview approval when automatic matching publications change and handles withdrawal safely', function () {
    $first = ruleProduct($this, 'rule-first', 'First');
    $id = $this->service->save($this->series, $this->rules, 0, $this->editor);
    $this->get(route('admin.commerce.series-content.preview', $id))->assertOk()->assertSee('First');
    ruleProduct($this, 'rule-second', 'Second');
    expect(fn () => $this->service->publish($this->series->fresh(), $id, 1, $this->editor))->toThrow(ValidationException::class);
    $this->get(route('admin.commerce.series-content.preview', $id))->assertOk()->assertSee('Second');
    $this->service->publish($this->series->fresh(), $id, 1, $this->editor);
    $first->update(['published_revision_id' => null]);
    $this->actingAs($this->userWithRole('sales'))->get(route('admin.commerce.series-content.preview', $id))->assertOk()->assertDontSee('>First<', false)->assertSee('Second');
});

it('validates allowlisted rules and never broadens a removed category to all products', function () {
    ruleProduct($this, 'rule-visible', 'Visible');
    foreach ([['membership_mode' => 'sql'], ['rule_sort' => 'price'], ['rule_limit' => 101], ['rule_hot' => 'yes'], ['rule_category_id' => 999999]] as $invalid) {
        expect(fn () => $this->service->validate(array_replace($this->rules, $invalid)))->toThrow(ValidationException::class);
    }
    expect($this->service->products(array_replace($this->rules, ['rule_category_id' => 999999])))->toBe([]);
    $component = Livewire::test(SeriesEditor::class, ['series' => $this->series])->set('form', $this->rules)->call('saveDraft')->assertHasNoErrors()->assertSee('Automatic published-product rules');
    expect($this->series->fresh()->published_revision_id)->toBeNull();
});

it('loads and publishes legacy manual revisions without adding fields to immutable history', function () {
    $product = ruleProduct($this, 'rule-legacy', 'Legacy manual product');
    $legacy = ['heading' => 'Legacy collection', 'intro' => '', 'seo_title' => 'Legacy title', 'seo_description' => '', 'product_ids' => [$product->id]];
    $id = DB::table('series_content_revisions')->insertGetId(['series_id' => $this->series->id, 'content' => json_encode($legacy), 'created_by' => $this->editor->id, 'created_at' => now()]);
    // Compare persisted snapshots: MySQL JSON normalizes object key order.
    $persisted = DB::table('series_content_revisions')->where('id', $id)->value('content');
    $this->series->update(['draft_revision_id' => $id, 'content_version' => 1]);
    $component = Livewire::test(SeriesEditor::class, ['series' => $this->series->fresh()])->assertSet('form.membership_mode', 'manual');
    $this->get(route('admin.commerce.series-content.preview', $id))->assertOk()->assertSee('Legacy manual product');
    $component->set('confirmPublish', true)->call('publish')->assertHasNoErrors();
    expect(DB::table('series_content_revisions')->where('id', $id)->value('content'))->toBe($persisted);
});
