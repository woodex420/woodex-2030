<?php

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\CategoryContentRevision;
use App\Domain\Commerce\Services\CategoryContent;
use App\Livewire\Admin\Commerce\Categories;
use App\Livewire\Admin\Commerce\CategoryEditor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->category = Category::where('slug', 'office-furniture')->sole();
    $this->editor = $this->userWithRole('admin');
    $this->actingAs($this->editor);
});

it('saves immutable drafts previews then publishes only an explicitly confirmed exact revision', function () {
    $editor = Livewire::test(CategoryEditor::class, ['category' => $this->category])->assertSee('Page metadata')
        ->set('form.hero_heading', 'Office category content')->set('form.bottom_text', 'Bottom content')
        ->call('addFaq')->set('form.faqs.0.question', 'Can I customize?')->set('form.faqs.0.answer', 'Ask the team.')
        ->call('saveDraft')->assertHasNoErrors();
    $revision = CategoryContentRevision::sole();
    expect($this->category->fresh()->published_revision_id)->toBeNull()->and($revision->content['faqs'][0]['answer'])->toBe('Ask the team.');
    $editor->set('confirmPublish', true)->call('publish')->assertStatus(200)->assertHasErrors('publish');
    $this->get(route('admin.commerce.category-content.preview', $revision))->assertOk()->assertSee('Office category content')
        ->assertSee('Can I customize?')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'no-store, private');
    $editor->call('publish')->assertHasNoErrors();
    expect($this->category->fresh()->published_revision_id)->toBe($revision->id);
    $editor->set('form.hero_heading', 'New unpublished content')->call('saveDraft')->assertHasNoErrors();
    expect(CategoryContentRevision::count())->toBe(2)->and($this->category->fresh()->published_revision_id)->toBe($revision->id)
        ->and($revision->fresh()->content['hero_heading'])->toBe('Office category content');
});

it('does not publish unsaved changes or stale tabs and rechecks hierarchy preview context', function () {
    $first = Livewire::test(CategoryEditor::class, ['category' => $this->category]);
    $stale = Livewire::test(CategoryEditor::class, ['category' => $this->category]);
    $first->set('form.carousel_mode', 'automatic')->call('saveDraft')->assertHasNoErrors();
    $stale->set('form.hero_heading', 'Stale')->call('saveDraft')->assertStatus(200)->assertHasErrors('conflict');
    $revision = CategoryContentRevision::sole();
    $this->get(route('admin.commerce.category-content.preview', $revision))->assertOk();
    $first->set('form.hero_heading', 'Unsaved')->set('confirmPublish', true)->call('publish')->assertStatus(200)->assertHasErrors('publish');
    // A category move changes automatic eligibility even without editing the root content row.
    Category::where('slug', 'office-tables')->update(['parent_id' => Category::where('slug', 'home-furniture')->value('id')]);
    $service = app(CategoryContent::class);
    try {
        $service->publish($this->category, $revision->id, 1, $this->editor);
        test()->fail('Publishing a stale preview should fail');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('publish');
    }
    expect($this->category->fresh()->published_revision_id)->toBeNull();
});

it('supports FAQ add remove reorder and safe escaped preview content', function () {
    $editor = Livewire::test(CategoryEditor::class, ['category' => $this->category])->call('addFaq')->call('addFaq')
        ->set('form.faqs.0', ['question' => 'First?', 'answer' => '<script>alert(1)</script>'])
        ->set('form.faqs.1', ['question' => 'Second?', 'answer' => 'Second answer'])
        ->call('moveFaq', 1, -1)->call('saveDraft')->assertHasNoErrors();
    $revision = CategoryContentRevision::sole();
    expect($revision->content['faqs'][0]['question'])->toBe('Second?');
    $this->get(route('admin.commerce.category-content.preview', $revision))->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    $editor->call('removeFaq', 0)->call('saveDraft')->assertHasNoErrors();
    expect(count(CategoryContentRevision::latest('id')->first()->content['faqs']))->toBe(1)->and(count($revision->fresh()->content['faqs']))->toBe(2);
});

