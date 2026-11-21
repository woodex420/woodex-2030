<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard;

use App\Models\User;

/**
 * One block of the dashboard (master list T-044; the owner's documents 05 §7 and 07 §1).
 *
 * The contract every block keeps:
 *   · who may see it (visibleTo) and with which optional module (module). A widget whose module is switched
 *     off, or that the person's role may not see, is absent from the page: not empty, not greyed out;
 *   · its figures, each with the list of the records it counted (Figure);
 *   · its states (WidgetState): ready, empty, error and no access, decided by the Board; loading is the layer
 *     the frame shows while Livewire asks again.
 *
 * data() does everything that can fail: every query, every sum, every address. The view only prints what it is
 * handed. That is what lets the Board catch a failure for one widget and draw the others (Board::draw).
 *
 * A new widget: a class here, a view in resources/views/admin/dashboard/widgets/, and a line in Board::WIDGETS.
 * tests/Feature/Overview/DashboardWidgetsTest.php then asks of it what it asks of every widget.
 */
abstract class Widget
{
    /** Its name in the page and in the tests: `money_owed`. Also names its view, `money-owed`. */
    abstract public function key(): string;

    /** Its heading. */
    abstract public function title(): string;

    /** Whether this person may see it at all. Asks only the person's rights: no query of records. */
    abstract public function visibleTo(User $user): bool;

    /**
     * Everything its view prints, already worked out. A Figure anywhere in it is found by figures().
     *
     * @return array<string, mixed>
     */
    abstract public function data(DashboardContext $context): array;

    /** The optional module it belongs to (config/modules.php), or null for the core. Off: the widget is absent. */
    public function module(): ?string
    {
        return null;
    }

    /** One line under the heading: what the widget is about. */
    public function description(): string
    {
        return '';
    }

    /** Whether its figures change with the period and the business line chosen at the top of the page. */
    public function followsPeriod(): bool
    {
        return false;
    }

    /**
     * Whether there is nothing to count. The widget is then drawn as empty, with these words.
     *
     * @param  array<string, mixed>  $data
     */
    public function isEmpty(array $data): bool
    {
        return false;
    }

    /**
     * What an empty widget says: what is missing, and what would put something here. It is handed what data()
     * found, so a widget that counts over dates can name them: an empty widget shows no other line about itself.
     *
     * @param  array<string, mixed>  $data
     */
    public function emptyWords(array $data = []): string
    {
        return 'Nothing to show yet.';
    }

    /** How much of a row it takes on a wide screen: `full`, `wide` (two thirds) or `narrow` (one third). */
    public function width(): string
    {
        return 'full';
    }

    /** The view that prints it. */
    public function view(): string
    {
        return 'admin.dashboard.widgets.'.str_replace('_', '-', $this->key());
    }

    /**
     * Every figure in what data() returned, wherever it stands.
     *
     * @param  array<string, mixed>  $data
     * @return list<Figure>
     */
    final public function figures(array $data): array
    {
        $found = [];
        array_walk_recursive($data, function (mixed $value) use (&$found): void {
            if ($value instanceof Figure) {
                $found[] = $value;
            }
        });

        return $found;
    }
}
