<?php

declare(strict_types=1);

namespace App\Domain\Bot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing the assistant may state as fact (BOT-003): a page's own words mirrored from the CMS,
 * or a question the studio answered once by hand. Retrieval is deliberately simple — words the
 * visitor used, scored against words in the entry — because the studio's knowledge is small and
 * the wrong answer is worse than a hand-off.
 */
class BotKnowledge extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'page_id' => 'integer',
        ];
    }

    /**
     * The entries most worth feeding the model for one question.
     *
     * @return list<self>
     */
    public static function retrieve(string $question, int $limit = 3): array
    {
        $terms = collect(preg_split('/\s+/u', mb_strtolower(trim($question))) ?: [])
            ->map(static fn (string $term): string => trim($term, ".,!?؛،:;'\"()[]{}—-"))
            ->filter(static fn (string $term): bool => mb_strlen($term) >= 3)
            ->unique()
            ->take(12);

        if ($terms->isEmpty()) {
            return [];
        }

        /** @var list<self> $all */
        $all = self::query()->where('is_active', true)->orderByDesc('id')->get()->all();

        $scored = [];

        foreach ($all as $entry) {
            $haystack = mb_strtolower($entry->title.' '.preg_replace('/\s+/u', ' ', $entry->content));
            $score = 0;

            foreach ($terms as $term) {
                $score += substr_count($haystack, $term);
            }

            if ($score > 0) {
                $scored[] = [$score, $entry];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_slice(array_map(static fn (array $pair): self => $pair[1], $scored), 0, $limit);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
