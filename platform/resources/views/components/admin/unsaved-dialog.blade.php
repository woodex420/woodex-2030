{{--
    The question before text that is not saved is left behind (master list T-043).
    resources/js/admin/unsaved.js opens it; nothing else does.

    A native dialog: the page behind cannot be used while it is open, Tab stays inside it, and the focus goes back
    to where it was when it closes. "Stay" has the focus, so Enter keeps the screen. Escape does the same.
--}}
<dialog data-unsaved-dialog aria-labelledby="unsaved-title" aria-describedby="unsaved-text"
        class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl bg-white p-6 text-left shadow-theme-lg backdrop:bg-gray-900/50 dark:bg-gray-900">
    <h2 id="unsaved-title" class="text-theme-xl font-medium text-gray-900 dark:text-white">Leave without saving?</h2>
    <p id="unsaved-text" class="mt-2 text-theme-sm text-gray-600 dark:text-gray-400">What you entered on this screen is not saved yet. If you leave now, it is lost.</p>
    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <x-admin.button variant="secondary" data-unsaved-leave>Leave without saving</x-admin.button>
        <x-admin.button data-unsaved-stay>Stay</x-admin.button>
    </div>
</dialog>
