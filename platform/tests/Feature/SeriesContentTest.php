<?php

use App\Domain\Commerce\Models\Series;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\SeriesContent;
use App\Livewire\Admin\Commerce\SeriesEditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->editor = $this->userWithRole('admin');
    $this->actingAs($this->editor);
    $this->series = Series::firstOrFail();
    $this->service = app(SeriesContent::class);
    $catalogue = app(ProductCatalogue::class);
    $draft = array_replace($catalogue->defaults(), ['name' => 'Public Series chair', 'slug' => 'series-public-chair']);
    $this->product = $catalogue->save(null, $draft, 0, $this->editor);
    $this->product->update(['published_revision_id' => DB::table('commerce_product_revisions')->where('product_id', $this->product->id)->value('id')]);
    $this->content = array_replace($this->service->defaults($this->series), ['product_ids' => [$this->product->id]]);
});

it('saves immutable private collection revisions without changing menu identity or product assignments', function () {
    $before = $this->product->draft;
    $slug = $this->series->slug;
    $component = Livewire::test(SeriesEditor::class, ['series' => $this->series])->set('form', $this->content)->call('saveDraft')->assertHasNoErrors()->assertSet('version', 1);
    $first = $this->series->fresh()->draft_revision_id;
    $component->set('form.heading', 'A newer private heading')->call('saveDraft')->assertHasNoErrors();
    expect($this->series->fresh()->published_revision_id)->toBeNull()->and($this->series->fresh()->slug)->toBe($slug)->and($this->product->fresh()->draft)->toBe($before)
        ->and(DB::table('series_content_revisions')->where('id', $first)->value('content'))->toContain($this->content['heading']);
    $this->actingAs($this->userWithRole('sales'))->get(route('admin.commerce.series-content.preview', $first))->assertForbidden();
});

it('requires exact saved preview per publisher and explicit confirmation before publication and withdrawal', function () {
    $component = Livewire::test(SeriesEditor::class, ['series' => $this->series])->set('form', $this->content)->call('saveDraft');
    $id = $this->series->fresh()->draft_revision_id;
    $component->call('publish')->assertHasErrors('publish')->set('confirmPublish', true)->call('publish')->assertHasErrors('publish');
    $this->get(route('admin.commerce.series-content.preview', $id))->assertOk()->assertSee('Public Series chair')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $other = $this->userWithRole('admin');
    expect(fn () => $this->service->publish($this->series->fresh(), $id, 1, $other))->toThrow(ValidationException::class);
    $component->set('form.heading', 'Unsaved heading')->call('publish')->assertHasErrors('publish')->set('form.heading', $this->content['heading'])->call('publish')->assertHasNoErrors();
    expect($this->series->fresh()->published_revision_id)->toBe($id);
    $component->call('withdraw')->assertHasErrors('publish')->set('confirmWithdraw', true)->call('withdraw')->assertHasNoErrors();
    expect($this->series->fresh()->published_revision_id)->toBeNull()->and(DB::table('series_content_revisions')->where('id', $id)->exists())->toBeTrue();
});

it('invalidates preview when a selected product publication changes and never renders a newer private product draft', function () {
    $id = $this->service->save($this->series, $this->content, 0, $this->editor);
    $this->get(route('admin.commerce.series-content.preview', $id))->assertOk();
    $catalogue = app(ProductCatalogue::class);
    $product = $catalogue->save($this->product, array_replace($this->product->draft, ['name' => 'Secret product heading']), $this->product->version, $this->editor);
    $this->get(route('admin.commerce.series-content.preview', $id))->assertOk()->assertDontSee('Secret product heading');
    $product->update(['published_revision_id' => DB::table('commerce_product_revisions')->where('product_id', $product->id)->max('id')]);
    expect(fn () => $this->service->publish($this->series->fresh(), $id, 1, $this->editor))->toThrow(ValidationException::class);
    $this->get(route('admin.commerce.series-content.preview', $id))->assertOk()->assertSee('Secret product heading');
    $this->service->publish($this->series->fresh(), $id, 1, $this->editor);
});

