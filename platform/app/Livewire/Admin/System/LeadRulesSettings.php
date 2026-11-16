<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\LeadSource;
use App\Livewire\Admin\Crm\LeadShow;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Settings → Lead rules: first-response targets per source and the escalation delay (CRM-010), and the
 * lead scoring rules (CRM-011). Saving does not rescore existing leads; new activity rescored them.
 */
class LeadRulesSettings extends Component
{
    use InteractsWithAdmin;

    /** @var array{default: int|string, escalation: int|string, by_source: array<string, int|string>} */
    public array $sla = ['default' => 15, 'escalation' => 60, 'by_source' => []];

    /** @var array<string, mixed> */
    public array $scoring = [];

    public function mount(SalesSettings $settings): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $rules = $settings->scoring_rules;
        $this->sla = [
            'default' => $settings->sla_first_response_minutes,
            'escalation' => $settings->sla_escalation_minutes,
            'by_source' => array_map('strval', $settings->sla_minutes_by_source),
        ];
        $this->scoring = [
            'base' => (string) $rules['base'],
            'hot' => (string) $settings->hot_score,
            'budget' => array_map('strval', $rules['budget']),
            'size' => array_map(fn (array $row): array => ['min_sqft' => (string) $row['min_sqft'], 'points' => (string) $row['points']], $rules['size']),
            'lines' => array_map('strval', $rules['lines']),
            'sources' => array_map('strval', $rules['sources']),
            'lead_types' => array_map('strval', $rules['lead_types']),
            'timelines' => array_map('strval', $rules['timelines']),
            'services' => self::mapToText($rules['services']),
            'cities' => self::mapToText($rules['cities']),
        ];
    }

    public function save(SalesSettings $settings): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $points = ['nullable', 'integer', 'between:-50,100'];
        $this->validate([
            'sla.default' => ['required', 'integer', 'between:5,1440'],
            'sla.escalation' => ['required', 'integer', 'between:5,1440'],
            'sla.by_source.*' => ['nullable', 'integer', 'between:5,1440'],
            'scoring.base' => ['required', 'integer', 'between:0,100'],
            'scoring.hot' => ['required', 'integer', 'between:1,100'],
            'scoring.budget.*' => $points,
            'scoring.size.*.min_sqft' => ['nullable', 'integer', 'between:1,1000000'],
            'scoring.size.*.points' => $points,
            'scoring.lines.*' => $points,
            'scoring.sources.*' => $points,
            'scoring.lead_types.*' => $points,
            'scoring.timelines.*' => $points,
            'scoring.services' => ['nullable', 'string', 'max:3000'],
            'scoring.cities' => ['nullable', 'string', 'max:3000'],
        ], attributes: [
            'sla.default' => 'default target', 'sla.escalation' => 'escalation delay', 'sla.by_source.*' => 'target',
            'scoring.hot' => 'hot threshold', 'scoring.size.*.min_sqft' => 'size', 'scoring.*.*' => 'points',
        ]);

        $services = self::textToMap((string) ($this->scoring['services'] ?? ''));
        $cities = self::textToMap((string) ($this->scoring['cities'] ?? ''));

        if ($services === null || $cities === null) {
            $this->addError($services === null ? 'scoring.services' : 'scoring.cities', 'Write one rule per line as "name = points", e.g. turnkey = 10.');

            return;
        }

        $ints = fn (array $values): array => array_map('intval', array_filter($values, fn ($v): bool => $v !== '' && $v !== null && (int) $v !== 0));

        $size = collect((array) $this->scoring['size'])
            ->filter(fn (array $row): bool => ($row['min_sqft'] ?? '') !== '' && ($row['points'] ?? '') !== '')
            ->map(fn (array $row): array => ['min_sqft' => (int) $row['min_sqft'], 'points' => (int) $row['points']])
            ->sortByDesc('min_sqft')
            ->values()
            ->all();

        $settings->sla_first_response_minutes = (int) $this->sla['default'];
        $settings->sla_escalation_minutes = (int) $this->sla['escalation'];
        $settings->sla_minutes_by_source = array_map('intval', array_filter((array) $this->sla['by_source'], fn ($v): bool => $v !== '' && $v !== null));
        $settings->scoring_rules = [
            'base' => (int) $this->scoring['base'],
            'budget' => $ints((array) $this->scoring['budget']),
            'size' => $size,
            'services' => $services,
            'lines' => $ints((array) $this->scoring['lines']),
            'sources' => $ints((array) $this->scoring['sources']),
            'lead_types' => $ints((array) $this->scoring['lead_types']),
            'timelines' => $ints((array) $this->scoring['timelines']),
            'cities' => $cities,
        ];
        $settings->hot_score = (int) $this->scoring['hot'];
        $settings->save();

        $this->toast('Lead rules saved. New and updated leads use them from now on.');
    }

    public function render(): View
    {
        return view('livewire.admin.system.lead-rules-settings', [
            'sources' => LeadSource::options(),
            'lines' => BusinessLine::options(),
            'bands' => app(SalesSettings::class)->budget_bands,
            'timelines' => LeadShow::timelines(),
        ]);
    }

    /**
     * @param  array<string, int>  $map
     */
    private static function mapToText(array $map): string
    {
        return collect($map)->map(fn (int $points, string $key): string => "{$key} = {$points}")->implode("\n");
    }

    /**
     * @return array<string, int>|null
     */
    private static function textToMap(string $text): ?array
    {
        $map = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (! preg_match('/^(.{1,60}?)\s*[=:]\s*(-?\d{1,3})$/u', $line, $m)) {
                return null;
            }

            $map[mb_strtolower(trim($m[1]))] = (int) $m[2];
        }

        return $map;
    }
}
