<?php

declare(strict_types=1);

namespace App\Domain\System\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The saved monthly CRM report (P8.5): what the owner was sent on the 1st of each month. The
 * payload is the computation, not a screenshot — the Automations screen renders it, so the
 * archive and the Telegram message can never disagree.
 *
 * @property int $id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property array<string, mixed> $payload
 * @property ?CarbonImmutable $notified_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class MonthlyReport extends Model
{
    protected $fillable = ['period_start', 'period_end', 'payload', 'notified_at'];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'payload' => 'array',
            'notified_at' => 'immutable_datetime',
        ];
    }

    /** "September 2026" — the label every surface (Telegram, mail, screen) shows for this row. */
    public function periodLabel(): string
    {
        return $this->period_start->translatedFormat('F Y');
    }

    /**
     * The headline chips the archive list shows next to each month.
     *
     * @return array<string, int>
     */
    public function headline(): array
    {
        $funnel = $this->payload['funnel'] ?? [];
        $money = $this->payload['money'] ?? [];

        return [
            'leads' => (int) ($funnel['leads'] ?? 0),
            'quotes_sent' => (int) ($funnel['quotes_sent'] ?? 0),
            'won' => (int) ($funnel['won'] ?? 0),
            'invoiced_minor' => (int) ($money['invoiced_minor'] ?? 0),
            'collected_minor' => (int) ($money['collected_minor'] ?? 0),
        ];
    }
}
