<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;
use App\Settings\SalesSettings;

/**
 * /quotes — recent quotations with amount and status (TGM-004). 'pending' narrows to the ones awaiting a
 * client's decision; 'viewed' to the ones the client has already opened.
 */
final class QuotesCommand implements TelegramCommand
{
    private const LIMIT = 8;

    public function __construct(
        private readonly SalesSettings $settings,
    ) {}

    public function name(): string
    {
        return '/quotes';
    }

    public function usage(): string
    {
        return '/quotes [pending|viewed]';
    }

    public function description(): string
    {
        return 'recent quotations, or those pending / viewed';
    }

    public function permission(): string
    {
        return 'quotes.view';
    }

    public function handle(User $user, string $argument): string
    {
        $query = Quote::query()->visibleTo($user)->with('contact:id,name');
        $filter = strtolower(trim($argument));
        $title = 'Recent quotations';

        if ($filter === 'pending') {
            $title = 'Awaiting the client';
            $query->whereIn('status', [QuoteStatus::Sent->value, QuoteStatus::Viewed->value]);
        } elseif ($filter === 'viewed') {
            $title = 'Opened by the client';
            $query->whereNotNull('first_viewed_at')->whereIn('status', [QuoteStatus::Sent->value, QuoteStatus::Viewed->value]);
        }

        $quotes = (clone $query)->latest('id')->limit(self::LIMIT)->get();

        if ($quotes->isEmpty()) {
            return $title.":\n— none";
        }

        $lines = [$title.':'];

        foreach ($quotes as $quote) {
            $lines[] = sprintf(
                '%s · %s · %s · %s',
                $quote->label(),
                $quote->contact->name,
                $quote->total()->format($this->settings->lakh_grouping),
                $quote->status->label(),
            );
        }

        $lines[] = '/quote <number> for one.';

        return implode("\n", $lines);
    }
}
