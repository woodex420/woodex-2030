<?php

declare(strict_types=1);

namespace Database\Factories\Website;

use App\Domain\Website\Models\NavigationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NavigationItem> */
final class NavigationItemFactory extends Factory
{
    protected $model = NavigationItem::class;

    public function definition(): array
    {
        return [
            'menu' => NavigationItem::HEADER,
            'label' => $this->faker->words(2, true),
            'url' => '/'.$this->faker->slug(2).'/',
            'position' => 0,
            'target' => '_self',
        ];
    }
}
