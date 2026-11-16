@extends('layouts.auth')

@section('title', 'Reset password')
@section('heading', 'Forgot your password?')
@section('subheading', 'Enter your staff email and we will send a reset link.')

@section('content')
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf
        <div>
            <label for="email" class="wx-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus class="wx-input h-11">
        </div>
        <button type="submit" class="flex h-11 w-full items-center justify-center rounded-lg bg-navy-900 text-theme-sm font-medium text-white hover:bg-navy-800">Email reset link</button>
        <p class="text-center text-theme-sm"><a href="{{ route('login') }}" class="text-navy-600 hover:text-navy-800 dark:text-gray-400">Back to sign in</a></p>
    </form>
@endsection
