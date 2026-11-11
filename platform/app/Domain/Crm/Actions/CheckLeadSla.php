<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Notifications\SlaBreachedNotification;
use App\Domain\System\Alerts\AlertRouter;
use App\Models\User;
use App\Settings\NotificationSettings;
use App\Settings\SalesSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * First-response SLA (CRM-010): flag open leads nobody has contacted by their due time and alert the
 * lead owner plus the escalation roles (in-app + Telegram). If the lead is still not contacted after
 * Settings → Sales "escalation delay", the final roles (default: owner) are alerted once more.
 * Each step happens once per lead (atomic claims); runs every minute.
 */
final class CheckLeadSla
{
    public function __construct(
        private readonly NotificationSettings $settings,
        private readonly SalesSettings $sales,
    ) {}

    /**
     * @return int number of leads newly flagged
     */
    public function handle(): int
    {
        $flagged = 0;

        Lead::query()
            ->open()
            ->whereNull('first_response_at')
            ->whereNull('sla_breached_at')
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<=', now())
            ->with(['contact', 'owner.telegramLink'])
            ->chunkById(100, function (Collection $leads) use (&$flagged): void {
                foreach ($leads as $lead) {
                    /** @var Lead $lead */
                    // Claim atomically so overlapping scheduler runs never alert twice.
                    $claimed = Lead::query()->whereKey($lead->id)->whereNull('sla_breached_at')->update(['sla_breached_at' => now()]);

                    if ($claimed === 0) {
                        continue;
                    }

                    $lead->sla_breached_at = now();

                    Activity::create([
                        'contact_id' => $lead->contact_id,
                        'lead_id' => $lead->id,
                        'type' => ActivityType::System,
                        'body' => 'First response time missed (was due '.$lead->sla_due_at?->format('j M, g:i a').').',
                        'data' => ['sla_due_at' => $lead->sla_due_at?->toIso8601String()],
                        'occurred_at' => now(),
                    ]);

                    $recipients = $this->recipients($lead);

                    if ($recipients->isNotEmpty()) {
                        app(AlertRouter::class)->send('lead.sla_breach', $recipients, new SlaBreachedNotification($lead));
                    }

                    $flagged++;
                }
            });

        return $flagged;
    }

    /**
     * Second level: still uncontacted N minutes after the breach.
     *
     * @return int number of leads escalated
     */
    public function escalate(): int
    {
        $escalated = 0;

        Lead::query()
            ->open()
            ->whereNull('first_response_at')
            ->whereNull('sla_escalated_at')
            ->whereNotNull('sla_breached_at')
            ->where('sla_breached_at', '<=', now()->subMinutes($this->sales->sla_escalation_minutes))
            ->with(['contact', 'owner.telegramLink'])
            ->chunkById(100, function (Collection $leads) use (&$escalated): void {
                foreach ($leads as $lead) {
                    /** @var Lead $lead */
                    $claimed = Lead::query()->whereKey($lead->id)->whereNull('sla_escalated_at')->update(['sla_escalated_at' => now()]);

                    if ($claimed === 0) {
                        continue;
                    }

                    Activity::create([
                        'contact_id' => $lead->contact_id,
                        'lead_id' => $lead->id,
                        'type' => ActivityType::System,
                        'body' => 'Still not contacted: escalated to '.implode(', ', array_map(
                            fn (string $role): string => str_replace('_', ' ', $role),
                            $this->settings->sla_final_roles,
                        )).'.',
                        'occurred_at' => now(),
                    ]);

                    $recipients = $this->usersWithRoles($this->settings->sla_final_roles);

                    if ($lead->owner !== null && $lead->owner->is_active) {
                        $recipients->push($lead->owner);
                    }

                    $recipients = $recipients->unique('id')->values();

                    if ($recipients->isNotEmpty()) {
                        app(AlertRouter::class)->send('lead.sla_breach', $recipients, new SlaBreachedNotification($lead, escalated: true));
                    }

                    $escalated++;
                }
            });

        return $escalated;
    }

    /**
     * @param  list<string>  $roles
     * @return Collection<int, User>
     */
    private function usersWithRoles(array $roles): Collection
    {
        return User::query()
            ->with('telegramLink')
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $q) => $q->whereIn('name', $roles))
            ->get();
    }

    /**
     * Owner plus the escalation roles from Settings → Notifications.
     *
     * @return Collection<int, User>
     */
    private function recipients(Lead $lead): Collection
    {
        $users = User::query()
            ->with('telegramLink')
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $q) => $q->whereIn('name', $this->settings->unassigned_lead_roles))
            ->get();

        if ($lead->owner !== null && $lead->owner->is_active) {
            $users->push($lead->owner);
        }

        return $users->unique('id')->values();
    }
}
