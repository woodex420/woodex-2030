@extends('layouts.setup')

@section('title', 'Database update')
@section('step', 'System')
@section('heading', $pending === [] ? 'The database is up to date' : 'A database update is needed')
@section('subheading', $pending === []
    ? 'Nothing to do. New code that changes the database brings you back here after a deploy.'
    : 'New code was deployed and it needs '.count($pending).' database change'.(count($pending) === 1 ? '' : 's').'. Staff see a short "updating" notice until you apply it.')

@section('content')
    @if (session('update_error'))
        <div class="mb-6 rounded-xl border border-error-500/30 bg-error-50 px-4 py-3 text-theme-sm text-error-700" role="alert">
            The update stopped: {{ session('update_error') }}
        </div>
    @endif

    @if (session('update_log'))
        <div class="mb-6 rounded-xl border border-success-500/30 bg-success-50 px-4 py-3 text-theme-sm text-success-700">Update applied.</div>
        <pre class="mb-6 overflow-x-auto rounded-lg bg-gray-900 p-3 text-[11px] leading-relaxed text-gray-100">{{ session('update_log') }}</pre>
    @endif

    @if ($pending !== [])
        <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-theme-sm font-semibold text-gray-900 dark:text-white">Changes waiting</h2>
            <ul class="mt-3 space-y-1 font-mono text-[12px] text-gray-700 dark:text-gray-300">
                @foreach ($pending as $migration)
                    <li>{{ $migration }}</li>
                @endforeach
            </ul>
            <p class="mt-4 text-theme-xs text-gray-500">Tip: hPanel makes daily backups; for large updates make a manual database backup first (hPanel → Databases → phpMyAdmin → Export).</p>
        </section>

        <form method="POST" action="{{ route('admin.system.updates.apply') }}" class="mt-6">
            @csrf
            <button type="submit" data-busy-text="Updating…" class="flex h-12 w-full items-center justify-center rounded-lg bg-navy-900 text-theme-sm font-medium text-white hover:bg-navy-800">
                Apply update
            </button>
        </form>
    @else
        <a href="{{ route('admin.dashboard') }}" class="flex h-12 w-full items-center justify-center rounded-lg bg-navy-900 text-theme-sm font-medium text-white hover:bg-navy-800">Open the dashboard</a>
    @endif
@endsection
