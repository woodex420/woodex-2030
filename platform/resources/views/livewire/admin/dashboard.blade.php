@use('App\Domain\Crm\Enums\BusinessLine')
{{--
    The dashboard (master list T-044; the owner's document 05 §7). The page is sections of widgets. What a
    widget shows is its own view (resources/views/admin/dashboard/widgets/), inside the one frame
    (<x-admin.widget>); which widgets a person has, and in which state, App\Domain\System\Dashboard\Board
    decides. This view only places them: three columns on a wide screen, one below.
--}}
@php
    // Written out, so that the stylesheet is built with them: xl:col-span-1 xl:col-span-2 xl:col-span-3
    $spans = [1 => 'xl:col-span-1', 2 => 'xl:col-span-2', 3 => 'xl:col-span-3'];
    $several = BusinessLine::severalOffered();
@endphp
<div wire:poll.60s.visible>
    <x-admin.page-header :title="$greeting"
                         description="Your control centre — selected-period performance above, live operating queues below. Updates every minute.">
        <x-slot:actions>
            @can('reports.view')<x-admin.button variant="secondary" icon="chart" :href="route('admin.reports.index', ['tab' => 'funnel', 'preset' => 'today'])" wire:navigate>Today's reports</x-admin.button>@endcan
            @can('leads.create')<x-admin.button icon="plus" :href="route('admin.leads.index', ['new' => 1])" wire:navigate>New lead</x-admin.button>@endcan
        </x-slot:actions>
    </x-admin.page-header>

    @if ($sections === [])
        {{-- Nothing on the dashboard is this person's: one box says so, instead of an empty page. --}}
        <x-admin.widget :widget="new \App\Domain\System\Dashboard\DrawnWidget('dashboard', 'Your dashboard', '', \App\Domain\System\Dashboard\WidgetState::NoAccess, '', 'full', false)">
            <x-slot:forbidden>
                <p class="text-theme-sm font-semibold text-gray-900 dark:text-white/90">None of the dashboard's figures are open to your role</p>
                <p class="mt-1 max-w-xl text-theme-sm text-gray-600 dark:text-gray-300">
                    This account has the dashboard but not the leads, quotations, invoices, projects or automation screens behind
                    its numbers, so the control tiles are hidden rather than shown as dead ends. Your own screens are in the menu;
                    ask an owner for the role that matches your work.
                </p>
            </x-slot:forbidden>
        </x-admin.widget>
    @endif

    @foreach ($sections as $section => $widgets)
        @if ($section === 'period')
            {{-- The period and the business line that the widgets of this section follow. --}}
            <section class="wx-card mb-4 flex flex-wrap items-end justify-between gap-4 rounded-2xl border border-gray-200 bg-white px-5 py-4 dark:border-gray-800 dark:bg-gray-900" aria-label="Period">
                <div>
                    <p class="text-theme-xs font-semibold uppercase tracking-widest text-brand-600 dark:text-brand-400">Period</p>
                    <h2 class="mt-1 text-theme-xl font-bold">{{ $range->label() }}</h2>
                    <p class="text-theme-xs text-gray-500" data-period-dates>{{ $range->from->format('d M Y') }} – {{ $range->to->format('d M Y') }} · {{ config('app.timezone') }} · refreshed {{ now()->format('H:i') }}</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <label class="text-theme-xs text-gray-500">Period<select wire:model.live="preset" aria-label="Performance period" class="wx-input mt-1"><option value="today">Today</option><option value="this_month">This month</option><option value="last_month">Last month</option><option value="last_90_days">Last 90 days</option><option value="this_quarter">This quarter</option><option value="this_year">This year</option>@if ($preset === 'custom')<option value="custom">{{ $range->label() }}</option>@endif</select></label>
                    @if ($several)
                        <label class="text-theme-xs text-gray-500">Business line<select wire:model.live="line" aria-label="Performance business line" class="wx-input mt-1"><option value="">All business lines</option>@foreach (BusinessLine::offered() as $businessLine)<option value="{{ $businessLine->value }}">{{ $businessLine->label() }}</option>@endforeach</select></label>
                    @endif
                </div>
            </section>
        @elseif ($section === 'now')
            <p class="mb-3 flex flex-wrap items-center justify-between gap-2 text-theme-xs text-gray-500">
                <span>From here down: where things stand now. Not cut to the period{{ $several ? ' or the business line' : '' }} above.</span>
                <a href="{{ route('admin.dashboard', ['tab' => 'operations']) }}" wire:navigate class="text-theme-sm text-brand-600 dark:text-brand-400">All operations →</a>
            </p>
        @endif

        <div class="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3" data-dashboard-section="{{ $section }}">
            @foreach ($widgets as $placed)
                @php($widget = $placed['widget'])
                @if ($widget->state->drawsContent())
                    @include($widget->view, [...$widget->data, 'widget' => $widget, 'span' => $spans[$placed['columns']]])
                @else
                    <x-admin.widget :widget="$widget" :class="$spans[$placed['columns']]" />
                @endif
            @endforeach
        </div>
    @endforeach
</div>
