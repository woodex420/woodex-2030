<?php

declare(strict_types=1);

namespace App\Domain\System\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The studio's automations (P8.5). The catalog is code — names, descriptions and cadences travel
 * with the deploy, never with a seed — and a missing row means ENABLED, so a database that has
 * never seen a toggle is already in the correct state (fail-safe: automations keep the funnel
 * moving). A row appears only when somebody pauses or resumes one, carrying who and when.
 *
 * A0 law holds: an automation switched ON never gains new powers from this table — it merely
 * allows the existing, consent-gated flows to run. Pausing is always safe.
 */
class Automation extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'bool',
            'toggled_at' => 'datetime',
        ];
    }

    /**
     * What the studio can switch on and off, and what merely keeps the lights on. Scheduled
     * entries are gated in routes/console.php — keep them in step; event-driven ones are gated
     * where they fire.
     *
     * @return list<array{key: string, name: string, description: string, cadence: string, toggleable: bool, scheduled: bool, default_enabled?: bool}>
     */
    public static function catalog(): array
    {
        return [
            [
                'key' => 'woodex:new-lead-alert',
                'name' => 'New-enquiry alerts',
                'description' => 'Telegram and in-app alert to the lead\'s owner and the sales managers the moment an enquiry arrives — by email for staff without a Telegram link.',
                'cadence' => 'On every new enquiry',
                'toggleable' => true,
                'scheduled' => false,
            ],
            [
                'key' => 'woodex:ask-for-number',
                'name' => 'Ask for a missing number',
                'description' => 'When an enquiry arrives with an email but no phone number, replies once asking for a WhatsApp number and opens a follow-up task.',
                'cadence' => 'On enquiries without a number',
                'toggleable' => true,
                'scheduled' => false,
            ],
            [
                'key' => 'woodex:daily-digest',
                'scheduled' => true,
                'name' => 'Daily digest',
                'description' => 'Every morning at 09:00 each linked staff member gets their day in one message — visits, open tasks, follow-ups due, yesterday\'s enquiries, quotations, money in and approvals waiting. A muted chat receives its copy when the mute ends.',
                'cadence' => 'Daily 09:00',
                'toggleable' => true,
            ],
            [
                'key' => 'woodex:weekly-digest',
                'scheduled' => true,
                'name' => 'Weekly digest',
                'description' => 'Every Monday at 09:00 the week that just ended in one message — leads and their top sources, won and lost, quotations sent and accepted, money collected, still to collect.',
                'cadence' => 'Mondays 09:00',
                'toggleable' => true,
            ],
            [
                'key' => 'woodex:telegram-flush',
                'scheduled' => true,
                'name' => 'Telegram quiet-hours queue',
                'description' => 'Delivers the messages that waited out a chat\'s mute — keep this on; pause the sending automations instead.',
                'cadence' => 'Every minute',
                'toggleable' => false,
            ],
            [
                'key' => 'woodex:automation-run',
                'scheduled' => true,
                'name' => 'Follow-up sequences',
                'description' => 'The heartbeat that runs every due step of the studio\'s follow-up sequences: waiting periods, WhatsApp templates, tasks, stage moves. Sends respect quiet hours (nothing automated between 21:00 and 09:00 unless the client wrote first) and the daily per-client cap. Every step of every enrolment is logged under Automation → Sequences.',
                'cadence' => 'Every minute',
                'toggleable' => true,
            ],
            [
                'key' => 'woodex:whatsapp-health',
                'scheduled' => true,
                'name' => 'WhatsApp health check',
                'description' => 'A daily read-only look at the studio\'s WhatsApp number: Meta\'s quality rating (alert the same day it drops) and this month\'s message count and cost against the budget you set in Settings → WhatsApp. Nothing is sent to clients.',
                'cadence' => 'Daily 09:10',
                'toggleable' => true,
            ],
            [
                'key' => 'woodex:monthly-report',
                'scheduled' => true,
                'name' => 'Monthly CRM report',
                'description' => 'On the 1st at 09:00 the owner gets the month that just ended in one message — enquiries, quotations, wins, money in, outstanding — with the month before for comparison, archived under this screen.',
                'cadence' => '1st of the month, 09:00',
                'toggleable' => true,
            ],
            [
                'key' => 'woodex:sla-check',
                'scheduled' => true,
                'name' => 'First-response SLA',
                'description' => 'Flags leads nobody contacted in time and alerts the owner and sales managers on Telegram.',
                'cadence' => 'Every minute',
                'toggleable' => true,
            ],
            [
                'key' => 'woodex:reminders',
                'scheduled' => true,
                'name' => 'Task & visit reminders',
                'description' => 'Sends D−1 and 2-hour reminders for tasks and site visits to the assigned staff.',
                'cadence' => 'Every minute',
                'toggleable' => true,
            ],
            [
                'key' => 'woodex:quote-follow-ups',
                'scheduled' => true,
                'name' => 'Quotation follow-up chaser',
                'description' => 'Nudges the team about quotations nobody answered, warns before prices lapse and retires stale ones.',
                'cadence' => 'Daily 09:00',
                'toggleable' => true,
            ],
            [
                'key' => 'woodex:invoice-milestones',
                'scheduled' => true,
                'name' => 'Milestone invoices',
                'description' => 'When a project reaches a milestone on an accepted quotation, its invoice is raised — number, tax, PDF, ledger and sequence, exactly as if Accounts had clicked it. A milestone is billed once.',
                'cadence' => 'Hourly at :15',
                'toggleable' => true,
            ],
            [
                'key' => 'woodex:invoice-recurring',
                'scheduled' => true,
                'name' => 'Recurring invoices',
                'description' => 'Bills a standing charge on its own rhythm — a monthly retainer, a quarterly maintenance contract — with its own number series, PDF and ledger posting. Runs once per period, and stops itself at the schedule\'s end date.',
                'cadence' => 'Daily 09:20',
                'toggleable' => true,
            ],
            [
                'key' => 'woodex:invoice-reminders',
                'scheduled' => true,
                'name' => 'Overdue payment ladder',
                'description' => 'Nudges an invoice 7, 14 and 30 days after it falls due — once per rung. The client sees it on WhatsApp only when they have consented; the studio is always told, with the reason when nothing could be sent.',
                'cadence' => 'Daily 10:00',
                'toggleable' => true,
            ],
            [
                'key' => 'woodex:review-requests',
                'scheduled' => true,
                'name' => 'Review requests',
                'description' => 'Asks a client for a review a few days after a signed handover — consent-checked before anything is sent.',
                'cadence' => 'Daily 10:30',
                'toggleable' => true,
            ],
            [
                'key' => 'woodex:seo-agent',
                'scheduled' => true,
                'name' => 'SEO health check',
                'description' => 'A weekly, read-only look at the website\'s search basics: pages missing a meta description, overlong titles, redirects pointing nowhere — a short list for the owner. Dormant until switched on (A0); it never edits or publishes anything by itself.',
                'cadence' => 'Sundays 08:00',
                'toggleable' => true,
                'default_enabled' => false,
            ],
            [
                'key' => 'woodex:social-agent',
                'scheduled' => true,
                'name' => 'Social post ideas',
                'description' => 'A weekly digest of what is worth posting about — handovers signed, pages published, projects won — sent only to the owner, who decides what to do with it. Dormant until switched on (A0); it never posts anywhere and never writes client-facing copy.',
                'cadence' => 'Mondays 09:30',
                'toggleable' => true,
                'default_enabled' => false,
            ],
            [
                'key' => 'woodex:retry-whatsapp-webhooks',
                'scheduled' => true,
                'name' => 'WhatsApp recovery queue',
                'description' => 'Replays client messages the webhook could not process — keep this on.',
                'cadence' => 'Every minute',
                'toggleable' => false,
            ],
            [
                'key' => 'queue:work --stop-when-empty --max-time=50 --tries=3',
                'scheduled' => true,
                'name' => 'Queue worker',
                'description' => 'Drains queued jobs (notifications, sends) once a minute without a daemon.',
                'cadence' => 'Every minute',
                'toggleable' => false,
            ],
            [
                'key' => 'woodex:heartbeat',
                'scheduled' => true,
                'name' => 'Cron heartbeat',
                'description' => 'Proof every minute that the scheduler itself is alive (System → Health).',
                'cadence' => 'Every minute',
                'toggleable' => false,
            ],
            [
                'key' => 'backup:run --only-db',
                'scheduled' => true,
                'name' => 'Nightly database backup',
                'description' => 'Full database backup at 02:30, cleaned and monitored by the backup package.',
                'cadence' => 'Daily 02:30',
                'toggleable' => false,
            ],
            [
                'key' => 'woodex:prune-task-runs',
                'scheduled' => true,
                'name' => 'Housekeeping',
                'description' => 'Prunes old scheduled-task runs and failed jobs.',
                'cadence' => 'Daily 04:45',
                'toggleable' => false,
            ],
        ];
    }

    /**
     * May this automation run right now? Missing row = the catalog's default: enabled, except the
     * agents that ship dormant (P8.5 A0 — an agent that reaches beyond the studio's own walls
     * starts OFF and waits for the owner's explicit switch). Cached for a minute so the
     * scheduler's per-task checks cost one cache hit, and an owner's toggle lands within a minute.
     */
    public static function enabled(string $key): bool
    {
        return (bool) Cache::remember(
            'woodex:automation.'.$key,
            60,
            static fn (): bool => (bool) (static::query()->where('key', $key)->value('is_enabled') ?? static::defaultEnabled($key)),
        );
    }

    /** The catalog's default state for a key — on, unless the entry ships dormant. */
    public static function defaultEnabled(string $key): bool
    {
        $entry = collect(static::catalog())->firstWhere('key', $key);

        return ! (is_array($entry) && array_key_exists('default_enabled', $entry) && $entry['default_enabled'] === false);
    }

    /**
     * Pause or resume, attributed. Returns the new state.
     */
    public static function toggle(string $key, ?User $by): bool
    {
        // The row starts at the catalog's default (a dormant agent wakes up, the rest pause).
        $automation = static::query()->firstOrCreate(['key' => $key], ['is_enabled' => static::defaultEnabled($key)]);

        $automation->forceFill([
            'is_enabled' => ! $automation->is_enabled,
            'toggled_by' => $by?->id,
            'toggled_at' => now(),
        ])->save();

        Cache::forget('woodex:automation.'.$key);

        return $automation->fresh()->is_enabled;
    }
}
