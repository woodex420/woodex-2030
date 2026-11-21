<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard\Widgets;

use App\Domain\Accounts\Models\Bill;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\Figure;
use App\Domain\System\Dashboard\Widget;
use App\Models\User;

/**
 * What the studio owes its vendors and when (the owner's document 05 §7: "Payables due"): what is past its due
 * date, what falls due within a week, and all that is owed. Each is Bill::due(), the scope the payables screen
 * asks for `?due=…`. The balance of a bill is worked out as that screen works it out (Bill::balanceMinor()).
 */
final class PayablesDue extends Widget
{
    public function key(): string
    {
        return 'payables_due';
    }

    public function title(): string
    {
        return 'Payables due';
    }

    public function description(): string
    {
        return 'What we owe our vendors, by the due date of each bill';
    }

    public function visibleTo(User $user): bool
    {
        return $user->can('accounts.view');
    }

    public function width(): string
    {
        return 'narrow';
    }

    public function data(DashboardContext $context): array
    {
        $figure = function (string $when, string $label, string $tone, array $address): Figure {
            $bills = Bill::query()->due($when)->get(['id', 'total_minor', 'paid_minor']);

            return new Figure(
                key: 'payables_due.'.$when,
                label: $label,
                count: $bills->count(),
                href: route('admin.accounts.payables', ['tab' => 'aging', ...$address]),
                amountMinor: (int) $bills->sum(fn (Bill $bill): int => $bill->balanceMinor()),
                noun: ['bill', 'bills'],
                tone: $bills->isEmpty() ? 'success' : $tone,
            );
        };

        return [
            'overdue' => $figure('overdue', 'Past the due date', 'error', ['due' => 'overdue']),
            'soon' => $figure('soon', 'Due within '.Bill::DUE_SOON_DAYS.' days', 'warning', ['due' => 'soon']),
            'all' => $figure('open', 'All that is owed', 'navy', []),
            'as_of' => $context->now->format('j M Y'),
            'lakh' => $context->lakh,
        ];
    }

    public function isEmpty(array $data): bool
    {
        return $data['all']->count === 0;
    }

    public function emptyWords(array $data = []): string
    {
        return 'Nothing is owed to a vendor: every received bill is paid, or none has been entered yet.';
    }
}
