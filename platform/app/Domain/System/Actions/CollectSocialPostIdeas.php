<?php

declare(strict_types=1);

namespace App\Domain\System\Actions;

use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Models\Project;
use App\Domain\Website\Models\Page;
use Carbon\CarbonImmutable;

/**
 * The social agent's rule-based core (P8.5): what happened this week that is worth telling people
 * about — handovers signed, pages published, projects won. It is a digest for the owner alone:
 * it never posts, never drafts client-facing copy, and never names a client (the owner decides
 * what to share and how). The future LLM layer, if ever switched on, starts from this same list.
 */
final class CollectSocialPostIdeas
{
    /**
     * @return array{handovers: list<string>, fresh_pages: list<string>, won: int}
     */
    public function handle(): array
    {
        $since = CarbonImmutable::now()->subDays(7);

        $handovers = Project::query()
            ->whereNotNull('client_ok_at')
            ->whereBetween('client_ok_at', [$since, CarbonImmutable::now()])
            ->orderBy('client_ok_at')
            ->pluck('name')
            ->values()->all();

        $freshPages = Page::query()
            ->published()
            ->where(function ($query) use ($since): void {
                $query->where('updated_at', '>=', $since)->orWhere('published_at', '>=', $since);
            })
            ->orderByDesc('updated_at')
            ->pluck('title')
            ->values()->all();

        $won = Lead::query()
            ->whereBetween('won_at', [$since, CarbonImmutable::now()])
            ->count();

        return [
            'handovers' => $handovers,
            'fresh_pages' => $freshPages,
            'won' => (int) $won,
        ];
    }

    /**
     * The ideas as the digest's plain lines.
     *
     * @param  array{handovers: list<string>, fresh_pages: list<string>, won: int}  $ideas
     * @return list<string>
     */
    public function lines(array $ideas): array
    {
        $lines = [];

        if ($ideas['handovers'] !== []) {
            $lines[] = sprintf('🔑 %d handover(s) signed this week — a finished space is the strongest post there is: %s', count($ideas['handovers']), implode(', ', array_slice($ideas['handovers'], 0, 4)));
        }

        if ((int) $ideas['won'] > 0) {
            $lines[] = sprintf('🤝 %d project(s) won — a "we are starting" story works without naming anyone.', (int) $ideas['won']);
        }

        if ($ideas['fresh_pages'] !== []) {
            $lines[] = sprintf('📄 New or refreshed page(s) worth sharing: %s', implode(', ', array_slice($ideas['fresh_pages'], 0, 4)));
        }

        if ($lines === []) {
            $lines[] = '💤 A quiet week — nothing new to post about. The website pages and finished projects are always worth a re-share.';
        }

        return $lines;
    }
}