it('validates paths limits and featured category membership without inventing hot products', function () {
    $eligible = app(CategoryContent::class)->eligibleProducts($this->category);
    expect(count($eligible))->toBe(143);
    $ids = array_slice(array_column($eligible, 'id'), 0, 2);
    $editor = Livewire::test(CategoryEditor::class, ['category' => $this->category])->set('form.carousel_mode', 'manual')->set('form.product_ids', $ids)->set('form.carousel_limit', '3')
        ->set('form.carousel_limit', '3')->call('moveProduct', 1, -1)->call('saveDraft')->assertHasNoErrors();
    $revision = CategoryContentRevision::sole();
    expect($revision->content['carousel_limit'])->toBe(3);
    expect(array_column(app(CategoryContent::class)->products($this->category, $revision->content), 'id'))->toBe(array_reverse($ids));
    $editor->set('form.product_ids', ['forged-product'])->call('saveDraft')->assertHasErrors('product_ids.0');
    $editor->set('form.product_ids', [])->set('form.cta_label', 'Click')->set('form.cta_path', 'javascript:alert(1)')->call('saveDraft')->assertHasErrors('cta_path');
    $editor->set('form.cta_path', '//evil.example')->call('saveDraft')->assertHasErrors('cta_path');
    $editor->set('form.cta_path', 123)->call('saveDraft')->assertHasErrors('cta_path');
    $editor->set('form.cta_path', '/shop')->set('form.carousel_limit', 99)->call('saveDraft')->assertHasErrors('carousel_limit');
});

it('stores real hero uploads privately retains old revision files and serves only authorized image requests', function () {
    Storage::fake('local');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a4YQAAAAASUVORK5CYII=');
    $file = UploadedFile::fake()->createWithContent('hero.png', $png);
    $editor = Livewire::test(CategoryEditor::class, ['category' => $this->category])->set('heroUpload', $file)->set('form.hero_alt', 'Synthetic test image')
        ->call('saveDraft')->assertHasNoErrors();
    $revision = CategoryContentRevision::sole();
    $path = $revision->content['hero_path'];
    Storage::disk('local')->assertExists($path);
    $this->get(route('admin.commerce.category-content.hero', $revision))->assertOk();
    $editor->call('removeHero')->call('saveDraft')->assertHasNoErrors();
    expect(CategoryContentRevision::latest('id')->first()->content['hero_path'])->toBeNull();
    Storage::disk('local')->assertExists($path);
    $this->actingAs($this->userWithRole('sales'))->get(route('admin.commerce.category-content.hero', $revision))->assertForbidden();
});

it('rejects unsafe uploads without a new revision and separates view manage and publish permissions', function () {
    Storage::fake('local');
    Livewire::test(CategoryEditor::class, ['category' => $this->category])->set('heroUpload', UploadedFile::fake()->createWithContent('evil.svg', '<svg onload="alert(1)"/>'))
        ->call('saveDraft')->assertHasErrors('heroUpload');
    expect(CategoryContentRevision::count())->toBe(0);
    $manager = $this->userWithRole('sales_manager');
    $this->actingAs($manager);
    $editor = Livewire::test(CategoryEditor::class, ['category' => $this->category])->call('saveDraft')->assertHasNoErrors();
    $editor->set('confirmPublish', true)->call('publish')->assertForbidden();
    $this->actingAs($this->userWithRole('sales'))->get(route('admin.commerce.categories.edit', $this->category))->assertForbidden();
    $this->get(route('admin.commerce.category-content.preview', CategoryContentRevision::sole()))->assertForbidden();
});

it('keeps automatic carousels bounded and empty categories honest', function () {
    $service = app(CategoryContent::class);
    $content = $service->defaults($this->category);
    $content['carousel_mode'] = 'automatic';
    $content['carousel_sort'] = 'name';
    $content['carousel_limit'] = 3;
    $products = $service->products($this->category, $content);
    expect(count($products))->toBe(3);
    $names = array_column($products, 'name');
    $sorted = $names;
    usort($sorted, 'strcasecmp');
    expect($names)->toBe($sorted);
    $empty = Category::where('slug', 'bedside-tables')->sole();
    expect($service->products($empty, $content))->toBe([]);
});

it('does not grant one publisher another users preview receipt or bypass publication with a forged revision', function () {
    $service = app(CategoryContent::class);
    $revision = $service->saveDraft($this->category, $service->defaults($this->category), null, 0, $this->editor);
    $this->get(route('admin.commerce.category-content.preview', $revision))->assertOk();
    $other = $this->userWithRole('admin');
    try {
        $service->publish($this->category, $revision->id, 1, $other);
        test()->fail('Preview receipt must be per publisher');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('publish');
    }
    expect($this->category->fresh()->published_revision_id)->toBeNull();
});

