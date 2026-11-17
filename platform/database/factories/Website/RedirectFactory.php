<?php

declare(strict_types=1);

namespace Database\Factories\Website;

use App\Domain\Website\Models\Redirect;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Redirect> */
final class RedirectFactory extends Factory
{
    protected $model = Redirect::class;

    public function definition(): array
    {
        return [
            'from_path' => '/'.$this->faker->unique()->slug(3).'/',
            'to_path' => '/interior-design/',
            'code' => 301,
            'hits' => 0,
        ];
    }
}
