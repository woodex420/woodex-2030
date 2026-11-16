<div x-data class="wx-toast-stack pointer-events-none fixed z-[99999] flex flex-col gap-2" aria-live="polite">
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div x-transition.opacity
             class="pointer-events-auto flex items-start gap-3 rounded-xl border bg-white px-4 py-3 text-theme-sm shadow-theme-lg dark:bg-gray-900"
             :class="{
                'border-success-500/30 text-success-700': toast.type === 'success',
                'border-error-500/30 text-error-700': toast.type === 'error',
                'border-warning-500/30 text-warning-700': toast.type === 'warning',
                'border-gray-200 text-gray-700 dark:border-gray-800 dark:text-gray-300': !['success','error','warning'].includes(toast.type),
             }">
            <span class="flex-1" x-text="toast.message"></span>
            <button type="button" x-show="toast.action" x-text="toast.action?.label ?? 'Open'"
                    @click="toast.action?.event && window.dispatchEvent(new CustomEvent(toast.action.event, { detail: toast.action.detail ?? {} })); $store.toasts.remove(toast.id)"
                    class="shrink-0 rounded-md px-2 py-0.5 text-theme-xs font-medium text-navy-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5"></button>
            <button type="button" class="text-gray-400 hover:text-gray-600" @click="$store.toasts.remove(toast.id)" aria-label="Dismiss">
                <x-icon name="x" class="size-4" />
            </button>
        </div>
    </template>
</div>
