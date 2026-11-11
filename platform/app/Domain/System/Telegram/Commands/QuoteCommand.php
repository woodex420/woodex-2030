<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Sales\Models\Quote;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;
use App\Settings\SalesSettings;

/**
 * /quote — one quotation by its number, e.g. /quote WI-10123 (TGM-004).
 */
final class QuoteCommand implements TelegramCommand
{
    public function __construct(
        private readonly SalesSettings $settings,
    ) {}

    public function name(): string
    {
        return '/quote';
    }

    public function usage(): string
    {
        return '/quote <number>';
    }

    public function description(): string
    {
        return 'one quotation: amount, status, validity, first view';
    }

    public function permission(): string
    {
        return 'quotes.view';
    }

    public function handle(User $user, string $argument): string
    {
        $number = strtoupper(trim($argument));

        if ($number === '') {
            return 'Give a quotation number, e.g. /quote WI-10123.';
        }

        /** @var Quote|null $quote */
        $quote = Quote::query()->visibleTo($user)
            ->with('contact:id,name', 'owner:id,name')
            ->where('number', $number)
            ->orderByDesc('id')
            ->first();

        if ($quote === null) {
            return "No quotation {$number} within your leads. Check the number with /quotes.";
        }

        $lines = [
            sprintf('%s · %s', $quote->label(), $quote->contact->name),
            sprintf('Amount: %s · %s', $quote->total()->format($this->settings->lakh_grouping), $quote->status->label()),
        ];

        if ($quote->owner !== null) {
            $lines[] = 'Owner: '.$quote->owner->name;
        }

        if ($quote->sent_at !== null) {
            $lines[] = 'Sent: '.$quote->sent_at->format('D d M H:i');
        }

        if ($quote->first_viewed_at !== null) {
            $lines[] = 'First opened: '.$quote->first_viewed_at->format('D d M H:i');
        }

        if ($quote->valid_until !== null) {
            $lines[] = 'Valid until: '.$quote->valid_until->format('d M').($quote->valid_until->isPast() ? ' (lapsed)' : '');
        }

        return implode("\n", $lines);
    }
}
