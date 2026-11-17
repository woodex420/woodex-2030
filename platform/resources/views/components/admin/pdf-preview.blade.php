<div x-data="documentPreview"
     x-on:document-preview.window="show($event.detail)"
     x-on:keydown.escape.window="close()">
    <template x-if="open">
        <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-gray-900/60 p-2 sm:p-5" role="dialog" aria-modal="true" aria-label="Document preview" x-trap.inert.noscroll="open" x-on:click.self="close()">
            <div class="flex h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-xl bg-white shadow-xl dark:bg-gray-900">
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 p-3 dark:border-gray-800">
                    <h2 class="truncate font-medium text-gray-900 dark:text-white" x-text="label"></h2>
                    <div class="flex shrink-0 items-center gap-3"><a x-show="url" :href="downloadUrl || url" :download="downloadName" class="text-theme-sm text-brand-600">Download PDF</a><button type="button" x-on:click="close()" class="rounded-lg px-3 py-2 text-theme-sm text-gray-700 dark:text-gray-200" aria-label="Close document preview">Close</button></div>
                </div>
                <p class="px-3 py-2 text-theme-xs text-gray-500">Actual PDF · previewing does not send a document. <span role="status" aria-live="polite" x-text="status"></span></p>
                <div x-show="error" role="alert" class="mx-3 mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"><p x-text="error"></p><button type="button" x-on:click="show({url, label})" class="mt-2 rounded border border-red-300 px-3 py-1 font-medium">Retry preview</button></div>
                <div x-ref="pages" class="min-h-0 flex-1 overflow-auto bg-gray-100 p-2 sm:p-4" :aria-busy="loading" aria-label="PDF pages"></div>
            </div>
        </div>
    </template>
</div>
