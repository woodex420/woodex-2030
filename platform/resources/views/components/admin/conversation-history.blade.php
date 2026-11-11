@props(['hasEarlier' => false, 'olderWindow' => false, 'historyLimit' => 60])
@if ($hasEarlier || $olderWindow)
    <div class="mb-4 space-y-2 text-center" aria-label="Conversation history navigation">
        @if ($olderWindow)
            <p class="text-theme-xs text-gray-500">Earlier history window. New messages are available in the latest window.</p>
            <button type="button" wire:click="newerHistory" wire:loading.attr="disabled" class="wx-history-button">Newer history page</button>
            <button type="button" wire:click="latestHistory" wire:loading.attr="disabled" class="wx-history-button">Back to latest messages</button>
        @endif
        @if ($hasEarlier)
            <button type="button" wire:click="loadEarlier" x-on:click="pinned = false" wire:loading.attr="disabled" class="wx-history-button">{{ $historyLimit < 600 ? 'Load earlier messages' : 'Earlier history page' }}</button>
        @endif
    </div>
@endif
