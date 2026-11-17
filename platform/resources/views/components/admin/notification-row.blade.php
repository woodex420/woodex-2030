@props(['notification'])
@php($archived = !empty($notification->data['woodex_archived_at']))
<article wire:key="notification-{{ $notification->id }}" @class(['wx-alert-row group flex gap-3 border-b border-gray-100 p-4 last:border-0 dark:border-gray-800', 'bg-brand-50/50 dark:bg-brand-900/20' => $notification->read_at === null])>
    <span class="wx-avatar relative mt-0.5" aria-hidden="true">
        <x-icon name="bell" class="size-4" />
        @if ($notification->read_at === null)<span class="absolute -right-0.5 -bottom-0.5 size-2.5 rounded-full border-2 border-white bg-brand-500 dark:border-gray-900"></span>@endif
    </span>
    <div class="min-w-0 flex-1">
        <time class="text-[11px] text-gray-400" datetime="{{ $notification->created_at?->toIso8601String() }}" title="{{ $notification->created_at?->format('j M Y, g:i a') }}">{{ $notification->created_at?->diffForHumans() }}</time>
        <button type="button" wire:click="open('{{ $notification->id }}')" wire:loading.attr="disabled" class="mt-1 block w-full text-left">
            <span @class(['block text-theme-sm text-gray-900 dark:text-white', 'font-semibold' => $notification->read_at === null, 'font-medium' => $notification->read_at !== null])>{{ $notification->data['title'] ?? 'Update' }}</span>
            <span class="mt-1 block text-theme-sm leading-relaxed break-words text-gray-500 dark:text-gray-400">{{ $notification->data['body'] ?? '' }}</span>
        </button>
        <button type="button" wire:click="{{ $archived ? 'restore' : 'archive' }}('{{ $notification->id }}')" wire:loading.attr="disabled" class="mt-2 text-theme-xs font-medium text-brand-600 hover:underline dark:text-gray-300">{{ $archived ? 'Restore to inbox' : 'Archive' }}</button>
    </div>
</article>
