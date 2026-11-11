@extends('layouts.auth')

@section('title', 'Confirm password')
@section('heading', 'Confirm your password')
@section('subheading', 'This is a secure area. Please confirm your password to continue.')

@section('content')
    <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-5">
        @csrf
        <div>
            <label for="password" class="wx-label">Password</label>
            <input id="password" type="password" name="password" required autofocus autocomplete="current-password" class="wx-input h-11">
        </div>
        <button type="submit" class="flex h-11 w-full items-center justify-center rounded-lg bg-navy-900 text-theme-sm font-medium text-white hover:bg-navy-800">Confirm</button>
    </form>
@endsection
