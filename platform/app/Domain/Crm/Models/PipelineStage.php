<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\StageType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configurable pipeline column: New → Contacted → Site Visit → Quotation → Negotiation → On Hold → Won / Lost (CRM-004).
 */
class PipelineStage extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StageType::class,
            'probability' => 'integer',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'stage_id');
    }

    /**
     * @param  Builder<PipelineStage>  $query
     * @return Builder<PipelineStage>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position');
    }

    public static function byKey(string $key): self
    {
        return self::query()->where('key', $key)->firstOrFail();
    }

    /**
     * Badge colour per docs/05 §4: won = success, lost = error, open stages use their configured colour.
     */
    public function badgeColor(): string
    {
        return match ($this->type) {
            StageType::Won => 'success',
            StageType::Lost => 'error',
            StageType::Open => (string) ($this->color ?: 'info'),
        };
    }

    public function isClosed(): bool
    {
        return $this->type !== StageType::Open;
    }
}
