<?php

declare(strict_types=1);

namespace App\Domain\Website\Models;

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Support\SectionStyle;
use Database\Factories\Website\PageBlockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One section of a page (docs/09 §4). Data is a plain array shaped by BlockType::fields().
 *
 * @property int $id
 * @property int $page_id
 * @property BlockType $type
 * @property string|null $block_key
 * @property int $position
 * @property array<string, mixed>|null $data
 * @property array<string, mixed>|null $settings the wrapper's look (SectionStyle), not its content
 * @property bool $visible
 */
class PageBlock extends Model
{
    /** @use HasFactory<PageBlockFactory> */
    use HasFactory;

    protected static function newFactory(): PageBlockFactory
    {
        return PageBlockFactory::new();
    }

    protected $fillable = ['page_id', 'type', 'block_key', 'position', 'data', 'settings', 'visible'];

    protected function casts(): array
    {
        return [
            'type' => BlockType::class,
            'data' => 'array',
            'settings' => 'array',
            'position' => 'integer',
            'visible' => 'boolean',
        ];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * The wrapper's settings, always complete: an old section with none keeps its design.
     *
     * @return array{label: string, background: string, spacing: string, width: string, align: string}
     */
    public function style(): array
    {
        return SectionStyle::clean($this->settings);
    }

    /** Missing keys fall back to the defaults of the block type, so a section never renders half-empty. */
    public function field(string $key, mixed $default = null): mixed
    {
        $value = $this->data[$key] ?? null;

        return $value ?? $default ?? ($this->type->fields()[$key] ?? null);
    }
}
