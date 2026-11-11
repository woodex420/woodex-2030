<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Sales rules the owner can change without a developer (QUO-*, INV-*, CRM-010).
 *
 * Note: never use @var on properties: spatie/laravel-settings parses @var to infer casts and rejects
 * generic types such as array<string, int>. Use @phpstan-var for those (spatie ignores it).
 */
class SalesSettings extends Settings
{
    public int $quote_validity_days;

    /** Chase a quotation nobody answered, and warn before its price lapses (AUT). */
    public bool $quote_follow_ups_enabled;

    public int $quote_follow_up_days;

    public int $quote_expiry_warning_days;

    public bool $round_totals_to_rupee;

    public bool $lakh_grouping;

    public string $execution_terms;

    public string $design_terms;

    /** @phpstan-var array<string, int> role => max discount in basis points without approval */
    public array $discount_limits_bp;

    public ?int $min_margin_bp;

    /** @phpstan-var list<string> */
    public array $lost_reasons;

    /** @phpstan-var array<string, string> key => label */
    public array $budget_bands;

    public int $sla_first_response_minutes;

    /** @phpstan-var array<string, int> lead source => first-response minutes (CRM-010) */
    public array $sla_minutes_by_source;

    /** Minutes after a breach before the second escalation (owner) */
    public int $sla_escalation_minutes;

    /** CNV-007: WhatsApp conversations — target for the first reply after an inbound message. */
    public int $conversation_first_reply_minutes = 30;

    /** CNV-007: WhatsApp conversations — target for every later reply. */
    public int $conversation_next_reply_minutes = 15;

    /** AUT-006: automated messages per client per day, across every sequence. */
    public int $automation_daily_contact_limit = 2;

    /** AUT-006: no automated message between here and quiet_end — unless the client wrote first. */
    public string $automation_quiet_start = '21:00';

    /** AUT-006: the morning the queue resumes. */
    public string $automation_quiet_end = '09:00';

    /**
     * Lead scoring rules (CRM-011), see App\Domain\Crm\Actions\ScoreLead.
     *
     * @phpstan-var array{base: int, budget: array<string, int>, size: list<array{min_sqft: int, points: int}>, services: array<string, int>, lines: array<string, int>, sources: array<string, int>, lead_types: array<string, int>, timelines: array<string, int>, cities: array<string, int>}
     */
    public array $scoring_rules;

    public int $hot_score;

    /** @phpstan-var list<array{name: string, title: string}> */
    public array $signatories;

    public string $invoice_number_format;

    public int $invoice_due_days;

    /** What happens to the advance invoice when a quotation is accepted: draft, issue, or issue and send. */
    public string $advance_invoice_mode;

    public string $invoice_terms;

    /**
     * The overdue ladder's rungs, in days past due (slice E). One reminder per rung, never twice.
     *
     * @phpstan-var list<int>
     */
    public array $invoice_reminder_rungs = [7, 14, 30];

    /** Let the ladder message the client on WhatsApp (still consent-gated); off means the studio is told only. */
    public bool $invoice_reminder_send_client = true;

    /** Announce a project's stage change to the client by email (PRJ-002). */
    public bool $project_stage_notifies_client;

    /**
     * Lines a handover checklist starts with (PRJ-007); the team adds and ticks them on the project.
     *
     * Defaults matter: a settings property missing from the database and without a default is uninitialized and
     * throws when read, which is a public 500 on a server whose Database update has not been run yet. These match
     * database/settings/2026_10_16_100600_add_handover_settings.php.
     *
     * @phpstan-var list<string>
     */
    public array $handover_checklist = [
        'Site cleaned and dusted',
        'Paint and polish touched up',
        'All fittings and fixtures checked',
        'Electrical and lighting tested',
        'Plumbing and sanitary fittings tested',
        'Doors, locks and handles adjusted',
        'Warranty cards and maintenance notes handed over',
        'Spare materials and paint left with the client',
        'Keys handed over',
        'Photographs of the finished space taken',
    ];

    /** Days after the client signs the handover before we ask for a public review (PRJ-007). */
    public int $review_request_days = 3;

    public function slaMinutesFor(string $source): int
    {
        return (int) ($this->sla_minutes_by_source[$source] ?? $this->sla_first_response_minutes);
    }

    public static function group(): string
    {
        return 'sales';
    }
}
