<?php

declare(strict_types=1);

namespace App\Domain\Website\Models;

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Support\SectionStyle;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A page template (V2-3 F5, docs/14 §5a): a named, ordered section list for one page type. A template
 * decides the layout — which sections, in what order — never the page's text. The studio can start a
 * page from one, apply one to an existing page, or save a page's own layout back as a template.
 *
 * @property int $id
 * @property string $name
 * @property PageType $page_type
 * @property string|null $description
 * @property bool $is_default
 * @property list<array{type: string, data: array<string, mixed>}> $sections
 */
class PageTemplate extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'page_type' => PageType::class,
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The template's sections as the page editor's block list: fresh keys, template data as-is.
     * Image placeholders stay empty — photos are the page's own, not the layout's.
     *
     * @return list<array{id: ?int, key: string, type: string, visible: bool, data: array<string, mixed>}>
     */
    public function toBlocks(): array
    {
        return collect($this->sections)
            ->map(fn (array $section): array => [
                'id' => null,
                'key' => 'b'.Str::lower(Str::random(8)),
                'type' => (string) $section['type'],
                'visible' => true,
                'data' => $this->sectionData($section),
                'settings' => SectionStyle::clean((array) ($section['settings'] ?? [])),
            ])
            ->values()
            ->all();
    }

    /**
     * The section's data, cleaned against what its type knows — so a template saved before a section
     * gained or lost a field still applies cleanly.
     *
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function sectionData(array $section): array
    {
        $type = BlockType::tryFrom((string) $section['type']) ?? BlockType::Text;

        return array_intersect_key((array) ($section['data'] ?? []), $type->fields());
    }
}
