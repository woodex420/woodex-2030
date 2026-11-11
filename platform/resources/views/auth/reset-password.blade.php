@extends('layouts.auth')

@section('title', 'Choose a new password')
@section('heading', 'Choose a new password')
@section('subheading', 'At least 10 characters with letters and numbers.')

@section('content')
    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <div>
            <label for="email" class="wx-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" required class="wx-input h-11">
        </div>
        <div>
            <label for="password" class="wx-label">New password</label>
            <input id="password" type="password" name="password" required autocomplete="new-password" class="wx-input h-11">
        </div>
        <div>
            <label for="password_confirmation" class="wx-label">Confirm new password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" class="wx-input h-11">
        </div>
        <button type="submit" class="flex h-11 w-full items-center justify-center rounded-lg bg-navy-900 text-theme-sm font-medium text-white hover:bg-navy-800">Save password</button>
    </form>
@endsection
