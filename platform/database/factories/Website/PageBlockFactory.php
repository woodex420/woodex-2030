<?php

declare(strict_types=1);

namespace Database\Factories\Website;

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Models\PageBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PageBlock> */
final class PageBlockFactory extends Factory
{
    protected $model = PageBlock::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(BlockType::cases());

        return [
            'type' => $type->value,
            'position' => 0,
            'data' => $type->fields(),
            'visible' => true,
        ];
    }

    public function asType(BlockType $type, array $data = []): self
    {
        return $this->state(fn (): array => ['type' => $type->value, 'data' => $data + $type->fields()]);
    }

    public function hidden(): self
    {
        return $this->state(fn (): array => ['visible' => false]);
    }
}
