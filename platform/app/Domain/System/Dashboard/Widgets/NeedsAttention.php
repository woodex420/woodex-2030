<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard\Widgets;

use App\Domain\Crm\Models\Lead;
use App\Domain\System\Control\ControlPanel;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\Figure;
use App\Domain\System\Dashboard\Widget;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Str;

/**
 * What is waiting for a person right now: only the lines that are not nought (ControlPanel::attention), each
 * with the button that starts the work and the count that opens its rows, and under them the five overdue
 * follow-ups to touch first.
 */
final class NeedsAttention extends Widget
{
    public function key(): string
    {
        return 'needs_attention';
    }

    public function title(): string
    {
        return 'Needs attention';
    }

    public function description(): string
    {
        return 'Only what is actually waiting — each button opens the screen that filters to it';
    }

    public function visibleTo(User $user): bool
    {
        return in_array(true, app(ControlPanel::class)->sees($user), true);
    }

    public function width(): string
    {
        return 'wide';
    }

    public function data(DashboardContext $context): array
    {
        $user = $context->user;
        $items = [];

        foreach ($context->panel->attention($user) as $item) {
            $items[] = [
                ...$item,
                // Late money and failures count records that have other names than "record".
                'figure' => new Figure(
                    key: 'needs_attention.'.$item['key'],
                    label: $item['title'],
                    count: $item['count'],
                    href: $item['list'],
                    rows: $item['list'] !== null,
                    noun: match ($item['key']) {
                        'overdue_followups' => ['enquiry', 'enquiries'],
                        'awaiting_approval' => ['quotation', 'quotations'],
                        'money_due' => ['late invoice', 'late invoices'],
                        'automations_failing' => ['failure', 'failures'],
                        'automations_stale' => ['silent task', 'silent tasks'],
                        default => ['record', 'records'],
                    },
                ),
            ];
        }

        $first = $user->can('leads.view')
            ? Lead::query()->visibleTo($user)->followUpDue()
                ->with(['contact', 'owner'])
                ->orderByRaw('next_action_at is null')
                ->orderBy('next_action_at')
                ->limit(5)
                ->get()
                ->map(function (Lead $lead): array {
                    $service = $lead->service ? Str::headline($lead->service) : null;

                    return [
                        'id' => $lead->id,
                        'href' => route('admin.leads.show', $lead),
                        'name' => $lead->contact->name,
                        'ref' => $lead->ref,
                        'about' => implode(' · ', array_filter([$lead->business_line->tag(), $service !== null && $service !== $lead->business_line->label() ? $service : '', $lead->owner?->name ?? 'Unassigned'])),
                        'breached' => $lead->isSlaBreached(),
                        'due' => $lead->next_action_at?->diffForHumans(),
                        'whatsapp' => Phone::whatsappUrl($lead->contact->whatsappNumber()),
                        'phone' => $lead->contact->phone,
                    ];
                })->all()
            : [];

        return ['items' => $items, 'first' => $first];
    }

    public function isEmpty(array $data): bool
    {
        return $data['items'] === [] && $data['first'] === [];
    }

    public function emptyWords(array $data = []): string
    {
        return 'No overdue follow-up, no quotation held for a decision, no late invoice and no failed automation.';
    }
}
