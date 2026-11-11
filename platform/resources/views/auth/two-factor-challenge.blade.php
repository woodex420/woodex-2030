@extends('layouts.auth')

@section('title', 'Two-factor authentication')
@section('heading', 'Enter your code')
@section('subheading', 'Open your authenticator app and enter the 6-digit code.')

@section('content')
    <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-5">
        @csrf
        <div>
            <label for="code" class="wx-label">Authentication code</label>
            <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus class="wx-input h-11 tracking-[0.3em]">
        </div>
        <details class="rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-800">
            <summary class="cursor-pointer text-theme-sm text-navy-600 dark:text-gray-400">Lost your phone? Use a recovery code</summary>
            <div class="mt-3">
                <label for="recovery_code" class="wx-label">Recovery code</label>
                <input id="recovery_code" type="text" name="recovery_code" autocomplete="off" class="wx-input h-11">
            </div>
        </details>
        <button type="submit" class="flex h-11 w-full items-center justify-center rounded-lg bg-navy-900 text-theme-sm font-medium text-white hover:bg-navy-800">Verify</button>
    </form>
@endsection
