@extends('layouts.setup')

@section('title', 'Install')
@section('step', 'Step 2 of 2 · Database')
@section('heading', $error ? 'The installation stopped' : 'Creating the database tables')
@section('subheading', $error ? 'Nothing is lost: fix the problem below and try again.' : 'Settings are saved. Now the tables, the reference data (roles, pipeline, rate book, templates) and the owner account are created.')

@section('content')
    @if ($error)
        <div class="rounded-xl border border-error-500/30 bg-error-50 px-4 py-3 text-theme-sm text-error-700" role="alert">{{ $error }}</div>
        <p class="mt-3 text-theme-xs text-gray-500">
            Wrong database details? In File Manager delete the <code>.env</code> file next to <code>{{ $codeFile }}</code>, then open
            <code>/install</code> again to start over.
        </p>
    @endif

    <form id="install-finish" method="POST" action="{{ url('/install/finish') }}" class="mt-6 space-y-4 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        @if ($code !== '')
            <input type="hidden" name="setup_code" value="{{ $code }}">
        @else
            <div>
                <label for="setup_code" class="wx-label">Setup code</label>
                <input id="setup_code" name="setup_code" required maxlength="64" spellcheck="false" class="wx-input h-11 font-mono uppercase tracking-wider">
                <p class="mt-1.5 text-theme-xs text-gray-500">From <code>{{ $siteCodeFile ?? $codeFile }}</code> (hPanel → File Manager{{ $siteCodeFile ? '' : ' → Access all files of hosting' }}).</p>
            </div>
        @endif
        <button type="submit" data-busy-text="Installing… (up to a minute)" class="flex h-12 w-full items-center justify-center rounded-lg bg-navy-900 text-theme-sm font-medium text-white hover:bg-navy-800">
            {{ $error ? 'Try again' : 'Finish installation' }}
        </button>
    </form>

    @if ($auto)
        <script>
            // Step 2 needs a fresh request that starts with the new settings: continue automatically.
            window.addEventListener('load', function () {
                var form = document.getElementById('install-finish');
                setTimeout(function () { form.requestSubmit ? form.requestSubmit() : form.submit(); }, 400);
            });
        </script>
    @endif
@endsection
