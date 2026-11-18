<?php

declare(strict_types=1);

namespace Database\Factories\Website;

use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Page> */
final class PageFactory extends Factory
{
    protected $model = Page::class;

    public function definition(): array
    {
        return [
            'type' => PageType::Service->value,
            'slug' => '/'.$this->faker->unique()->slug(2).'/',
            'title' => rtrim($this->faker->unique()->sentence(3), '.'),
            'excerpt' => $this->faker->sentence(14),
            'status' => PageStatus::Draft->value,
            'sort' => 0,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'status' => PageStatus::Published->value,
            'published_at' => now()->subDay(),
        ]);
    }

    public function scheduled(): self
    {
        return $this->state(fn (): array => [
            'status' => PageStatus::Published->value,
            'published_at' => now()->addWeek(),
        ]);
    }

    public function asType(PageType $type): self
    {
        return $this->state(fn (): array => ['type' => $type->value]);
    }

    public function withSlug(string $slug): self
    {
        return $this->state(fn (): array => ['slug' => Page::normalizeSlug($slug)]);
    }

    public function withSeo(array $seo): self
    {
        return $this->state(fn (): array => ['seo' => $seo]);
    }
}