it('isolates publication from later private collection drafts and omits archived or withdrawn products for viewers', function () {
    $id = $this->service->save($this->series, $this->content, 0, $this->editor);
    $this->get(route('admin.commerce.series-content.preview', $id))->assertOk();
    $this->service->publish($this->series->fresh(), $id, 1, $this->editor);
    $privateId = $this->service->save($this->series->fresh(), array_replace($this->content, ['heading' => 'Confidential collection']), 2, $this->editor);
    $this->actingAs($this->userWithRole('sales'));
    $this->get(route('admin.commerce.series-content.preview', $privateId))->assertForbidden();
    $this->product->update(['published_revision_id' => null]);
    $this->get(route('admin.commerce.series-content.preview', $id))->assertOk()->assertDontSee('Confidential collection')->assertDontSee('Public Series chair')->assertSee('No currently eligible published products');
    Livewire::test(SeriesEditor::class, ['series' => $this->series])->assertForbidden();
});

it('rejects stale saves invalid memberships foreign revisions and preserves selected manual ordering', function () {
    $first = Livewire::test(SeriesEditor::class, ['series' => $this->series]);
    $stale = Livewire::test(SeriesEditor::class, ['series' => $this->series]);
    $first->call('saveDraft')->assertHasNoErrors();
    $stale->call('saveDraft')->assertHasErrors('conflict');
    foreach ([[$this->product->id, $this->product->id], [999999]] as $ids) {
        expect(fn () => $this->service->validate(array_replace($this->content, ['product_ids' => $ids])))->toThrow(ValidationException::class);
    }
    $this->product->update(['archived_at' => now()]);
    expect(fn () => $this->service->validate($this->content))->toThrow(ValidationException::class);
    $other = Series::where('id', '!=', $this->series->id)->firstOrFail();
    $this->expectException(NotFoundHttpException::class);
    $this->service->revision($other, $this->series->fresh()->draft_revision_id);
});

it('supports explicit empty states safe text and publication permission revocation', function () {
    $content = array_replace($this->content, ['heading' => '<script>alert(1)</script>', 'product_ids' => []]);
    $id = $this->service->save($this->series, $content, 0, $this->editor);
    $this->get(route('admin.commerce.series-content.preview', $id))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false)->assertSee('No currently eligible');
    $this->editor->roles->each(fn ($role) => $role->revokePermissionTo('commerce.catalog.publish'));
    $this->actingAs($this->editor->fresh());
    Livewire::test(SeriesEditor::class, ['series' => $this->series->fresh()])->set('confirmPublish', true)->call('publish')->assertForbidden();
});

it('orders and removes selected products and refuses publication of an older saved draft', function () {
    $catalogue = app(ProductCatalogue::class);
    $second = $catalogue->save(null, array_replace($catalogue->defaults(), ['name' => 'Second public chair', 'slug' => 'second-series-chair']), 0, $this->editor);
    $second->update(['published_revision_id' => DB::table('commerce_product_revisions')->where('product_id', $second->id)->value('id')]);
    Livewire::test(SeriesEditor::class, ['series' => $this->series])->set('form.product_ids', [$this->product->id, $second->id])
        ->call('moveProduct', 1, -1)->assertSet('form.product_ids.0', $second->id)->call('saveDraft')->assertHasNoErrors();
    $old = $this->series->fresh()->draft_revision_id;
    $this->get(route('admin.commerce.series-content.preview', $old))->assertOk()->assertSeeInOrder(['Second public chair', 'Public Series chair']);
    Livewire::test(SeriesEditor::class, ['series' => $this->series->fresh()])->call('removeProduct', 0)->assertSet('form.product_ids', [$this->product->id])->call('saveDraft')->assertHasNoErrors();
    $this->expectException(HttpException::class);
    $this->service->publish($this->series->fresh(), $old, 2, $this->editor);
});
