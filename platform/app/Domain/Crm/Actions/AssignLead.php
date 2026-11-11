<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\AssignmentRule;
use App\Domain\Crm\Models\Lead;
use App\Models\User;
use App\Settings\BusinessSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lead assignment (CRM-009). Manual: assign to a chosen person (a reason is recorded on reassignment).
 * Automatic: the first active assignment rule that matches the lead (line, service, city/area, source)
 * picks a team member by round robin or fewest open leads, skipping anyone at the rule's workload cap.
 * Rules marked "working hours only" are skipped while the studio is closed, so an after-hours rule
 * further down the list can route evening enquiries. Without a matching rule the least-loaded active
 * sales user (then sales manager) gets the lead.
 */
final class AssignLead
{
    /** @var list<string> */
    private const FALLBACK_ROLES = ['sales', 'sales_manager'];

    public function __construct(private readonly BusinessSettings $business) {}

    public function handle(Lead $lead, ?User $to = null, ?User $by = null, ?string $reason = null): ?User
    {
        $rule = null;

        if ($to === null) {
            [$to, $rule] = $this->pickOwner($lead);
        }

        if ($to === null) {
            return null;
        }

        $previous = $lead->owner_id;
        $lead->owner_id = $to->id;
        $lead->save();

        $body = match (true) {
            $reason !== null && $reason !== '' => $reason,
            $rule !== null => "Auto-assigned to {$to->name} (rule: {$rule->name})",
            $by === null => "Auto-assigned to {$to->name}",
            default => "Assigned to {$to->name}",
        };

        Activity::create([
            'contact_id' => $lead->contact_id,
            'lead_id' => $lead->id,
            'user_id' => $by?->id,
            'type' => ActivityType::Assignment,
            'body' => $body,
            'data' => array_filter(['from' => $previous, 'to' => $to->id, 'rule_id' => $rule?->id]),
            'occurred_at' => now(),
        ]);

        return $to;
    }

    /**
     * @return array{0: User|null, 1: AssignmentRule|null}
     */
    private function pickOwner(Lead $lead): array
    {
        $open = $this->business->hours()->isOpen(CarbonImmutable::now());

        foreach (AssignmentRule::query()->active()->get() as $rule) {
            if (($rule->working_hours_only && ! $open) || ! $rule->matches($lead)) {
                continue;
            }

            if ($user = $this->pickFromRule($rule)) {
                return [$user, $rule];
            }
        }

        foreach (self::FALLBACK_ROLES as $role) {
            $candidates = User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn (Builder $q) => $q->where('name', $role))
                ->orderBy('id')
                ->get();

            if ($candidates->isNotEmpty()) {
                $loads = $this->openLeadCounts($candidates->modelKeys());

                return [$candidates->sortBy(fn (User $u): string => sprintf('%08d-%08d', $loads[$u->id] ?? 0, $u->id))->first(), null];
            }
        }

        return [null, null];
    }

    private function pickFromRule(AssignmentRule $rule): ?User
    {
        $candidates = $rule->candidates();

        if ($candidates->isEmpty()) {
            return null;
        }

        $loads = $this->openLeadCounts($candidates->modelKeys());

        if ($rule->max_open_leads !== null && $rule->max_open_leads > 0) {
            $candidates = $candidates->filter(fn (User $u): bool => ($loads[$u->id] ?? 0) < $rule->max_open_leads)->values();
        }

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($rule->strategy === 'least_loaded') {
            $user = $candidates->sortBy(fn (User $u): string => sprintf('%08d-%08d', $loads[$u->id] ?? 0, $u->id))->first();
        } else {
            // Round robin: the next person after the last one this rule picked (ids ascending, wrapping).
            $user = $candidates->first(fn (User $u): bool => $u->id > (int) $rule->last_assigned_user_id) ?? $candidates->first();
        }

        $rule->forceFill(['last_assigned_user_id' => $user?->id])->save();

        return $user;
    }

    /**
     * @param  array<int, mixed>  $userIds
     * @return Collection<int, int> user id => open leads
     */
    private function openLeadCounts(array $userIds): Collection
    {
        return Lead::query()
            ->open()
            ->whereIn('owner_id', $userIds)
            ->selectRaw('owner_id, count(*) as aggregate')
            ->groupBy('owner_id')
            ->pluck('aggregate', 'owner_id')
            ->map(fn (mixed $count): int => (int) $count);
    }
}
