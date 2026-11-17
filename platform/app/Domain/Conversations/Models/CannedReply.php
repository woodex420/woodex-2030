<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Models;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\QuoteLinks;
use App\Settings\BusinessSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A saved reply (CNV-004). Typed with "/" in the inbox composer, picked from a list, filled in for the
 * client on the other end. English and Urdu both live here; the team picks what the client picked.
 *
 * Variables in the body, filled by renderFor(): {first_name} {studio_name} {studio_hours} {quote_link}.
 */
class CannedReply extends Model
{
    public const VARIABLES = ['{first_name}', '{studio_name}', '{studio_hours}', '{quote_link}'];

    protected $guarded = ['id', 'usage_count'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'usage_count' => 'integer',
            'is_active' => 'bool',
            'position' => 'integer',
        ];
    }

    /**
     * @param  Builder<CannedReply>  $query
     * @return Builder<CannedReply>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The replies matching what was typed after "/": title, keywords or body, case-insensitive.
     *
     * @return list<self>
     */
    public static function matchesFor(string $term, int $limit = 6): array
    {
        $term = trim(mb_strtolower($term));

        return static::query()
            ->active()
            ->when($term !== '', function (Builder $query) use ($term): void {
                $like = '%'.$term.'%';

                $query->where(fn (Builder $q) => $q
                    ->whereRaw('lower(title) like ?', [$like])
                    ->orWhereRaw('lower(keywords) like ?', [$like])
                    ->orWhereRaw('lower(body) like ?', [$like]));
            })
            ->orderBy('position')
            ->orderBy('title')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Fill the variables for one conversation — the whole point of a canned reply: personal without the typing.
     * {quote_link} is the client's latest sent quotation (the link always shows the newest revision, QUO-009).
     */
    public function renderFor(Conversation $conversation, BusinessSettings $business): string
    {
        $contact = $conversation->contact;
        $hours = $business->hours();

        $quote = Quote::query()
            ->where('contact_id', $conversation->contact_id)
            ->whereIn('status', [QuoteStatus::Sent->value, QuoteStatus::Viewed->value, QuoteStatus::Accepted->value, QuoteStatus::Expired->value])
            ->orderByDesc('sent_at')
            ->first();

        $values = [
            '{first_name}' => $contact !== null ? Str::before($contact->name ?? '', ' ') : '',
            '{studio_name}' => $business->brand_name,
            '{studio_hours}' => self::hoursSummary($hours->opensAt, $hours->closesAt, $hours->workingDays),
            '{quote_link}' => $quote !== null ? QuoteLinks::portal($quote) : '',
        ];

        return str_replace(array_keys($values), $values, $this->body);
    }

    /**
     * "Mon–Sat, 10:00–19:00" — the studio's open hours in one readable line.
     *
     * @param  list<int>  $workingDays  ISO day numbers
     */
    public static function hoursSummary(string $opensAt, string $closesAt, array $workingDays): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $days = array_values(array_intersect([1, 2, 3, 4, 5, 6, 7], $workingDays));

        if ($days === []) {
            return $opensAt.'–'.$closesAt;
        }

        // Compress consecutive days: [1,2,3,4,5,6] → Mon–Sat, [1,2,4,5] → Mon–Tue, Thu–Fri.
        $ranges = [];
        $start = $previous = $days[0];

        foreach (array_slice($days, 1) as $day) {
            if ($day === $previous + 1) {
                $previous = $day;

                continue;
            }

            $ranges[] = [$start, $previous];
            $start = $previous = $day;
        }

        $ranges[] = [$start, $previous];

        $summary = implode(', ', array_map(
            static fn (array $range): string => $range[0] === $range[1] ? $names[$range[0]] : $names[$range[0]].'–'.$names[$range[1]],
            $ranges,
        ));

        return $summary.', '.$opensAt.'–'.$closesAt;
    }
}
