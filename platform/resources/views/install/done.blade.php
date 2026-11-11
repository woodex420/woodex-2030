@extends('layouts.setup')

@section('title', 'Installed')
@section('step', 'Done')
@section('heading', 'Woodex OS is installed')
@section('subheading', 'The installer is now closed: its setup code was deleted and /install answers "not found".')

@section('content')
    <div class="rounded-2xl border border-success-500/30 bg-success-50 p-5 text-theme-sm text-success-700">
        @if ($owner)
            Owner account <strong>{{ $owner }}</strong> is ready.
        @else
            This database already had an owner account; it was kept.
        @endif
    </div>

    <a href="{{ route('login') }}" class="mt-6 flex h-12 w-full items-center justify-center rounded-lg bg-navy-900 text-theme-sm font-medium text-white hover:bg-navy-800">
        Sign in to the admin
    </a>

    <section class="mt-8 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 class="text-theme-sm font-semibold text-gray-900 dark:text-white">Next steps</h2>
        <ol class="mt-3 list-decimal space-y-3 pl-5 text-theme-sm text-gray-700 dark:text-gray-300">
            <li>
                <strong>Cron job</strong> (reminders, queue, alerts): hPanel → Advanced → Cron Jobs → Custom, every minute:
                <code class="mt-1 block overflow-x-auto rounded-lg bg-gray-100 px-3 py-2 text-[12px] text-gray-800 dark:bg-gray-800 dark:text-gray-200">{{ $cron }}</code>
            </li>
            <li>
                <strong>Settings file:</strong> <code>{{ $envFile }}</code>. Edit it in File Manager later (email SMTP, Telegram).
                @if ($classic)
                    <span class="mt-1 block text-warning-700">It is inside the site folder because no home folder was found: back it up, a deploy may replace it (docs/06 §2).</span>
                @endif
            </li>
            <li><strong>After each deploy</strong>, sign in: when the database needs an update the admin asks you to apply it with one click.</li>
            <li>Emails are written to the log until the mailbox is ready (P-02).</li>
        </ol>
    </section>

    <details class="mt-6 text-theme-xs text-gray-500">
        <summary class="cursor-pointer">Installation log</summary>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-900 p-3 text-[11px] leading-relaxed text-gray-100">{{ $log }}</pre>
    </details>
@endsection
