<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lead routing rule (CRM-009). Rules are tried in position order; the first one that matches the lead
 * and still has a team member under the workload cap wins. Empty condition lists mean "any".
 *
 * @property array{business_lines?: list<string>, services?: list<string>, cities?: list<string>, sources?: list<string>}|null $conditions
 * @property list<int>|null $user_ids
 */
class AssignmentRule extends Model
{
    public const STRATEGIES = ['round_robin' => 'Round robin', 'least_loaded' => 'Fewest open leads'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'user_ids' => 'array',
            'is_active' => 'boolean',
            'working_hours_only' => 'boolean',
            'max_open_leads' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lastAssigned(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_assigned_user_id');
    }

    /**
     * @param  Builder<AssignmentRule>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('position')->orderBy('id');
    }

    public function matches(Lead $lead): bool
    {
        $conditions = $this->conditions ?? [];

        return $this->allows($conditions['business_lines'] ?? [], $lead->business_line->value)
            && $this->allows($conditions['services'] ?? [], (string) $lead->service)
            && $this->allows($conditions['sources'] ?? [], $lead->source->value)
            && $this->matchesPlace($conditions['cities'] ?? [], (string) $lead->city, (string) $lead->area);
    }

    /**
     * Active team members this rule can assign to: the listed people, otherwise everyone with the role.
     *
     * @return Collection<int, User>
     */
    public function candidates(): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $this->user_ids ?? [])));

        return User::query()
            ->where('is_active', true)
            ->when($ids !== [], fn (Builder $q) => $q->whereIn('id', $ids))
            ->when($ids === [] && $this->role !== null, fn (Builder $q) => $q->whereHas('roles', fn (Builder $r) => $r->where('name', $this->role)))
            ->when($ids === [] && $this->role === null, fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->orderBy('id')
            ->get();
    }

    public function summary(): string
    {
        $conditions = $this->conditions ?? [];
        $parts = [];

        foreach (['business_lines' => 'Line', 'services' => 'Service', 'cities' => 'City/area', 'sources' => 'Source'] as $key => $label) {
            if (($conditions[$key] ?? []) !== []) {
                $parts[] = $label.': '.implode(', ', array_map(fn (string $v): string => str_replace(['_', '-'], ' ', $v), $conditions[$key]));
            }
        }

        return $parts === [] ? 'Every lead' : implode(' · ', $parts);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function allows(array $allowed, string $value): bool
    {
        return $allowed === [] || in_array($value, $allowed, true);
    }

    /**
     * City must match exactly (any case); an area term matches anywhere in the lead's area ("DHA" ⊂ "DHA Phase 6").
     *
     * @param  list<string>  $places
     */
    private function matchesPlace(array $places, string $city, string $area): bool
    {
        if ($places === []) {
            return true;
        }

        foreach ($places as $place) {
            $place = trim($place);

            if ($place !== '' && (strcasecmp($place, trim($city)) === 0 || ($area !== '' && stripos($area, $place) !== false))) {
                return true;
            }
        }

        return false;
    }
}
