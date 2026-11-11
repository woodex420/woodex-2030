<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\EstimatorRate;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * What a website cost-estimator enquiry actually told us (QUO-017).
 *
 * The public estimator sends three things — service, area and finish — plus the range it showed the
 * visitor. They arrive in `meta.fields` from the bridge and in `meta.estimate` from the importer, so
 * both places are read here, once, instead of in every screen that wants to price the enquiry.
 *
 * `rate` is null when the service is unknown or retired: a screen must not invent a price from a
 * rate the rate book no longer lists.
 */
final class EstimatorLeadDetails
{
    /**
     * @return array{service: string, area: float, finish: string, estimate: string, rate: ?EstimatorRate}
     */
    public function read(Lead $lead): array
    {
        $fields = array_merge(
            (array) ($lead->meta['fields'] ?? []),
            (array) ($lead->meta['estimate'] ?? []),
        );

        // Legacy estimator enquiries without extras may use the area column. Partial metadata must
        // supply its own valid area: never fill a missing size from a potentially stale column.
        // The service column has a separate bridge fallback below.
        $useColumns = $fields === [] && $lead->source === LeadSource::Estimator;

        // The live bridge keeps service on the lead, while area/finish/range are extras. A missing
        // metadata service may use that column for estimator leads only. Explicit values (including
        // blank/null/unknown services) stay authoritative; never substitute a different billable rate.
        $useServiceColumn = ! array_key_exists('service', $fields) && $lead->source === LeadSource::Estimator;
        $service = trim((string) Arr::get($fields, 'service', ''))
            ?: ($useServiceColumn ? trim((string) $lead->service) : '');
        $finish = trim((string) Arr::get($fields, 'finish', '')) ?: 'standard';
        $areaRaw = trim((string) Arr::get($fields, 'area', ''));
        try {
            $area = EstimateQuantity::parse($areaRaw !== '' ? $areaRaw : ($useColumns ? (string) ($lead->area_sqft ?? 0) : '0'));
        } catch (ValidationException) {
            $area = 0.0;
        }
        $estimate = trim((string) Arr::get($fields, 'estimate', ''));

        $rate = $service === ''
            ? null
            : EstimatorRate::query()->where('key', $service)->where('is_active', true)->first()
                ?? EstimatorRate::query()->where('label', $service)->where('is_active', true)->first();

        return ['service' => $service, 'area' => $area, 'finish' => $finish, 'estimate' => $estimate, 'rate' => $rate];
    }

    /** Whether this enquiry carries an estimate worth turning into a quotation. */
    public function available(Lead $lead): bool
    {
        $read = $this->read($lead);

        return $read['rate'] !== null && $read['area'] > 0;
    }
}
