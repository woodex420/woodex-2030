<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The sales funnel (RPT-002): where enquiries come from, which of them become quotations, which quotations
 * are won, and the honest reasons the rest are lost. The same numbers under the screen and its CSV.
 */
final class SalesFunnelReport
{
    /**
     * @return array<string, mixed>
     */
    public function handle(ReportRange $range): array
    {
        return [
            'headline' => $this->headline($range),
            'previous' => $this->headline($range->previous()),
            'by_source' => $this->bySource($range),
            'by_line' => $this->byLine($range),
            'by_owner' => $this->byOwner($range),
            'drop_offs' => $this->dropOffs($range),
        ];
    }

    /**
     * @return array{leads: int, quotes_sent: int, accepted: int, accepted_value_minor: int, won: int, lost: int, win_rate: int}
     */
    public function headline(ReportRange $range): array
    {
        $leads = Lead::query()->whereBetween('created_at', [$range->from, $range->to])->count();
        $quotesSent = Quote::query()
            ->whereNot('status', QuoteStatus::Void->value)
            ->whereBetween('sent_at', [$range->from, $range->to])
            ->count();
        $accepted = Quote::query()
            ->where('status', QuoteStatus::Accepted->value)
            ->whereBetween('accepted_at', [$range->from, $range->to]);
        $acceptedCount = (clone $accepted)->count();
        $acceptedValue = (int) (clone $accepted)->sum('total_minor');
        $won = Lead::query()->whereBetween('won_at', [$range->from, $range->to])->count();
        $lost = Lead::query()->whereBetween('lost_at', [$range->from, $range->to])->count();

        return [
            'leads' => $leads,
            'quotes_sent' => $quotesSent,
            'accepted' => $acceptedCount,
            'accepted_value_minor' => $acceptedValue,
            'won' => $won,
            'lost' => $lost,
            'win_rate' => $quotesSent > 0 ? (int) round($acceptedCount * 100 / $quotesSent) : 0,
        ];
    }

    /**
     * Enquiries by source with how many became accepted quotations (RPT-002: "which source is worth the
     * spend" — the question that decides next month's marketing).
     *
     * @return list<array{label: string, leads: int, quotes_sent: int, accepted: int, accepted_value_minor: int, win_rate: int}>
     */
    public function bySource(ReportRange $range): array
    {
        $leads = Lead::query()
            ->whereBetween('created_at', [$range->from, $range->to])
            ->selectRaw('source, count(*) as leads')
            ->groupBy('source')
            ->pluck('leads', 'source');

        return $this->dimension($range, $leads, 'leads.source');
    }

    /**
     * @return list<array{label: string, leads: int, quotes_sent: int, accepted: int, accepted_value_minor: int, win_rate: int}>
     */
    public function byLine(ReportRange $range): array
    {
        $leads = Lead::query()
            ->whereBetween('created_at', [$range->from, $range->to])
            ->selectRaw('business_line, count(*) as leads')
            ->groupBy('business_line')
            ->pluck('leads', 'business_line');

        return $this->dimension($range, $leads, 'quotes.business_line');
    }

    /**
     * @return list<array{label: string, leads: int, quotes_sent: int, accepted: int, accepted_value_minor: int, win_rate: int}>
     */
    public function byOwner(ReportRange $range): array
    {
        $leads = Lead::query()
            ->whereBetween('created_at', [$range->from, $range->to])
            ->whereNotNull('owner_id')
            ->selectRaw('owner_id, count(*) as leads')
            ->groupBy('owner_id')
            ->pluck('leads', 'owner_id');

        $owners = $this->dimension($range, $leads, 'leads.owner_id');
        $names = DB::table('users')->whereIn('id', array_keys($leads->toArray()))->pluck('name', 'id');

        foreach ($owners as &$row) {
            $row['label'] = (string) ($names[(int) $row['label']] ?? $row['label']);
        }

        return $owners;
    }

    /**
     * Why leads were lost in the period — the studio's own words, counted.
     *
     * @return list<array{reason: string, count: int}>
     */
    public function dropOffs(ReportRange $range): array
    {
        return Lead::query()
            ->whereBetween('lost_at', [$range->from, $range->to])
            ->whereNotNull('lost_reason')
            ->selectRaw('lost_reason, count(*) as aggregate')
            ->groupBy('lost_reason')
            ->orderByDesc('aggregate')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => ['reason' => (string) $row->lost_reason, 'count' => (int) $row->getAttribute('aggregate')])
            ->all();
    }

    /**
     * A shared one-dimension table: leads per bucket, quotations sent and accepted per bucket.
     *
     * @param  Collection<string, int>  $leads  bucket key => lead count
     * @return list<array{label: string, leads: int, quotes_sent: int, accepted: int, accepted_value_minor: int, win_rate: int}>
     */
    private function dimension(ReportRange $range, Collection $leads, string $quotesColumn): array
    {
        $sent = DB::table('quotes')
            ->whereNot('status', QuoteStatus::Void->value)
            ->whereBetween('sent_at', [$range->from, $range->to]);

        if ($quotesColumn === 'quotes.business_line') {
            $sent->selectRaw('business_line as bucket, count(*) as aggregate')
                ->groupBy('business_line');
        } else {
            $sent->join('leads', 'quotes.lead_id', '=', 'leads.id')
                ->selectRaw($quotesColumn.' as bucket, count(*) as aggregate')
                ->groupBy($quotesColumn);
        }

        $sentByBucket = (clone $sent)->pluck('aggregate', 'bucket');

        $accepted = DB::table('quotes')
            ->where('status', QuoteStatus::Accepted->value)
            ->whereBetween('accepted_at', [$range->from, $range->to]);

        if ($quotesColumn === 'quotes.business_line') {
            $accepted->selectRaw('business_line as bucket, count(*) as count, sum(total_minor) as value')
                ->groupBy('business_line');
        } else {
            $accepted->join('leads', 'quotes.lead_id', '=', 'leads.id')
                ->selectRaw($quotesColumn.' as bucket, count(*) as count, sum(total_minor) as value')
                ->groupBy($quotesColumn);
        }

        $acceptedByBucket = (clone $accepted)->get()->keyBy('bucket');

        $keys = $leads->keys()
            ->merge($sentByBucket->keys())
            ->merge($acceptedByBucket->keys())
            ->unique()
            ->values();

        $rows = [];

        foreach ($keys as $key) {
            $sentCount = (int) ($sentByBucket[$key] ?? 0);
            $acceptedCount = (int) ($acceptedByBucket[$key]->count ?? 0);

            $rows[] = [
                'label' => $this->bucketLabel((string) $key, $quotesColumn),
                'leads' => (int) ($leads[$key] ?? 0),
                'quotes_sent' => $sentCount,
                'accepted' => $acceptedCount,
                'accepted_value_minor' => (int) ($acceptedByBucket[$key]->value ?? 0),
                'win_rate' => $sentCount > 0 ? (int) round($acceptedCount * 100 / $sentCount) : 0,
            ];
        }

        return collect($rows)->sortByDesc('leads')->values()->all();
    }

    private function bucketLabel(string $key, string $quotesColumn): string
    {
        if ($quotesColumn === 'leads.source' && LeadSource::tryFrom($key) !== null) {
            return LeadSource::from($key)->label();
        }

        return $key === '' ? '—' : $key;
    }
}
