<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard;

/**
 * How a widget of the dashboard is drawn (master list T-044; the owner's document 07 §1: "Widget loading,
 * empty, error and no-access states").
 *
 * Four of the five are decided here, on the server, when the dashboard is worked out. The fifth, loading, is not
 * a case: the dashboard is drawn whole in one answer, so a widget is never sent without its figures. Loading is
 * what a widget shows while Livewire asks again (another period, "Try again"): a layer every widget carries, in
 * resources/views/components/admin/widget.blade.php. The decision is DL-044.
 */
enum WidgetState: string
{
    /** Worked out, and there is something to show. */
    case Ready = 'ready';

    /** Worked out, and there is nothing to count: the widget says so in words, it does not show a row of zeros. */
    case Empty = 'empty';

    /** It could not be worked out. It says so itself, with the request number, and the rest of the page stands. */
    case Error = 'error';

    /**
     * The person may not see it. A widget a role may not see is absent from that role's dashboard; this state is
     * for the widget that was on the page when it was opened and whose right was taken away since, and for the
     * one box that tells a person none of the dashboard's figures are open to their role.
     */
    case NoAccess = 'no-access';

    /** Whether the widget's own view is drawn: only then are its figures there to print. */
    public function drawsContent(): bool
    {
        return $this === self::Ready || $this === self::Empty;
    }
}
