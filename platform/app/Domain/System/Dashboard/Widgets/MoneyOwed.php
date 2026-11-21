<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard\Widgets;

use App\Domain\Sales\Models\Invoice;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\Figure;
use App\Domain\System\Dashboard\Widget;
use App\Models\User;

/**
 * What clients still owe, by how long ago it fell due (the owner's document 05 §7: "Overdue aging").
 *
 * The five ages are those of the receivables report (Invoice::AGES). Each is counted and summed by
 * Invoice::aged(), the scope the Invoices list asks for `?age=…`, so an age and the rows under it are one
 * query. Every invoice that is owed on is in exactly one age, so the five add up to "all that is owed".
 */
final class MoneyOwed extends Widget
{
    public function key(): string
    {
        return 'money_owed';
    }

    public function title(): string
    {
        return 'Money owed, by age';
    }

    public function description(): string
    {
        return 'What clients still owe on issued invoices, by days past the due date';
    }

    public function visibleTo(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function width(): string
    {
        return 'wide';
    }

    public function data(DashboardContext $context): array
    {
        $ages = [];
        [$allCount, $allMinor, $lateCount, $lateMinor] = [0, 0, 0, 0];

        foreach (Invoice::AGES as $age => $label) {
            $found = Invoice::query()->aged($age)->toBase()->selectRaw('count(*) as invoices, coalesce(sum(balance_minor), 0) as owed')->first();
            [$count, $minor] = [(int) ($found->invoices ?? 0), (int) ($found->owed ?? 0)];

            $ages[] = new Figure(
                key: 'money_owed.'.$age,
                label: $label,
                count: $count,
                href: route('admin.invoices.index', ['age' => $age]),
                amountMinor: $minor,
                noun: ['invoice', 'invoices'],
                tone: match ($age) {
                    'current' => 'navy',
                    'd1_30' => 'warning',
                    default => 'error',
                },
            );

            $allCount += $count;
            $allMinor += $minor;

            if ($age !== 'current') {
                $lateCount += $count;
                $lateMinor += $minor;
            }
        }

        return [
            'ages' => $ages,
            'all' => new Figure('money_owed.open', 'All that is owed', $allCount, route('admin.invoices.index', ['age' => 'open']), amountMinor: $allMinor, noun: ['invoice', 'invoices']),
            'late' => new Figure('money_owed.overdue', 'Past the due date', $lateCount, route('admin.invoices.index', ['age' => 'overdue']), amountMinor: $lateMinor, noun: ['invoice', 'invoices'], tone: $lateMinor > 0 ? 'error' : 'success'),
            'most' => max(1, ...array_map(fn (Figure $age): int => (int) $age->amountMinor, $ages)),
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
        return 'Nothing is owed: no issued invoice has a balance.';
    }
}
