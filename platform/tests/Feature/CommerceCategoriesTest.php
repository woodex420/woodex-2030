<?php

use App\Domain\Commerce\Models\Category;
use App\Livewire\Admin\Commerce\Categories;
use Livewire\Livewire;

it('creates edits deactivates and audits categories without publishing anything', function () {
    $this->actingAs($this->userWithRole('admin'));
    Livewire::test(Categories::class)->call('create')->set('form.name', 'Office Tables')
        ->set('form.slug', 'synthetic-office-tables')->set('form.is_active', true)->call('save')->assertHasNoErrors()->assertSee('Office Tables');
    $category = Category::query()->where('slug', 'synthetic-office-tables')->firstOrFail();
    Livewire::test(Categories::class)->call('edit', $category->id)->set('form.name', 'Office desks')
        ->set('form.is_active', false)->call('save')->assertHasNoErrors();
    expect($category->fresh()->name)->toBe('Office desks')->and($category->fresh()->is_active)->toBeFalse();
    $this->assertDatabaseHas('activity_log', ['log_name' => 'commerce', 'subject_id' => $category->id]);
});

it('allows sales to view but blocks every management action', function () {
    $this->actingAs($this->userWithRole('sales'));
    $this->get(route('admin.commerce.categories'))->assertOk();
    Livewire::test(Categories::class)->call('create')->assertForbidden();
    Livewire::test(Categories::class)->call('edit', 1)->assertForbidden();
    Livewire::test(Categories::class)->set('form', ['name' => 'Forged'])->call('save')->assertForbidden();
    expect(Category::where('name', 'Forged')->exists())->toBeFalse();
    $this->actingAs($this->userWithRole('accountant'))->get(route('admin.commerce.categories'))->assertForbidden();
});

it('rejects duplicate slugs malformed values and category cycles', function () {
    $this->actingAs($this->userWithRole('admin'));
    $root = Category::create(['name' => 'Root', 'slug' => 'root']);
    $child = Category::create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $root->id]);
    Livewire::test(Categories::class)->call('edit', $root->id)->set('form.parent_id', $child->id)->call('save')->assertHasErrors('form.parent_id');
    Livewire::test(Categories::class)->call('edit', $root->id)->set('form.parent_id', $root->id)->call('save')->assertHasErrors('form.parent_id');
    Livewire::test(Categories::class)->call('create')->set('form.name', 'Duplicate')->set('form.slug', 'root')->call('save')->assertHasErrors('form.slug');
    Livewire::test(Categories::class)->call('create')->set('form.name', ' ')->set('form.slug', '../bad')->call('save')->assertHasErrors(['form.name', 'form.slug']);
    expect($root->fresh()->parent_id)->toBeNull();
});

it('searches categories and shows an honest empty state', function () {
    $this->actingAs($this->userWithRole('sales'));
    Category::create(['name' => 'Office Tables', 'slug' => 'tables']);
    Category::create(['name' => 'Chairs', 'slug' => 'synthetic-chairs']);
    Livewire::test(Categories::class)->set('search', 'Tables')->assertSee('Office Tables');
    Livewire::test(Categories::class)->set('search', 'Not available')->assertSee('No categories found');
});
