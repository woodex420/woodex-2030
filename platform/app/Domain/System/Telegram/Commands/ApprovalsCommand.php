<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Sales\Models\Quote;
use App\Domain\System\Enums\ApprovalStatus;
use App\Domain\System\Models\Approval;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Money;

/**
 * /approvals — what is waiting for a decision (TGM-004/S3): discount and low-margin requests on quotations.
 * The decision itself is /approve <id>.
 */
final class ApprovalsCommand implements TelegramCommand
{
    public function __construct(
        private readonly SalesSettings $settings,
    ) {}

    public function name(): string
    {
        return '/approvals';
    }

    public function usage(): string
    {
        return '/approvals';
    }

    public function description(): string
    {
        return 'quotations waiting for your approval';
    }

    public function permission(): string
    {
        return 'quotes.approve';
    }

    public function handle(User $user, string $argument): string
    {
        $approvals = Approval::query()
            ->where('status', ApprovalStatus::Pending->value)
            ->whereHasMorph('approvable', Quote::class)
            ->with(['approvable:id,number,revision,option_label,total_minor', 'requester:id,name'])
            ->latest('id')
            ->limit(8)
            ->get();

        if ($approvals->isEmpty()) {
            return 'Nothing is waiting for approval.';
        }

        $lines = ['Waiting for approval:'];

        foreach ($approvals as $approval) {
            $quote = $approval->approvable;

            if (! $quote instanceof Quote) {
                continue;
            }

            $lines[] = sprintf(
                '#%d · %s · %s · asked by %s',
                $approval->id,
                $quote->label(),
                Money::ofMinor((int) $quote->total_minor)->format($this->settings->lakh_grouping),
                $approval->requester !== null ? $approval->requester->name : 'someone',
            );

            if ($approval->reason !== null && $approval->reason !== '') {
                $lines[] = '  why: '.$approval->reason;
            }
        }

        $lines[] = 'Decide with /approve <number>, or /approve <number> no <reason> to decline.';

        return implode("\n", $lines);
    }
}
