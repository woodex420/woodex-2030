<?php

use App\Domain\Website\Models\NavigationItem;
use App\Domain\Website\Services\Menus;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithPublicWebsite;

/*
| V2-3 navigation (docs/14): menus are a tree. A top link with children opens a dropdown; its mega switch
| turns that dropdown into the wide numbered panel of the v26 design. Children move only inside their own
| level, and the tree is cached like the flat menus always were.
*/

uses(InteractsWithPublicWebsite::class);

beforeEach(function () {
    $this->editor = User::factory()->create();
    $this->editor->givePermissionTo('website.edit');

    Cache::forget('website.menu.tree.header');
    Cache::forget('website.menu.header');
    NavigationItem::query()->delete();
});

it('builds the header menu as a tree with children and grandchildren', function () {
    $services = NavigationItem::factory()->create(['menu' => NavigationItem::HEADER, 'label' => 'Services', 'url' => '/services/', 'position' => 0, 'is_mega' => true]);
    $design = NavigationItem::factory()->create(['menu' => NavigationItem::HEADER, 'label' => 'Interior design', 'url' => '/interior-design/', 'parent_id' => $services->id, 'position' => 0]);
    NavigationItem::factory()->create(['menu' => NavigationItem::HEADER, 'label' => 'Kitchen design', 'url' => '/kitchen-design/', 'parent_id' => $design->id, 'position' => 0]);
    NavigationItem::factory()->create(['menu' => NavigationItem::HEADER, 'label' => 'About', 'url' => '/about/', 'position' => 1]);

    $tree = Menus::tree('header');

    expect($tree)->toHaveCount(2)
        ->and($tree[0])->toMatchArray(['label' => 'Services', 'url' => '/services/', 'is_mega' => true])
        ->and($tree[0]['children'])->toHaveCount(1)
        ->and($tree[0]['children'][0]['label'])->toBe('Interior design')
        ->and($tree[0]['children'][0]['children'][0]['label'])->toBe('Kitchen design')
        ->and($tree[1])->toMatchArray(['label' => 'About', 'is_mega' => false, 'children' => []]);
});

it('falls back to the built-in links as a flat tree until the menu is filled in', function () {
    $tree = Menus::tree('header');

    expect($tree)->not->toBeEmpty()
        ->and($tree[0]['children'])->toBe([])
        ->and($tree[0]['is_mega'])->toBeFalse();
});
