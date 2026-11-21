<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard;

use App\Domain\System\Dashboard\Widgets\GoLiveChecklist;
use App\Domain\System\Dashboard\Widgets\Inbox;
use App\Domain\System\Dashboard\Widgets\LiveQueues;
use App\Domain\System\Dashboard\Widgets\Milestones;
use App\Domain\System\Dashboard\Widgets\MoneyOwed;
use App\Domain\System\Dashboard\Widgets\NeedsAttention;
use App\Domain\System\Dashboard\Widgets\NewLeads;
use App\Domain\System\Dashboard\Widgets\PayablesDue;
use App\Domain\System\Dashboard\Widgets\Performance;
use App\Domain\System\Dashboard\Widgets\Pipeline;
use App\Domain\System\Dashboard\Widgets\ProjectHealth;
use App\Domain\System\Dashboard\Widgets\RecentActivity;
use App\Domain\System\Dashboard\Widgets\SalesFunnel;
use App\Models\User;
use App\Support\Modules;
use App\Support\RequestNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * The dashboard's widgets, and how one drawing of the page finds each of them (master list T-044; DL-044).
 *
 * The rules, in the order they are asked:
 *   1. its module is switched off  →  absent;
 *   2. the person may not see it   →  absent; but "no access" where it was on the page when the page was
 *      opened (the right was taken away while the page stood open), so that a box does not vanish unexplained;
 *   3. working it out fails        →  "error", for this widget alone: the failure is reported with the request
 *      number, and every other widget is drawn;
 *   4. there is nothing to count   →  "empty";
 *   5. otherwise                   →  "ready".
 */
final class Board
{
    /**
     * Every widget, by the section of the page it stands in, in the page's order. The sections follow the
     * owner's document 05 §7: the business in a period, what is live now, money, projects, enquiries, the record.
     *
     * @var array<string, list<class-string<Widget>>>
     */
    public const WIDGETS = [
        'period' => [Performance::class, SalesFunnel::class],
        'now' => [LiveQueues::class, NeedsAttention::class, GoLiveChecklist::class],
        'money' => [MoneyOwed::class, PayablesDue::class],
        'projects' => [ProjectHealth::class, Milestones::class],
        'enquiries' => [NewLeads::class, Pipeline::class],
        'record' => [RecentActivity::class, Inbox::class],
    ];

    /**
     * The widgets whose module is switched on, by section. Each is asked of the container, so a test can put
     * another in its place.
     *
     * @return array<string, list<Widget>>
     */
    public function widgets(): array
    {
        $sections = [];

        foreach (self::WIDGETS as $section => $classes) {
            foreach ($classes as $class) {
                $widget = app($class);

                if ($widget->module() === null || Modules::enabled($widget->module())) {
                    $sections[$section][] = $widget;
                }
            }
        }

        return $sections;
    }

    /**
     * The names of the widgets this person's dashboard holds: module on, and theirs to see.
     *
     * @return list<string>
     */
    public function keysFor(User $user): array
    {
        $keys = [];

        foreach ($this->widgets() as $widgets) {
            foreach ($widgets as $widget) {
                if ($widget->visibleTo($user)) {
                    $keys[] = $widget->key();
                }
            }
        }

        return $keys;
    }

    /**
     * One drawing of the dashboard.
     *
     * @param  list<string>|null  $shown  the widgets that were on the page when it was opened; null on opening
     * @return array<string, list<DrawnWidget>> by section; a section without a widget is left out
     */
    public function draw(DashboardContext $context, ?array $shown = null): array
    {
        $drawn = [];

        foreach ($this->widgets() as $section => $widgets) {
            foreach ($widgets as $widget) {
                $one = $this->drawOne($widget, $context, $shown ?? []);

                if ($one !== null) {
                    $drawn[$section][] = $one;
                }
            }
        }

        return $drawn;
    }

    /** @param  list<string>  $shown */
    private function drawOne(Widget $widget, DashboardContext $context, array $shown): ?DrawnWidget
    {
        if (! $widget->visibleTo($context->user)) {
            return in_array($widget->key(), $shown, true) ? DrawnWidget::of($widget, WidgetState::NoAccess) : null;
        }

        try {
            $data = $widget->data($context);

            return DrawnWidget::of($widget, $widget->isEmpty($data) ? WidgetState::Empty : WidgetState::Ready, $data, $widget->figures($data));
        } catch (AuthorizationException) {
            // A right that one of its queries asks for and the person lacks: theirs to be told, not a fault.
            return DrawnWidget::of($widget, WidgetState::NoAccess);
        } catch (Throwable $failure) {
            // One widget's failure is that widget's: it is written to the log under the request number the
            // widget shows, and the page goes on. A dashboard that answers 500 because one count failed hides
            // every other number at the moment somebody most needs them.
            report($failure);

            return DrawnWidget::of($widget, WidgetState::Error, reference: RequestNumber::current());
        }
    }
}
