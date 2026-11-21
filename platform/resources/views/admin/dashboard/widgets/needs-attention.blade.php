{{-- What is waiting: each line with the button that starts the work, and its count as the door to its rows. --}}
<x-admin.widget :widget="$widget" :class="$span" flush>
    <x-slot:empty>
        <p class="text-[15px] leading-6 font-semibold text-gray-900 dark:text-white/90">Nothing is waiting</p>
        <p class="mt-1 max-w-md text-theme-sm text-gray-500 dark:text-gray-400">{{ $widget->emptyWords }}</p>
    </x-slot:empty>

    @foreach ($items as $item)
        @php
            $figure = $item['figure'];
            // Where the work is done in the list the line counts, the button is the door to its rows. Late money
            // is chased from the receivables book and counted in invoices: there the count is a link of its own.
            $buttonIsDoor = $figure->opens() && $figure->href === $item['href'];
        @endphp
        <div wire:key="attention-{{ $item['key'] }}" class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-5 py-3.5 last:border-0 dark:border-gray-800">
            <span @class([
                'flex size-9 shrink-0 items-center justify-center rounded-xl',
                'bg-error-50 text-error-600 dark:bg-error-500/10 dark:text-error-500' => $item['tone'] === 'error',
                'bg-warning-50 text-warning-600 dark:bg-warning-500/10 dark:text-warning-500' => $item['tone'] === 'warning',
                'bg-navy-50 text-navy-700 dark:bg-white/5 dark:text-navy-200' => ! in_array($item['tone'], ['error', 'warning'], true),
            ])>
                <x-icon :name="$item['icon']" class="size-4.5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="font-medium text-gray-800 dark:text-white/90">{{ $item['title'] }}</p>
                <p class="text-theme-xs text-gray-500">{{ $item['detail'] }}
                    @unless ($buttonIsDoor)
                        <x-admin.figure :figure="$figure" class="whitespace-nowrap">{{ $figure->opens() ? 'Open the '.$figure->counted() : $figure->counted() }}</x-admin.figure>
                    @endunless
                </p>
            </div>
            @if ($buttonIsDoor)
                <x-admin.button size="sm" :variant="$item['tone'] === 'error' ? 'primary' : 'secondary'" :href="$item['href']" wire:navigate
                                data-figure="{{ $figure->key }}" data-figure-count="{{ $figure->count }}" data-figure-rows>{{ $item['action'] }}</x-admin.button>
            @else
                <x-admin.button size="sm" :variant="$item['tone'] === 'error' ? 'primary' : 'secondary'" :href="$item['href']" wire:navigate>{{ $item['action'] }}</x-admin.button>
            @endif
        </div>
    @endforeach

    @if ($first !== [])
        <div class="border-t border-gray-100 px-5 py-3 dark:border-gray-800">
            <p class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase">Overdue follow-ups — the five to touch first</p>
        </div>
        @foreach ($first as $lead)
            {{-- The name keeps its width: on a narrow screen the badge and the two buttons go under it, together. --}}
            <div wire:key="overdue-{{ $lead['id'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-gray-100 px-5 py-3 last:border-0 dark:border-gray-800">
                <div class="min-w-40 flex-1">
                    <a href="{{ $lead['href'] }}" wire:navigate class="block truncate font-medium text-gray-800 hover:underline dark:text-white/90">{{ $lead['name'] }}
                        <span class="ml-1 text-theme-xs font-normal text-gray-400">{{ $lead['ref'] }}</span></a>
                    <p class="text-theme-xs text-gray-500">{{ $lead['about'] }}</p>
                </div>
                <div class="flex items-center gap-3">
                    @if ($lead['breached'])
                        <x-admin.badge color="error">SLA breached</x-admin.badge>
                    @else
                        <x-admin.badge color="warning">Due {{ $lead['due'] }}</x-admin.badge>
                    @endif
                    @if ($lead['whatsapp'])
                        <a href="{{ $lead['whatsapp'] }}" target="_blank" rel="noopener" class="flex size-8 items-center justify-center rounded-lg border border-gray-200 text-success-600 hover:bg-success-50 dark:border-gray-800" title="WhatsApp" aria-label="WhatsApp {{ $lead['name'] }}">
                            <x-icon name="whatsapp" class="size-4" />
                        </a>
                    @endif
                    @if ($lead['phone'])
                        <a href="tel:{{ $lead['phone'] }}" class="flex size-8 items-center justify-center rounded-lg border border-gray-200 text-navy-700 hover:bg-navy-50 dark:border-gray-800 dark:text-gray-300" title="Call" aria-label="Call {{ $lead['name'] }}">
                            <x-icon name="phone" class="size-4" />
                        </a>
                    @endif
                </div>
            </div>
        @endforeach
    @endif
</x-admin.widget>
