<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Automation\Enums\SequenceTrigger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A follow-up sequence (AUT-001): named, ordered steps, entered by a trigger, editable by the
 * studio — the built-ins are seeds, not code (AUT-002).
 */
class AutomationSequence extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trigger' => SequenceTrigger::class,
            'exits_on_reply' => 'boolean',
            'is_active' => 'boolean',
            'is_builtin' => 'boolean',
        ];
    }

    /** @return HasMany<AutomationStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(AutomationStep::class, 'sequence_id')->orderBy('position');
    }

    /** @return HasMany<AutomationEnrolment, $this> */
    public function enrolments(): HasMany
    {
        return $this->hasMany(AutomationEnrolment::class, 'sequence_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The active sequences a given trigger should enroll into.
     *
     * @return Collection<int, self>
     */
    public static function forTrigger(SequenceTrigger $trigger): Collection
    {
        return self::query()->active()->where('trigger', $trigger->value)->orderBy('id')->get();
    }
}
