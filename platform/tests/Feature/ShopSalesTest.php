<?php

use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\ProductMerchandising;
use App\Domain\Commerce\Services\ShopContent;
use App\Livewire\Admin\Commerce\ShopEditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->editor = $this->userWithRole('admin');
    $this->actingAs($this->editor);
    $this->service = app(ShopContent::class);
    $this->content = array_replace($this->service->defaults(), ['sections' => [array_replace($this->service->section(), ['heading' => 'Active native discounts', 'type' => 'sale'])]]);
});
afterEach(fn () => CarbonImmutable::setTestNow());

function shopSaleProduct($test, string $slug, array $extra = [], bool $publish = true)
{
    $catalogue = app(ProductCatalogue::class);
    $product = $catalogue->save(null, array_replace($catalogue->defaults(), ['name' => $slug, 'slug' => $slug, 'price' => '1000.00', 'sale_price' => '900.00', 'sale_start' => '2026-11-12T10:00', 'sale_end' => '2026-11-12T12:00'], $extra), 0, $test->editor);
    if ($publish) {
        $product->update(['published_revision_id' => DB::table('commerce_product_revisions')->where('product_id', $product->id)->value('id')]);
    }

    return $product;
}

it('evaluates inclusive UTC start and exclusive end from public base or explicit variant rows without rewrites', function () {
    $base = shopSaleProduct($this, 'base-sale');
    $variant = shopSaleProduct($this, 'variant-sale', ['sale_price' => '', 'sale_start' => '', 'sale_end' => '', 'variants' => [['sku' => 'EXPLICIT', 'price' => '2000.00', 'sale_price' => '0', 'sale_start' => '2026-11-12T10:00', 'sale_end' => '2026-11-12T12:00', 'availability' => 'on_request']]]);
    shopSaleProduct($this, 'private-sale', [], false);
    $archived = shopSaleProduct($this, 'archived-sale');
    $archived->update(['archived_at' => now()]);
    $at = fn ($time) => CarbonImmutable::parse('2026-11-12 '.$time, 'UTC');
    expect($this->service->project($this->content, $at('09:59:59'))[0]['products'])->toBe([]);
    expect(array_column($this->service->project($this->content, $at('10:00:00'))[0]['products'], 'id'))->toBe([$base->id, $variant->id]);
    expect($this->service->project($this->content, $at('12:00:00'))[0]['products'])->toBe([]);
    expect(app(ProductMerchandising::class)->isOnSale(['price' => '2000.00'], $at('10:00:00')))->toBeFalse();
    app(ProductCatalogue::class)->save($base, array_replace($base->draft, ['sale_price' => '', 'sale_start' => '', 'sale_end' => '']), $base->version, $this->editor);
    expect(array_column($this->service->project($this->content, $at('11:00:00'))[0]['products'], 'id'))->toBe([$base->id, $variant->id]);
});

it('expires publisher preview approval only when rendered sale membership changes at a boundary', function () {
    shopSaleProduct($this, 'scheduled-sale');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 11:00:00', 'UTC'));
    $id = $this->service->save($this->content, 0, $this->editor);
    $this->get(route('admin.commerce.shop-content.preview', $id))->assertOk()->assertSee('scheduled-sale');
    $first = DB::table('shop_content_previews')->where('revision_id', $id)->value('context_hash');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 11:01:00', 'UTC'));
    expect($this->service->fingerprint($this->service->validate($this->content)))->toBe($first);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 12:00:00', 'UTC'));
    expect(fn () => $this->service->publish($id, 1, $this->editor))->toThrow(ValidationException::class);
    $this->get(route('admin.commerce.shop-content.preview', $id))->assertOk()->assertDontSee('scheduled-sale')->assertSee('No currently eligible published products');
    $this->service->publish($id, 1, $this->editor);
});

it('sorts before limiting with deterministic name and published featured-position ties and preserves manual source moves', function () {
    $z = shopSaleProduct($this, 'zulu-sale', ['featured_position' => 8]);
    $a = shopSaleProduct($this, 'alpha-sale', ['featured_position' => 1]);
    $a2 = shopSaleProduct($this, 'another-sale', ['name' => 'alpha-sale', 'featured_position' => 1]);
    $manual = array_replace($this->content, ['sections' => [array_replace($this->service->section(), ['heading' => 'Curated', 'type' => 'manual', 'product_ids' => [$z->id, $a2->id, $a->id], 'sort' => 'name', 'limit' => 2])]]);
    expect(array_column($this->service->project($manual)[0]['products'], 'id'))->toBe([$a->id, $a2->id]);
    $manual['sections'][0]['sort'] = 'featured';
    expect(array_column($this->service->project($manual)[0]['products'], 'id'))->toBe([$a->id, $a2->id]);
    $manual['sections'][0]['sort'] = 'source';
    Livewire::test(ShopEditor::class)->set('form', $manual)->call('moveProduct', 0, 2, -1)->assertSet('form.sections.0.product_ids', [$z->id, $a->id, $a2->id])->call('saveDraft')->assertHasNoErrors();
    $this->actingAs($this->userWithRole('sales'));
    Livewire::test(ShopEditor::class)->call('moveProduct', 0, 0, 1)->assertForbidden();
});

it('normalizes legacy source order without rewriting revisions and rejects unallowlisted sorts', function () {
    $product = shopSaleProduct($this, 'legacy-sort');
    $legacy = array_replace($this->service->defaults(), ['sections' => [array_replace($this->service->section(), ['heading' => 'Legacy', 'product_ids' => [$product->id]])]]);
    unset($legacy['sections'][0]['sort']);
    $id = DB::table('shop_content_revisions')->insertGetId(['content' => json_encode($legacy), 'created_by' => $this->editor->id, 'created_at' => now()]);
    // Preserve exact stored history, independent of MySQL JSON key ordering.
    $persisted = $this->service->revision($id)->content;
    DB::table('commerce_shop_state')->where('id', 1)->update(['draft_revision_id' => $id, 'version' => 1]);
    $component = Livewire::test(ShopEditor::class)->assertSet('form.sections.0.sort', 'source');
    $this->get(route('admin.commerce.shop-content.preview', $id))->assertOk();
    $component->set('confirmPublish', true)->call('publish')->assertHasNoErrors();
    expect($this->service->revision($id)->content)->toBe($persisted);
    $legacy['sections'][0]['sort'] = 'price-sql';
    expect(fn () => $this->service->validate($legacy))->toThrow(ValidationException::class);
});
