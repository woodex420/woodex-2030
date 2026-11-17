<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Enums\TemplateKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reusable quotation blueprint: sections + items + terms + milestone plan (QUO-002).
 *
 * blueprint = {sections: [{name, note, items: [{description, unit, qty, rate_minor, catalog_item_id}]}]}
 */
class QuoteTemplate extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_line' => BusinessLine::class,
            'kind' => TemplateKind::class,
            'milestone_plan' => MilestonePlanKey::class,
            'blueprint' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Quote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'template_id');
    }

    public function sectionCount(): int
    {
        return count($this->blueprint['sections'] ?? []);
    }

    public function itemCount(): int
    {
        return array_sum(array_map(
            static fn (array $section): int => count($section['items'] ?? []),
            $this->blueprint['sections'] ?? [],
        ));
    }
}
