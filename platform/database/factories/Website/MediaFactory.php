<?php

declare(strict_types=1);

namespace Database\Factories\Website;

use App\Domain\Website\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Media> */
final class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        return [
            'disk' => 'public',
            'path' => 'website/'.$this->faker->unique()->slug(2).'.webp',
            'alt' => $this->faker->sentence(6),
            'title' => $this->faker->words(3, true),
            'width' => 1600,
            'height' => 900,
            'folder' => 'website',
        ];
    }
}
