<?php

use App\Domain\Commerce\Actions\InstallCatalogueTaxonomy;
use App\Domain\Commerce\Models\Category;

it('installs all office and home menu categories including those without products', function () {
    $taxonomy = json_decode(file_get_contents(resource_path('data/commerce-taxonomy.json')), true);
    foreach ($taxonomy['segments'] as $segment) {
        $root = Category::where('slug', $segment['slug'])->sole();
        foreach ($segment['groups'] as $group) {
            $parent = Category::where('slug', $group['id'])->sole();
            expect($parent->parent_id)->toBe($root->id);
            foreach ($group['children'] as $child) {
                expect(Category::where('slug', $child['id'])->sole()->parent_id)->toBe($parent->id);
            }
        }
    }
});

it('reinstalls without duplicating categories or overwriting operator customizations', function () {
    $category = Category::where('slug', 'chairs')->sole();
    $category->update(['name' => 'Customized Chairs', 'is_active' => false]);
    $count = Category::count();
    app(InstallCatalogueTaxonomy::class)->handle();
    expect(Category::count())->toBe($count)->and($category->fresh()->name)->toBe('Customized Chairs')->and($category->fresh()->is_active)->toBeFalse();
});
