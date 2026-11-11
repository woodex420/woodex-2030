<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Sales\Actions\DecideQuoteApproval;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\System\Models\Approval;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;

/**
 * /approve — decide a quotation approval from the phone (TGM-005/S3).
 *
 * `/approve 12` approves; `/approve 12 no the discount is too deep` declines with the reason the sales person
 * will read. The same rules as the screen apply — including that nobody approves their own request — and the
 * requester is notified either way by the action itself.
 */
final class ApproveCommand implements TelegramCommand
{
    public function __construct(
        private readonly DecideQuoteApproval $decide,
    ) {}

    public function name(): string
    {
        return '/approve';
    }

    public function usage(): string
    {
        return '/approve <id> [no <reason>]';
    }

    public function description(): string
    {
        return 'decide an approval from /approvals';
    }

    public function permission(): string
    {
        return 'quotes.approve';
    }

    public function handle(User $user, string $argument): string
    {
        $parts = preg_split('/\s+/', trim($argument), 3) ?: [];

        if ($parts === [] || $parts[0] === '' || ! ctype_digit($parts[0])) {
            return 'Give the approval number from /approvals, e.g. /approve 12 — or /approve 12 no <reason> to decline.';
        }

        /** @var Approval|null $approval */
        $approval = Approval::query()->with('approvable')->find((int) $parts[0]);

        if ($approval === null) {
            return 'There is no approval #'.$parts[0].'. Send /approvals for the current list.';
        }

        $rest = trim((string) ($parts[1] ?? '').' '.($parts[2] ?? ''));
        $decline = strtolower((string) ($parts[1] ?? '')) === 'no';

        if ($decline && trim((string) ($parts[2] ?? '')) === '') {
            return 'Say why you are declining, so the sales person knows what to change: /approve 12 no <reason>.';
        }

        try {
            $quote = $this->decide->handle($approval, $user, ! $decline, $decline ? trim((string) $parts[2]) : null);
        } catch (QuoteActionDenied $denied) {
            return $denied->getMessage();
        }

        return $decline
            ? sprintf('Declined: %s. The requester has been told why.', $quote->label())
            : sprintf('Approved: %s. The requester has been told they can send it.', $quote->label());
    }
}
