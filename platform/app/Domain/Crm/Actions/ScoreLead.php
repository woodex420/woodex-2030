<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\Lead;
use App\Settings\SalesSettings;

/**
 * Lead score 0–100 (CRM-011) from the rules in Settings → Sales → Lead scoring: budget band, size,
 * service, business line, city, source / lead type (the higher of the two) and timeline.
 * A score at or above the "hot" threshold shows as Hot and sorts first on the board.
 */
final class ScoreLead
{
    public function __construct(private readonly SalesSettings $settings) {}

    public function score(Lead $lead): int
    {
        $rules = $this->settings->scoring_rules;

        $score = (int) ($rules['base'] ?? 0);
        $score += (int) ($rules['budget'][(string) $lead->budget_band] ?? 0);

        $sqft = (int) ($lead->area_sqft ?? 0);
        foreach ($rules['size'] ?? [] as $band) {
            if ($sqft > 0 && $sqft >= (int) $band['min_sqft']) {
                $score += (int) $band['points'];
                break;
            }
        }

        $score += (int) ($rules['services'][(string) $lead->service] ?? 0);
        $score += (int) ($rules['lines'][self::key($lead->getAttribute('business_line'))] ?? 0);
        $score += $this->cityPoints($rules['cities'] ?? [], (string) $lead->city);
        $score += max(
            (int) ($rules['sources'][self::key($lead->getAttribute('source'))] ?? 0),
            (int) ($rules['lead_types'][self::key($lead->getAttribute('lead_type'))] ?? 0),
        );
        $score += (int) ($rules['timelines'][(string) $lead->timeline] ?? 0);

        return max(0, min(100, $score));
    }

    public function isHot(Lead $lead): bool
    {
        return $lead->score >= $this->settings->hot_score;
    }

    /**
     * Enum or raw value as a rule key; unsaved leads may not have every attribute yet.
     */
    private static function key(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    /**
     * @param  array<string, int>  $cities
     */
    private function cityPoints(array $cities, string $city): int
    {
        foreach ($cities as $name => $points) {
            if ($city !== '' && strcasecmp(trim($name), trim($city)) === 0) {
                return (int) $points;
            }
        }

        return 0;
    }
}
