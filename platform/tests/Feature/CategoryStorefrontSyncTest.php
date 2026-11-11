<?php

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\CategoryContentRevision;
use App\Domain\Commerce\Models\Series;
use App\Domain\Commerce\Services\CategoryContent;
use App\Livewire\Admin\Commerce\Categories;
use App\Livewire\Admin\Commerce\SeriesManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    config(['woodex.category_sync_enabled' => true]);
    $this->admin = $this->userWithRole('admin');
    $this->actingAs($this->admin);
});

it('organizes siblings including main roots without changing parents and rejects stale or cross-parent moves', function () {
    $office = Category::where('slug', 'office-furniture')->sole();
    $home = Category::where('slug', 'home-furniture')->sole();
    $component = Livewire::test(Categories::class);
    $stale = Livewire::test(Categories::class);
    $component->call('reorder', $home->id, $office->id)->assertHasNoErrors();
    expect(Category::whereNull('parent_id')->orderBy('position')->first()->id)->toBe($home->id)
        ->and($home->fresh()->parent_id)->toBeNull();
    $stale->call('reorder', $office->id, $home->id)->assertHasErrors('tree');
    $leaf = Category::where('slug', 'executive-tables')->sole();
    $component->call('reorder', $leaf->id, $home->id)->assertHasErrors('tree');
    $meeting = Category::where('slug', 'meeting-tables')->sole();
    Livewire::test(Categories::class)->call('reorder', $meeting->id, $leaf->id)->assertHasNoErrors();
    expect($meeting->fresh()->position)->toBe(0)->and($meeting->fresh()->parent_id)->toBe($leaf->parent_id);
});

it('denies category and series ordering to view-only staff', function () {
    $this->actingAs($this->userWithRole('sales'));
    $ids = Category::whereNull('parent_id')->pluck('id');
    Livewire::test(Categories::class)->call('reorder', $ids[0], $ids[1])->assertForbidden();
    $series = Series::pluck('id');
    Livewire::test(SeriesManager::class)->call('reorder', $series[0], $series[1])->assertForbidden();
});

it('publishes only reviewed content publicly and withdraws it on unpublish', function () {
    $category = Category::where('slug', 'office-furniture')->sole();
    $service = app(CategoryContent::class);
    $form = $service->defaults($category);
    $form['intro_text'] = '**Reviewed published copy**';
    $form['carousel_mode'] = 'automatic';
    $revision = $service->saveDraft($category, $form, null, 0, $this->admin);
    $url = '/api/v1/catalogue/categories';
    $data = $this->getJson($url)->assertOk()->json('categories');
    expect(collect($data)->firstWhere('id', $category->id)['page'])->toBeNull();
    $this->get(route('admin.commerce.category-content.preview', $revision))->assertOk();
    $service->publish($category, $revision->id, 1, $this->admin);
    $data = $this->getJson($url)->assertOk()->json('categories');
    $page = collect($data)->firstWhere('id', $category->id)['page'];
    expect($page['content']['intro_html'])->toContain('<strong>Reviewed published copy</strong>')
        ->and($page['content']['featured_ids'])->toHaveCount(8)->and($page)->not->toHaveKey('created_by')
        ->and($page['content'])->not->toHaveKey('hero_path');
    $form['intro_text'] = 'PRIVATE DRAFT';
    $service->saveDraft($category, $form, null, 2, $this->admin);
    $this->getJson($url)->assertDontSee('PRIVATE DRAFT');
    $service->unpublish($category, 3, $this->admin);
    $data = $this->getJson($url)->json('categories');
    expect(collect($data)->firstWhere('id', $category->id)['page'])->toBeNull();
});

it('excludes inactive branches, serves only published hero images and has a rollback gate', function () {
    Storage::fake('local');
    $category = Category::where('slug', 'executive-tables')->sole();
    $service = app(CategoryContent::class);
    $path = UploadedFile::fake()->image('hero.png')->store('commerce/category-heroes', 'local');
    $form = $service->defaults($category);
    $form['hero_alt'] = 'Actual test fixture';
    $revision = $service->saveDraft($category, $form, $path, 0, $this->admin);
    $image = '/api/v1/catalogue/categories/'.$category->id.'/hero/'.$revision->id;
    $this->get($image)->assertNotFound();
    $this->get(route('admin.commerce.category-content.preview', $revision))->assertOk();
    $service->publish($category, $revision->id, 1, $this->admin);
    $this->get($image)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    Category::where('slug', 'office-furniture')->update(['is_active' => false]);
    $this->get($image)->assertNotFound();
    $this->getJson('/api/v1/catalogue/categories')->assertOk()->assertJsonMissing(['slug' => 'executive-tables']);
    config(['woodex.category_sync_enabled' => false]);
    $this->getJson('/api/v1/catalogue/categories')->assertStatus(503);
});

it('organizes Series separately from categories and emits the new order', function () {
    $first = Series::orderBy('position')->first();
    $last = Series::orderByDesc('position')->first();
    $count = Category::count();
    Livewire::test(SeriesManager::class)->call('reorder', $last->id, $first->id)->assertHasNoErrors();
    $this->getJson('/api/v1/catalogue/categories')->assertOk()->assertJsonPath('series.0.id', $last->slug);
    expect(Category::count())->toBe($count)->and(CategoryContentRevision::count())->toBe(0);
});
