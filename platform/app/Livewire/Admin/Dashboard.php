<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\System\Control\ControlPanel;
use App\Domain\System\Dashboard\Board;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\DrawnWidget;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Reports\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The control view (deep QA slice D), drawn from widgets (master list T-044; the owner's documents 05 §7 and
 * 07 §1; DL-044).
 *
 * This component knows the page: who looks, which period and business line were chosen, and where each widget
 * stands. What a widget counts, who may see it and where its figures lead is the widget's own
 * (App\Domain\System\Dashboard\Widget); which widgets there are, and how one of them is found on a drawing, is
 * the Board's. No number on this page is typed or estimated, and every figure opens the list of the records it
 * counted.
 *
 * The page is drawn whole in one answer. A widget shows "loading" while Livewire asks again, for another period
 * or after "Try again"; the minute's own refresh (wire:poll) is silent.
 */
#[Layout('layouts::admin')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    /** How much of a row of three a widget takes on a wide screen (Widget::width()). */
    private const TAKES = ['full' => 3, 'wide' => 2, 'narrow' => 1];

    #[Url]
    public string $preset = 'this_month';

    #[Url]
    public string $line = '';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    /**
     * The widgets that were on this person's page when it was opened. One of them that the person may no longer
     * see says "no access" until the page is opened again, instead of vanishing (Board::draw, rule 2). Locked:
     * the browser cannot name widgets here to learn which exist.
     *
     * @var list<string>|null
     */
    #[Locked]
    public ?array $shown = null;

    public function mount(): void
    {
        $this->authorize('dashboard.view');
    }

    /** "Try again" on a widget that could not be loaded: the whole page is asked again. */
    public function refresh(): void
    {
        $this->authorize('dashboard.view');
    }

    public function render(Board $board): View
    {
        $this->authorize('dashboard.view');
        abort_unless(in_array($this->preset, ReportRange::PRESETS, true), 422, 'Choose a valid period.');
        abort_unless($this->line === '' || BusinessLine::tryFrom($this->line) !== null, 422, 'Choose a business line.');
        if ($this->preset === 'custom') {
            $this->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        }
        $range = ReportRange::fromPreset($this->preset, $this->from, $this->to);
        /** @var User $user */
        $user = Auth::user();
        $now = CarbonImmutable::now();

        $sections = $board->draw(
            new DashboardContext($user, $range, BusinessLine::tryFrom($this->line), $now, app(ControlPanel::class), app(SalesSettings::class)->lakh_grouping),
            $this->shown,
        );
        $this->shown ??= $board->keysFor($user);

        $hour = (int) $now->timezone('Asia/Karachi')->format('G');
        $greeting = ($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'))
            .', '.Str::of($user->name)->trim()->before(' ');

        return view('livewire.admin.dashboard', [
            'user' => $user,
            'greeting' => $greeting,
            'range' => $range,
            'sections' => array_map(self::inRows(...), $sections),
        ]);
    }

    /**
     * The widgets of one section, each with how many of a row's three columns it takes. A row that would stay
     * short is filled by its last widget, so a widget that is absent leaves no hole: "Needs attention" takes the
     * whole row for somebody who has no go-live checklist beside it.
     *
     * @param  list<DrawnWidget>  $widgets
     * @return list<array{widget: DrawnWidget, columns: int}>
     */
    private static function inRows(array $widgets): array
    {
        $placed = [];
        $row = [];
        $taken = 0;

        $close = function () use (&$placed, &$row, &$taken): void {
            if ($row !== []) {
                $row[array_key_last($row)]['columns'] += 3 - $taken;
                $placed = [...$placed, ...$row];
            }
            $row = [];
            $taken = 0;
        };

        foreach ($widgets as $widget) {
            $columns = self::TAKES[$widget->width] ?? 3;

            if ($taken + $columns > 3) {
                $close();
            }

            $row[] = ['widget' => $widget, 'columns' => $columns];
            $taken += $columns;
        }

        $close();

        return $placed;
    }
}
