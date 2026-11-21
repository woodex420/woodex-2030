<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard\Widgets;

use App\Domain\System\Control\ControlPanel;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\Figure;
use App\Domain\System\Dashboard\Widget;
use App\Models\User;

/**
 * The six numbers the studio runs on, as they stand now (ControlPanel). Each tile is one query and one click
 * from the list of exactly those records. A tile whose screen the person may not open is left out; a person
 * with none of them does not get the widget.
 *
 * Never "empty": a queue of nought is a figure worth reading ("every enquiry is on schedule").
 */
final class LiveQueues extends Widget
{
    public function key(): string
    {
        return 'live_queues';
    }

    public function title(): string
    {
        return 'Live operating queues';
    }

    public function description(): string
    {
        return 'Across all the records you may see, as they stand at this minute';
    }

    public function visibleTo(User $user): bool
    {
        return in_array(true, app(ControlPanel::class)->sees($user), true);
    }

    public function data(DashboardContext $context): array
    {
        $tiles = [];

        foreach ($context->panel->cards($context->user) as $card) {
            $tiles[] = new Figure(
                key: 'live_queues.'.$card['key'],
                label: $card['label'],
                count: $card['count'],
                href: $card['href'],
                rows: $card['rows'],
                hint: $card['key'] === 'automations_failing' && $card['count'] === 0 ? 'No failures recorded; check task freshness in System.' : $card['hint'],
                tone: $card['tone'],
                icon: $card['icon'],
                display: $card['display'],
            );
        }

        return ['tiles' => $tiles];
    }
}
