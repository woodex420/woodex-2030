@if ($paginator->hasPages())
    <nav class="flex items-center justify-between gap-3 border-t border-gray-100 px-5 py-3 dark:border-gray-800" aria-label="Pagination">
        <p class="text-theme-xs text-gray-500">
            Showing <span class="font-medium text-gray-700 dark:text-gray-300">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</span>
            of <span class="font-medium text-gray-700 dark:text-gray-300">{{ $paginator->total() }}</span>
        </p>
        <div class="flex items-center gap-2">
            <button type="button" wire:click="previousPage" wire:loading.attr="disabled" @disabled($paginator->onFirstPage())
                    class="h-8 rounded-lg border border-gray-300 px-3 text-theme-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                Previous
            </button>
            <span class="text-theme-xs text-gray-500">Page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            <button type="button" wire:click="nextPage" wire:loading.attr="disabled" @disabled(! $paginator->hasMorePages())
                    class="h-8 rounded-lg border border-gray-300 px-3 text-theme-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                Next
            </button>
        </div>
    </nav>
@endif
