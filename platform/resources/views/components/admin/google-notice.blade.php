@if (session('google_status'))
    <div role="status" class="mb-5 rounded-xl border border-gray-200 bg-gray-50 p-4 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ session('google_status') }}</div>
@endif