it('invalidates editor versions when a quick edit changes category identity', function () {
    $editor = Livewire::test(CategoryEditor::class, ['category' => $this->category]);
    Livewire::test(Categories::class)->call('edit', $this->category->id)
        ->set('form.name', 'Changed category name')->call('save')->assertHasNoErrors();
    $editor->call('saveDraft')->assertHasErrors('conflict');
    expect(CategoryContentRevision::count())->toBe(0);
});

it('unpublishes without deleting history and restores old content only as a new draft', function () {
    $editor = Livewire::test(CategoryEditor::class, ['category' => $this->category])->call('saveDraft')->assertHasNoErrors();
    $old = CategoryContentRevision::sole();
    $this->get(route('admin.commerce.category-content.preview', $old))->assertOk();
    $editor->set('confirmPublish', true)->call('publish')->assertHasNoErrors();
    $editor->call('unpublish')->assertHasErrors('publish');
    $editor->set('confirmUnpublish', true)->call('unpublish');
    expect($this->category->fresh()->published_revision_id)->toBeNull()->and(CategoryContentRevision::count())->toBe(1);
    $editor->set('form.hero_heading', 'Later content')->call('saveDraft')->assertHasNoErrors();
    $editor->call('restoreAsDraft', $old->id)->assertSet('savedRevisionId', null)->call('saveDraft')->assertHasNoErrors();
    expect(CategoryContentRevision::count())->toBe(3)->and(CategoryContentRevision::latest('id')->first()->content['hero_heading'])->toBe($old->content['hero_heading']);
});

it('renders formatted content safely and preserves old plain revisions', function () {
    $service = app(CategoryContent::class);
    $html = $service->formattedText("## Introduction\n\n**Real content**\n\n- First\n- Second\n\n<script>alert(1)</script>\n[unsafe](javascript:alert(1))\n![remote](https://example.com/track.png)", 'markdown');
    expect($html)->toContain('<h2>Introduction</h2>', '<strong>Real content</strong>', '<ul>')
        ->not->toContain('<script', 'href="javascript:', '<img');
    expect($service->formattedText('**Original text** <script>'))->toContain('**Original text**', '&lt;script&gt;');
    $form = $service->defaults($this->category);
    $form['intro_text'] = '## Saved introduction';
    $revision = $service->saveDraft($this->category, $form, null, 0, $this->editor);
    $this->get(route('admin.commerce.category-content.preview', $revision))->assertOk()->assertSee('<h2>Saved introduction</h2>', false);
});

it('shows the unified category actions and formatting editor', function () {
    $this->get(route('admin.commerce.categories'))->assertOk()->assertSee('Quick edit')->assertSee(route('admin.commerce.categories.edit', $this->category), false)->assertDontSee('Full Edit / CMS');
    Livewire::test(CategoryEditor::class, ['category' => $this->category])->assertSee('Content preview')->assertSee('Formatted text (Markdown)')->assertSee('No category image has been saved');
});

it('continues category creation into the full editor', function () {
    $component = Livewire::test(Categories::class)->call('create')
        ->set('form.name', 'New test collection category')->set('form.slug', 'new-test-category')
        ->set('form.parent_id', $this->category->id)->call('save')->assertHasNoErrors();
    $created = Category::where('slug', 'new-test-category')->sole();
    $component->assertRedirect(route('admin.commerce.categories.edit', $created));
    expect($created->parent_id)->toBe($this->category->id);
});

it('loads existing source text only on request and never overwrites an introduction', function () {
    $category = Category::where('slug', 'executive-tables')->sole();
    $component = Livewire::test(CategoryEditor::class, ['category' => $category])->call('loadSourceText')->assertHasNoErrors();
    expect($component->get('form.intro_text'))->toStartWith('Commanding desks');
    $component->set('form.intro_text', 'Reviewed operator content')->call('loadSourceText')->assertHasErrors('source')->assertSet('form.intro_text', 'Reviewed operator content');
    expect(CategoryContentRevision::count())->toBe(0);
    Livewire::test(CategoryEditor::class, ['category' => $this->category])->call('loadSourceText')->assertHasErrors('source');
});
