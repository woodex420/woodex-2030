<?php

declare(strict_types=1);

namespace App\Domain\Crm\Jobs;

use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Notifications\NewLeadNotification;
use App\Domain\System\Alerts\AlertRouter;
use App\Domain\System\Models\Automation;
use App\Models\User;
use App\Settings\NotificationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Notify the lead owner and the sales managers (P8.5: the owner's decision — both, always) in-app
 * and on Telegram; staff without a Telegram link get the alert by email (TGM-002). The switch on
 * Automation → Automations can silence the whole flow without touching the capture itself.
 */
final class SendLeadAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $leadId,
        public readonly bool $isNewLead,
    ) {}

    public function handle(NotificationSettings $settings): void
    {
        if (! Automation::enabled('woodex:new-lead-alert')) {
            return;
        }

        $lead = Lead::query()->with(['contact', 'owner.telegramLink', 'stage'])->find($this->leadId);

        if ($lead === null) {
            return;
        }

        $recipients = $this->recipients($lead, $settings);

        if ($recipients->isNotEmpty()) {
            app(AlertRouter::class)->send('lead.new', $recipients, new NewLeadNotification($lead, $this->isNewLead));
        }
    }

    /**
     * The owner's accepted rule: the lead's owner AND the sales managers hear about every new
     * enquiry. Without an owner, the configured unassigned-lead roles (owner + sales managers by
     * default) take the alert.
     *
     * @return Collection<int, User>
     */
    private function recipients(Lead $lead, NotificationSettings $settings): Collection
    {
        $managers = User::query()
            ->with('telegramLink')
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $q) => $q->where('name', 'sales_manager'))
            ->get();

        if ($lead->owner !== null && $lead->owner->is_active) {
            return $managers->push($lead->owner)->unique('id')->values();
        }

        return User::query()
            ->with('telegramLink')
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $q) => $q->whereIn('name', $settings->unassigned_lead_roles))
            ->get();
    }
}
