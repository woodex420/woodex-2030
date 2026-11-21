@use('App\Domain\Crm\Enums\StageType')
{{-- The pipeline: leads per stage as they stand now; each count is the door to the leads of that stage. --}}
<x-admin.widget :widget="$widget" :class="$span">
    <ul class="space-y-3">
        @foreach ($stages as $stage)
            @php($figure = $stage['figure'])
            <li>
                <div class="mb-1 flex justify-between gap-3 text-theme-sm">
                    <span class="text-gray-600 dark:text-gray-300">{{ $figure->label }}</span>
                    <x-admin.figure :figure="$figure" class="tabular-nums" aria-label="{{ $figure->label }}: open the {{ $figure->counted() }}">{{ number_format($figure->count) }}</x-admin.figure>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5" aria-hidden="true">
                    <div @class([
                        'h-full rounded-full',
                        'bg-success-500' => $stage['type'] === StageType::Won->value,
                        'bg-error-500' => $stage['type'] === StageType::Lost->value,
                        'bg-navy-600' => $stage['type'] === StageType::Open->value,
                    ]) style="width: {{ round($figure->count / $most * 100) }}%"></div>
                </div>
            </li>
        @endforeach
    </ul>

    <x-slot:definition>
        As it stands now, over all dates · {{ $own ? 'your own leads' : 'everybody\'s leads' }} · a count of leads, no money.
        Each lead is counted once, in the stage it stands in.
    </x-slot:definition>
</x-admin.widget>
