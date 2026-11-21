{{-- The go-live checklist: each line done or not, with where to do it. No figure. --}}
<x-admin.widget :widget="$widget" :class="$span">
    <ul class="space-y-3">
        @foreach ($checklist as $item)
            <li class="flex items-start gap-3">
                <span @class([
                    'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full',
                    'bg-success-500 text-white' => $item['done'],
                    'border border-gray-300 text-transparent dark:border-gray-700' => ! $item['done'],
                ])><x-icon name="check-circle" class="size-3.5" /></span>
                <span>
                    <span class="block text-theme-sm text-gray-700 dark:text-gray-300">{{ $item['label'] }}<span class="sr-only">: {{ $item['done'] ? 'done' : 'not done' }}</span></span>
                    @unless ($item['done'])
                        <span class="block text-theme-xs text-gray-400">{{ $item['hint'] }}</span>
                    @endunless
                </span>
            </li>
        @endforeach
    </ul>
</x-admin.widget>
